<?php

namespace Tests\Feature;

use App\Enums\ActivityStatus;
use App\Enums\ActivityType;
use App\Enums\CompanyStatus;
use App\Models\Activity;
use App\Models\Company;
use App\Models\Lead;
use App\Models\Opportunity;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Follow-up tasks (module 04 slice S1, spec 12): creating one on any record, moving it through
 * its states, and the scoping that decides who may owe what.
 */
class TaskTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 10:00 on a Monday in Manila — the clock is pinned so the due states and the exact instants
     * a payload is asserted against cannot drift with the machine running the suite.
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

    public function test_a_task_can_be_created_on_an_account(): void
    {
        $rep = User::factory()->salesrep()->create();
        $company = $this->companyFor($rep);
        $dueAt = Carbon::now()->addDays(2);

        $response = $this->actingAs($rep, 'api')
            ->postJson('/api/v1/tasks', [
                'subject_type' => 'company',
                'subject_id' => $company->id,
                'subject' => 'Send the revised rate sheet',
                'body' => 'Cover the two supervisor roles we discussed.',
                'due_at' => $dueAt->toIso8601String(),
            ])
            ->assertCreated()
            ->assertJsonPath('data.subject', 'Send the revised rate sheet')
            ->assertJsonPath('data.body', 'Cover the two supervisor roles we discussed.')
            ->assertJsonPath('data.record.type', 'company')
            ->assertJsonPath('data.record.id', $company->id)
            ->assertJsonPath('data.record.label', $company->name)
            ->assertJsonPath('data.owner.id', $rep->id)
            ->assertJsonPath('data.status.value', 'open')
            ->assertJsonPath('data.status.label', 'Open')
            ->assertJsonPath('data.due.state', 'upcoming')
            ->assertJsonPath('data.due.days', 2)
            ->assertJsonPath('data.channel', null)
            ->assertJsonPath('data.can_update', true)
            ->assertJsonPath('data.can_transition', true)
            // A task is not a message: nothing to send and nothing to delete.
            ->assertJsonPath('data.can_send', false)
            ->assertJsonPath('data.can_delete', false);

        $task = Activity::findOrFail($response->json('data.id'));

        $this->assertSame(ActivityType::Task, $task->type);
        $this->assertSame(ActivityStatus::Open, $task->status);
        $this->assertSame($rep->id, $task->owner_id);
        $this->assertSame(Company::class, $task->subject_type);
        $this->assertSame($company->id, $task->subject_id);
        $this->assertSame($dueAt->timestamp, $task->due_at->timestamp);
        // It has not happened, so it is not a record of anything.
        $this->assertNull($task->occurred_at);
    }

    public function test_a_task_can_be_created_on_every_subject_type(): void
    {
        $rep = User::factory()->salesrep()->create();
        $lead = Lead::factory()->create(['owner_id' => $rep->id]);
        $company = $this->companyFor($rep);
        $deal = Opportunity::factory()->forCompany($company)->ownedBy($rep)->create();
        $contact = $company->contacts()->create(['name' => 'Ana Reyes', 'role_type' => 'hr']);

        $subjects = [
            'lead' => $lead,
            'company' => $company,
            'contact' => $contact,
            'opportunity' => $deal,
        ];

        foreach ($subjects as $type => $subject) {
            $this->actingAs($rep, 'api')
                ->postJson('/api/v1/tasks', [
                    'subject_type' => $type,
                    'subject_id' => $subject->id,
                    'subject' => "Follow up on the {$type}",
                    'due_at' => Carbon::now()->addDay()->toIso8601String(),
                ])
                ->assertCreated()
                ->assertJsonPath('data.record.type', $type);
        }

        $this->assertSame(4, Activity::where('type', ActivityType::Task)->count());
    }

    public function test_a_task_needs_a_title_a_record_and_a_due_time(): void
    {
        $rep = User::factory()->salesrep()->create();
        $company = $this->companyFor($rep);

        $this->actingAs($rep, 'api')
            ->postJson('/api/v1/tasks', [
                'subject_type' => 'company',
                'subject_id' => $company->id,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['subject', 'due_at']);

        // A reminder without a time is not a reminder.
        $this->actingAs($rep, 'api')
            ->postJson('/api/v1/tasks', [
                'subject_type' => 'company',
                'subject_id' => $company->id,
                'subject' => 'Call them back',
                'due_at' => 'not a date',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['due_at']);
    }

    public function test_an_unknown_record_or_subject_type_is_rejected(): void
    {
        $rep = User::factory()->salesrep()->create();

        $this->actingAs($rep, 'api')
            ->postJson('/api/v1/tasks', [
                'subject_type' => 'company',
                'subject_id' => '01JZZZZZZZZZZZZZZZZZZZZZZZ',
                'subject' => 'Call them back',
                'due_at' => Carbon::now()->addDay()->toIso8601String(),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['subject_id']);

        $this->actingAs($rep, 'api')
            ->postJson('/api/v1/tasks', [
                'subject_type' => 'invoice',
                'subject_id' => 'anything',
                'subject' => 'Call them back',
                'due_at' => Carbon::now()->addDay()->toIso8601String(),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['subject_type']);
    }

    public function test_a_salesrep_cannot_put_a_task_on_another_reps_record(): void
    {
        $rep = User::factory()->salesrep()->create();
        $other = User::factory()->salesrep()->create();
        $company = $this->companyFor($other);

        $this->actingAs($rep, 'api')
            ->postJson('/api/v1/tasks', [
                'subject_type' => 'company',
                'subject_id' => $company->id,
                'subject' => 'Send the revised rate sheet',
                'due_at' => Carbon::now()->addDay()->toIso8601String(),
            ])
            ->assertStatus(403);

        $this->assertSame(0, Activity::where('type', ActivityType::Task)->count());
    }

    public function test_a_salesrep_may_only_owe_themselves(): void
    {
        $rep = User::factory()->salesrep()->create();
        $other = User::factory()->salesrep()->create();
        $company = $this->companyFor($rep);

        $this->actingAs($rep, 'api')
            ->postJson('/api/v1/tasks', [
                'subject_type' => 'company',
                'subject_id' => $company->id,
                'subject' => 'Send the revised rate sheet',
                'due_at' => Carbon::now()->addDay()->toIso8601String(),
                'owner_id' => $other->id,
            ])
            ->assertStatus(403);
    }

    public function test_a_manager_may_hand_a_task_to_their_team_but_not_outside_it(): void
    {
        $team = Team::factory()->create();
        $manager = User::factory()->manager()->create(['team_id' => $team->id]);
        $rep = User::factory()->salesrep()->create(['team_id' => $team->id]);
        $outsider = User::factory()->salesrep()->create();
        $company = $this->companyFor($rep);

        $this->actingAs($manager, 'api')
            ->postJson('/api/v1/tasks', [
                'subject_type' => 'company',
                'subject_id' => $company->id,
                'subject' => 'Confirm the 40-seat start date',
                'due_at' => Carbon::now()->addDay()->toIso8601String(),
                'owner_id' => $rep->id,
            ])
            ->assertCreated()
            ->assertJsonPath('data.owner.id', $rep->id);

        $this->actingAs($manager, 'api')
            ->postJson('/api/v1/tasks', [
                'subject_type' => 'company',
                'subject_id' => $company->id,
                'subject' => 'Confirm the 40-seat start date',
                'due_at' => Carbon::now()->addDay()->toIso8601String(),
                'owner_id' => $outsider->id,
            ])
            ->assertStatus(403);
    }

    public function test_a_task_follows_its_state_machine(): void
    {
        $rep = User::factory()->salesrep()->create();
        $company = $this->companyFor($rep);

        $task = Activity::factory()->task()->create([
            'owner_id' => $rep->id,
            'subject_type' => Company::class,
            'subject_id' => $company->id,
        ]);

        $this->actingAs($rep, 'api')
            ->patchJson("/api/v1/tasks/{$task->id}/status", ['status' => 'done'])
            ->assertOk()
            ->assertJsonPath('data.status.value', 'done');

        // A settled task reopens; it does not become a different kind of settled one.
        $this->actingAs($rep, 'api')
            ->patchJson("/api/v1/tasks/{$task->id}/status", ['status' => 'cancelled'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['status']);

        $this->actingAs($rep, 'api')
            ->patchJson("/api/v1/tasks/{$task->id}/status", ['status' => 'done'])
            ->assertStatus(422);

        $this->actingAs($rep, 'api')
            ->patchJson("/api/v1/tasks/{$task->id}/status", ['status' => 'open'])
            ->assertOk()
            ->assertJsonPath('data.status.value', 'open');

        $this->actingAs($rep, 'api')
            ->patchJson("/api/v1/tasks/{$task->id}/status", ['status' => 'open'])
            ->assertStatus(422);

        $this->actingAs($rep, 'api')
            ->patchJson("/api/v1/tasks/{$task->id}/status", ['status' => 'postponed'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['status']);

        $this->assertSame(ActivityStatus::Open, $task->fresh()->status);
    }

    public function test_a_task_can_be_rescheduled_and_reassigned_by_whoever_owns_it(): void
    {
        $team = Team::factory()->create();
        $manager = User::factory()->manager()->create(['team_id' => $team->id]);
        $rep = User::factory()->salesrep()->create(['team_id' => $team->id]);
        $company = $this->companyFor($rep);

        $task = Activity::factory()->task()->create([
            'owner_id' => $rep->id,
            'subject_type' => Company::class,
            'subject_id' => $company->id,
        ]);

        $moved = Carbon::now()->addWeek();

        $this->actingAs($manager, 'api')
            ->patchJson("/api/v1/tasks/{$task->id}", [
                'subject' => 'Send the revised rate sheet (v2)',
                'body' => 'Add the weekend shift.',
                'due_at' => $moved->toIso8601String(),
            ])
            ->assertOk()
            ->assertJsonPath('data.subject', 'Send the revised rate sheet (v2)')
            ->assertJsonPath('data.due.days', 7);

        $this->assertSame($moved->timestamp, $task->fresh()->due_at->timestamp);

        // The record a task hangs off is fixed — a follow-up that changed hands would be a
        // different promise — but it is not in the payload at all, so nothing changes it.
        $this->actingAs($manager, 'api')
            ->patchJson("/api/v1/tasks/{$task->id}", ['subject_type' => 'lead'])
            ->assertOk();

        $this->assertSame(Company::class, $task->fresh()->subject_type);
    }

    public function test_a_salesrep_cannot_touch_another_reps_task(): void
    {
        $rep = User::factory()->salesrep()->create();
        $other = User::factory()->salesrep()->create();
        $company = $this->companyFor($other);

        $task = Activity::factory()->task()->create([
            'owner_id' => $other->id,
            'subject_type' => Company::class,
            'subject_id' => $company->id,
        ]);

        $this->actingAs($rep, 'api')
            ->getJson("/api/v1/tasks/{$task->id}")
            ->assertStatus(403);

        $this->actingAs($rep, 'api')
            ->patchJson("/api/v1/tasks/{$task->id}", ['subject' => 'Mine now'])
            ->assertStatus(403);

        $this->actingAs($rep, 'api')
            ->patchJson("/api/v1/tasks/{$task->id}/status", ['status' => 'done'])
            ->assertStatus(403);

        $this->assertSame('open', $task->fresh()->status->value);
    }

    public function test_a_manager_sees_a_team_task_and_an_admin_any(): void
    {
        $team = Team::factory()->create();
        $manager = User::factory()->manager()->create(['team_id' => $team->id]);
        $rep = User::factory()->salesrep()->create(['team_id' => $team->id]);
        $admin = User::factory()->admin()->create();
        $company = $this->companyFor($rep);

        $task = Activity::factory()->task()->create([
            'owner_id' => $rep->id,
            'subject_type' => Company::class,
            'subject_id' => $company->id,
        ]);

        $this->actingAs($manager, 'api')
            ->getJson("/api/v1/tasks/{$task->id}")
            ->assertOk()
            ->assertJsonPath('data.can_transition', true);

        $this->actingAs($admin, 'api')
            ->getJson("/api/v1/tasks/{$task->id}")
            ->assertOk();
    }

    public function test_the_task_routes_do_not_reach_an_ordinary_activity(): void
    {
        $rep = User::factory()->salesrep()->create();
        $lead = Lead::factory()->create(['owner_id' => $rep->id]);

        $note = $lead->activities()->create([
            'type' => ActivityType::Note,
            'subject' => 'Called about the roster',
            'status' => ActivityStatus::Done,
            'occurred_at' => Carbon::now(),
            'owner_id' => $rep->id,
        ]);

        $this->actingAs($rep, 'api')
            ->getJson("/api/v1/tasks/{$note->id}")
            ->assertStatus(403);

        $this->actingAs($rep, 'api')
            ->patchJson("/api/v1/tasks/{$note->id}/status", ['status' => 'open'])
            ->assertStatus(403);
    }

    public function test_a_task_cannot_be_deleted_only_cancelled(): void
    {
        $rep = User::factory()->salesrep()->create();
        $company = $this->companyFor($rep);

        $task = Activity::factory()->task()->create([
            'owner_id' => $rep->id,
            'subject_type' => Company::class,
            'subject_id' => $company->id,
        ]);

        $this->actingAs($rep, 'api')
            ->deleteJson("/api/v1/tasks/{$task->id}")
            ->assertStatus(405);

        $this->actingAs($rep, 'api')
            ->patchJson("/api/v1/tasks/{$task->id}/status", ['status' => 'cancelled'])
            ->assertOk()
            ->assertJsonPath('data.status.value', 'cancelled');

        $this->assertDatabaseHas('activities', ['id' => $task->id, 'status' => 'cancelled']);
    }

    public function test_a_task_on_a_lead_keeps_next_follow_up_at_honest(): void
    {
        $rep = User::factory()->salesrep()->create();
        $lead = Lead::factory()->create(['owner_id' => $rep->id, 'company_name' => 'Sunrise Foods Inc']);

        $this->assertNull($lead->fresh()->next_follow_up_at);

        // The expected instants are pinned once: the payload and the assertion must agree to the
        // second, and re-reading the wall clock between them is a race the test would lose.
        $laterDue = Carbon::now()->addDays(5);
        $soonerDue = Carbon::now()->addDays(2);

        $later = $this->actingAs($rep, 'api')
            ->postJson('/api/v1/tasks', [
                'subject_type' => 'lead',
                'subject_id' => $lead->id,
                'subject' => 'Call the HR supervisor back',
                'due_at' => $laterDue->toIso8601String(),
            ])
            ->assertCreated();

        $sooner = $this->actingAs($rep, 'api')
            ->postJson('/api/v1/tasks', [
                'subject_type' => 'lead',
                'subject_id' => $lead->id,
                'subject' => 'Send the recruitment pack',
                'due_at' => $soonerDue->toIso8601String(),
            ])
            ->assertCreated();

        // The sooner of the two open tasks is the lead's next follow-up ...
        $this->assertSame(
            $soonerDue->timestamp,
            $lead->fresh()->next_follow_up_at?->timestamp,
        );

        // ... and finishing it promotes the later one.
        $this->actingAs($rep, 'api')
            ->patchJson("/api/v1/tasks/{$sooner->json('data.id')}/status", ['status' => 'done'])
            ->assertOk();

        $this->assertSame(
            $laterDue->timestamp,
            $lead->fresh()->next_follow_up_at?->timestamp,
        );

        // Nothing is owed once both are settled (a cancelled task is not a promise).
        $this->actingAs($rep, 'api')
            ->patchJson("/api/v1/tasks/{$later->json('data.id')}/status", ['status' => 'cancelled'])
            ->assertOk();

        $this->assertNull($lead->fresh()->next_follow_up_at);
    }

    public function test_the_lead_list_carries_the_next_follow_up(): void
    {
        $rep = User::factory()->salesrep()->create();
        $lead = Lead::factory()->create(['owner_id' => $rep->id]);

        $dueAt = Carbon::now()->addDays(3);

        $this->actingAs($rep, 'api')
            ->postJson('/api/v1/tasks', [
                'subject_type' => 'lead',
                'subject_id' => $lead->id,
                'subject' => 'Follow up on the recruitment drive',
                'due_at' => $dueAt->toIso8601String(),
            ])
            ->assertCreated();

        $this->actingAs($rep, 'api')
            ->getJson('/api/v1/leads')
            ->assertOk()
            ->assertJsonPath('data.0.next_follow_up.state', 'upcoming')
            ->assertJsonPath('data.0.next_follow_up.days', 3)
            ->assertJsonPath(
                'data.0.next_follow_up_at',
                $lead->fresh()->next_follow_up_at?->toISOString(),
            );
    }

    public function test_a_task_is_marked_in_its_record_timeline(): void
    {
        $rep = User::factory()->salesrep()->create();
        $lead = Lead::factory()->create(['owner_id' => $rep->id]);

        $this->actingAs($rep, 'api')
            ->postJson('/api/v1/tasks', [
                'subject_type' => 'lead',
                'subject_id' => $lead->id,
                'subject' => 'Call the HR supervisor back',
                'due_at' => Carbon::now()->subDay()->toIso8601String(),
            ])
            ->assertCreated();

        $this->actingAs($rep, 'api')
            ->getJson("/api/v1/leads/{$lead->id}/timeline")
            ->assertOk()
            // What is still owed comes first, ahead of anything already done.
            ->assertJsonPath('data.0.subject', 'Call the HR supervisor back')
            ->assertJsonPath('data.0.is_follow_up', true)
            ->assertJsonPath('data.0.category', 'activity')
            ->assertJsonPath('data.0.due.state', 'overdue')
            ->assertJsonPath('data.0.due.label', 'Overdue')
            ->assertJsonPath('data.0.due.overdue', true);
    }

    public function test_an_archived_company_still_carries_its_tasks(): void
    {
        $rep = User::factory()->salesrep()->create();
        $company = Company::factory()->ownedBy($rep)->client()->create(['status' => CompanyStatus::Active]);

        $task = Activity::factory()->task()->create([
            'owner_id' => $rep->id,
            'subject_type' => Company::class,
            'subject_id' => $company->id,
        ]);

        $this->actingAs($rep, 'api')
            ->getJson("/api/v1/tasks/{$task->id}")
            ->assertOk();
    }
}
