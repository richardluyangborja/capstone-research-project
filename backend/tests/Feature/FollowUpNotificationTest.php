<?php

namespace Tests\Feature;

use App\Enums\ActivityStatus;
use App\Enums\CommunicationStatus;
use App\Models\Activity;
use App\Models\Communication;
use App\Models\Company;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

/**
 * The due run and its notifications (module 04 slice S3, spec 12 §10): who gets told when work
 * is handed over, when it comes due, and when a send that nobody watched fails.
 *
 * Nothing runs on a timer in this phase — the run is an admin action (`POST
 * /follow-ups/dispatch-due`) or its artisan twin (`follow-ups:notify-due`) — so `reminded_at`
 * is what keeps a repeated run quiet.
 */
class FollowUpNotificationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 10:00 on a Monday in Manila, so "yesterday" and "tomorrow" are unambiguous whatever the
     * runner's own timezone is.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-10-19 02:00:00', 'UTC'));
    }

    private function companyFor(User $owner): Company
    {
        return Company::factory()->ownedBy($owner)->create();
    }

    /**
     * A task on a record, written the way the factory writes one.
     */
    private function task(User $owner, Company $company, array $attributes = []): Activity
    {
        return Activity::factory()->task()->create([
            'owner_id' => $owner->id,
            'subject_type' => Company::class,
            'subject_id' => $company->id,
        ] + $attributes);
    }

    public function test_handing_work_to_another_person_tells_them(): void
    {
        $team = Team::factory()->create();
        $manager = User::factory()->manager()->create(['team_id' => $team->id]);
        $rep = User::factory()->salesrep()->create(['team_id' => $team->id]);
        $company = $this->companyFor($rep);

        $response = $this->actingAs($manager, 'api')
            ->postJson('/api/v1/tasks', [
                'subject_type' => 'company',
                'subject_id' => $company->id,
                'subject' => 'Confirm the 40-seat start date',
                'due_at' => Carbon::now()->addDay()->toIso8601String(),
                'owner_id' => $rep->id,
            ])
            ->assertCreated();

        $taskId = $response->json('data.id');

        $this->assertDatabaseCount('notifications', 1);

        $notification = $rep->notifications()->firstOrFail();

        $this->assertSame('follow_up_assigned', $notification->data['type']);
        $this->assertSame('/follow-ups/'.$taskId, $notification->data['url']);
        $this->assertSame($taskId, $notification->data['follow_up_id']);
        $this->assertStringContainsString($manager->name.' asked you to Confirm the 40-seat start date', $notification->data['message']);
        $this->assertStringContainsString($company->name, $notification->data['message']);
    }

    public function test_work_you_put_on_yourself_tells_nobody(): void
    {
        $rep = User::factory()->salesrep()->create();
        $company = $this->companyFor($rep);

        $this->actingAs($rep, 'api')
            ->postJson('/api/v1/tasks', [
                'subject_type' => 'company',
                'subject_id' => $company->id,
                'subject' => 'Send the revised rate sheet',
                'due_at' => Carbon::now()->addDay()->toIso8601String(),
            ])
            ->assertCreated();

        $this->assertDatabaseCount('notifications', 0);
    }

    public function test_reassigning_a_task_tells_the_new_owner(): void
    {
        $team = Team::factory()->create();
        $manager = User::factory()->manager()->create(['team_id' => $team->id]);
        $rep = User::factory()->salesrep()->create(['team_id' => $team->id]);
        $other = User::factory()->salesrep()->create(['team_id' => $team->id]);
        $company = $this->companyFor($rep);

        $task = $this->task($rep, $company);

        $this->actingAs($manager, 'api')
            ->patchJson("/api/v1/tasks/{$task->id}", ['owner_id' => $other->id])
            ->assertOk()
            ->assertJsonPath('data.owner.id', $other->id);

        $this->assertDatabaseCount('notifications', 1);
        $this->assertSame('follow_up_assigned', $other->notifications()->firstOrFail()->data['type']);
        $this->assertSame($other->id, $task->fresh()->owner_id);
    }

    /**
     * Editing the wording is not a reassignment, even though both go through `PATCH /tasks/{id}`.
     */
    public function test_editing_a_tasks_wording_tells_nobody(): void
    {
        $rep = User::factory()->salesrep()->create();
        $company = $this->companyFor($rep);
        $task = $this->task($rep, $company);

        $this->actingAs($rep, 'api')
            ->patchJson("/api/v1/tasks/{$task->id}", ['subject' => 'Send the revised rate sheet (v2)'])
            ->assertOk();

        $this->assertDatabaseCount('notifications', 0);
    }

    public function test_the_due_run_flags_each_open_task_once_and_only_when_it_is_due(): void
    {
        $admin = User::factory()->admin()->create();
        $rep = User::factory()->salesrep()->create();
        $company = $this->companyFor($rep);

        $overdue = $this->task($rep, $company, [
            'subject' => 'Send the revised rate sheet',
            'due_at' => Carbon::now()->subDays(2),
        ]);

        // Still owed later, already settled, and dropped: none of them is flagged.
        $this->task($rep, $company, [
            'subject' => 'Chase the signed contract',
            'due_at' => Carbon::now()->addDays(2),
        ]);
        $this->task($rep, $company, [
            'subject' => 'Prepare the crew schedule',
            'due_at' => Carbon::now()->subDays(4),
            'status' => ActivityStatus::Done,
        ]);
        $this->task($rep, $company, [
            'subject' => 'Book a site visit',
            'due_at' => Carbon::now()->subDays(6),
            'status' => ActivityStatus::Cancelled,
        ]);

        $this->actingAs($admin, 'api')
            ->postJson('/api/v1/follow-ups/dispatch-due')
            ->assertOk()
            ->assertJsonPath('data.notified', 1);

        $this->assertDatabaseCount('notifications', 1);

        $notification = $rep->notifications()->firstOrFail();

        $this->assertSame('follow_up_due', $notification->data['type']);
        $this->assertTrue($notification->data['overdue']);
        $this->assertSame('/follow-ups/'.$overdue->id, $notification->data['url']);
        $this->assertSame('Overdue: Send the revised rate sheet ('.$company->name.').', $notification->data['message']);

        // Stamped as it was flagged: the second run of the same day finds nothing to say.
        $this->assertNotNull($overdue->fresh()->reminded_at);

        $this->actingAs($admin, 'api')
            ->postJson('/api/v1/follow-ups/dispatch-due')
            ->assertOk()
            ->assertJsonPath('data.notified', 0);

        $this->assertDatabaseCount('notifications', 1);
    }

    public function test_rescheduling_a_task_re_arms_its_reminder(): void
    {
        $admin = User::factory()->admin()->create();
        $rep = User::factory()->salesrep()->create();
        $company = $this->companyFor($rep);

        $task = $this->task($rep, $company, ['due_at' => Carbon::now()->subDays(2)]);

        $this->actingAs($admin, 'api')
            ->postJson('/api/v1/follow-ups/dispatch-due')
            ->assertJsonPath('data.notified', 1);

        $this->assertNotNull($task->fresh()->reminded_at);

        // Pushed out to next week: the reminder is re-armed, so the run flags it when it lands.
        $this->actingAs($rep, 'api')
            ->patchJson("/api/v1/tasks/{$task->id}", ['due_at' => Carbon::now()->addWeek()->toIso8601String()])
            ->assertOk();

        $this->assertNull($task->fresh()->reminded_at);

        $this->actingAs($admin, 'api')
            ->postJson('/api/v1/follow-ups/dispatch-due')
            ->assertJsonPath('data.notified', 0);

        // ... and once that moment passes, it is flagged again.
        $this->travel(8)->days();

        $this->actingAs($admin, 'api')
            ->postJson('/api/v1/follow-ups/dispatch-due')
            ->assertJsonPath('data.notified', 1);

        $this->assertSame(2, $rep->notifications()->count());
    }

    public function test_only_an_admin_may_run_the_due_reminders(): void
    {
        $rep = User::factory()->salesrep()->create();
        $manager = User::factory()->manager()->create();

        $this->actingAs($rep, 'api')
            ->postJson('/api/v1/follow-ups/dispatch-due')
            ->assertForbidden();

        $this->actingAs($manager, 'api')
            ->postJson('/api/v1/follow-ups/dispatch-due')
            ->assertForbidden();
    }

    public function test_the_artisan_twin_runs_the_same_work(): void
    {
        $rep = User::factory()->salesrep()->create();
        $company = $this->companyFor($rep);

        $this->task($rep, $company, ['due_at' => Carbon::now()->subHour()]);

        $this->artisan('follow-ups:notify-due')
            ->expectsOutputToContain('1 follow-up flagged')
            ->assertSuccessful();

        $this->assertSame(1, $rep->notifications()->count());

        $this->artisan('follow-ups:notify-due')
            ->expectsOutputToContain('0 follow-ups flagged')
            ->assertSuccessful();

        $this->assertSame(1, $rep->notifications()->count());
    }

    public function test_an_unattended_scheduled_failure_tells_the_author(): void
    {
        Config::set('crm.integrations.email.simulate_failure', true);

        $admin = User::factory()->admin()->create();
        $rep = User::factory()->salesrep()->create();
        $company = $this->companyFor($rep);

        $message = Communication::factory()
            ->about($company, $rep)
            ->scheduled(Carbon::now()->subHour())
            ->create();

        $this->actingAs($admin, 'api')
            ->postJson('/api/v1/communications/dispatch-due')
            ->assertOk()
            ->assertJsonPath('data.failed', 1);

        $this->assertSame(CommunicationStatus::Failed, $message->fresh()->status);
        $this->assertDatabaseCount('notifications', 1);

        $notification = $rep->notifications()->firstOrFail();

        $this->assertSame('scheduled_send_failed', $notification->data['type']);
        $this->assertSame('/communications/'.$message->id, $notification->data['url']);
        $this->assertStringContainsString('Your scheduled email "'.$message->subject.'" could not be sent', $notification->data['message']);
    }

    /**
     * The rep who pressed Retry is already looking at the reason, so that attempt says nothing.
     */
    public function test_a_rep_retrying_their_own_rejected_send_is_not_notified(): void
    {
        Config::set('crm.integrations.email.simulate_failure', true);

        $rep = User::factory()->salesrep()->create();
        $company = $this->companyFor($rep);

        $message = Communication::factory()
            ->about($company, $rep)
            ->scheduled(Carbon::now()->subHour())
            ->create();

        $this->actingAs($rep, 'api')
            ->postJson('/api/v1/communications/'.$message->id.'/send')
            ->assertOk()
            ->assertJsonPath('data.status', 'failed');

        $this->assertDatabaseCount('notifications', 0);
    }

    public function test_the_bell_carries_the_item_each_notification_is_about(): void
    {
        $rep = User::factory()->salesrep()->create();
        $company = $this->companyFor($rep);
        $task = $this->task($rep, $company, ['due_at' => Carbon::now()->subHour()]);

        Activity::notifyDue();

        $this->actingAs($rep, 'api')
            ->getJson('/api/v1/notifications')
            ->assertOk()
            ->assertJsonPath('meta.unread', 1)
            ->assertJsonPath('data.0.type', 'follow_up_due')
            ->assertJsonPath('data.0.url', '/follow-ups/'.$task->id);
    }
}
