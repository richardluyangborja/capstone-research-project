<?php

namespace Tests\Feature;

use App\Enums\ActivityStatus;
use App\Enums\ActivityType;
use App\Models\Activity;
use App\Models\Company;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The follow-up worklist (module 04 slice S1, spec 12): what is owed, ordered by when, narrowed
 * by who owes it and by the record it hangs off — with the due state derived from the clock
 * rather than stored.
 */
class FollowUpWorklistTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 10:00 on a Monday in Manila — far enough into the PH day that "today" and "yesterday"
     * are unambiguous whatever the runner's own timezone is.
     */
    private function freezeClock(): void
    {
        $this->travelTo(Carbon::parse('2026-10-19 02:00:00', 'UTC'));
    }

    private function task(Company $company, User $owner, array $attributes = []): Activity
    {
        return Activity::factory()->task()->create($attributes + [
            'owner_id' => $owner->id,
            'subject_type' => Company::class,
            'subject_id' => $company->id,
        ]);
    }

    public function test_the_worklist_lists_what_is_owed_by_when_it_is_due(): void
    {
        $this->freezeClock();

        $rep = User::factory()->salesrep()->create();
        $company = Company::factory()->ownedBy($rep)->create();

        $overdue = $this->task($company, $rep, [
            'subject' => 'Send the revised rate sheet',
            'due_at' => Carbon::now()->subDays(2),
        ]);
        $today = $this->task($company, $rep, [
            'subject' => 'Confirm the 40-seat start date',
            'due_at' => Carbon::now()->addHours(3),
        ]);
        $upcoming = $this->task($company, $rep, [
            'subject' => 'Chase the signed contract',
            'due_at' => Carbon::now()->addDays(3),
        ]);
        // Settled work is not owed, so the default worklist leaves it out.
        $this->task($company, $rep, [
            'subject' => 'Prepare the crew schedule',
            'due_at' => Carbon::now()->subDays(4),
            'status' => ActivityStatus::Done,
        ]);
        $this->task($company, $rep, [
            'subject' => 'Book a site visit',
            'due_at' => Carbon::now()->subDays(6),
            'status' => ActivityStatus::Cancelled,
        ]);

        $response = $this->actingAs($rep, 'api')
            ->getJson('/api/v1/follow-ups')
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('data.0.id', $overdue->id)
            ->assertJsonPath('data.0.kind', 'task')
            ->assertJsonPath('data.0.due.state', 'overdue')
            ->assertJsonPath('data.0.due.overdue', true)
            ->assertJsonPath('data.0.record.type', 'company')
            ->assertJsonPath('data.0.record.label', $company->name)
            ->assertJsonPath('data.0.owner.id', $rep->id)
            ->assertJsonPath('data.1.id', $today->id)
            ->assertJsonPath('data.1.due.state', 'today')
            ->assertJsonPath('data.1.due.days', 0)
            ->assertJsonPath('data.2.id', $upcoming->id)
            ->assertJsonPath('data.2.due.state', 'upcoming')
            ->assertJsonPath('data.2.due.days', 3);

        // The counters are the same filters read as numbers.
        $response
            ->assertJsonPath('counts.overdue', 1)
            ->assertJsonPath('counts.today', 1)
            ->assertJsonPath('counts.upcoming', 1)
            ->assertJsonPath('counts.open', 3)
            ->assertJsonPath('counts.done', 1)
            ->assertJsonPath('meta.total', 3);
    }

    public function test_the_worklist_narrows_by_state(): void
    {
        $this->freezeClock();

        $rep = User::factory()->salesrep()->create();
        $company = Company::factory()->ownedBy($rep)->create();

        $overdue = $this->task($company, $rep, ['due_at' => Carbon::now()->subDay()]);
        $today = $this->task($company, $rep, ['due_at' => Carbon::now()->addHour()]);
        $upcoming = $this->task($company, $rep, ['due_at' => Carbon::now()->addWeek()]);
        $done = $this->task($company, $rep, ['due_at' => Carbon::now()->subDay(), 'status' => ActivityStatus::Done]);
        $cancelled = $this->task($company, $rep, ['due_at' => Carbon::now()->subDay(), 'status' => ActivityStatus::Cancelled]);

        $states = [
            'overdue' => $overdue->id,
            'today' => $today->id,
            'upcoming' => $upcoming->id,
            'done' => $done->id,
            'cancelled' => $cancelled->id,
        ];

        foreach ($states as $state => $id) {
            $this->actingAs($rep, 'api')
                ->getJson("/api/v1/follow-ups?filter[state]={$state}")
                ->assertOk()
                ->assertJsonCount(1, 'data')
                ->assertJsonPath('data.0.id', $id);
        }

        // `all` looks at everything, settled or not.
        $this->actingAs($rep, 'api')
            ->getJson('/api/v1/follow-ups?filter[state]=all')
            ->assertOk()
            ->assertJsonCount(5, 'data');
    }

    public function test_the_due_state_follows_the_clock_rather_than_a_stored_column(): void
    {
        $this->freezeClock();

        $rep = User::factory()->salesrep()->create();
        $company = Company::factory()->ownedBy($rep)->create();
        $task = $this->task($company, $rep, ['due_at' => Carbon::now()->addDays(2)]);

        $this->actingAs($rep, 'api')
            ->getJson('/api/v1/follow-ups')
            ->assertOk()
            ->assertJsonPath('data.0.due.state', 'upcoming')
            ->assertJsonPath('counts.overdue', 0);

        // Three days later the same row is late — nothing was written to say so.
        $this->travelTo(Carbon::now()->addDays(3));

        $this->actingAs($rep, 'api')
            ->getJson('/api/v1/follow-ups')
            ->assertOk()
            ->assertJsonPath('data.0.due.state', 'overdue')
            ->assertJsonPath('data.0.due.days', -1)
            ->assertJsonPath('counts.overdue', 1);

        $this->assertSame('open', $task->fresh()->status->value);
        $this->assertTrue(Carbon::parse('2026-10-21 02:00:00', 'UTC')->equalTo($task->fresh()->due_at));
    }

    public function test_a_late_task_due_earlier_today_counts_as_overdue(): void
    {
        $this->freezeClock();

        $rep = User::factory()->salesrep()->create();
        $company = Company::factory()->ownedBy($rep)->create();

        // 09:30 Manila — half an hour past, and still "today" on the calendar.
        $this->task($company, $rep, ['due_at' => Carbon::now()->subMinutes(30)]);

        $this->actingAs($rep, 'api')
            ->getJson('/api/v1/follow-ups')
            ->assertOk()
            ->assertJsonPath('data.0.due.state', 'overdue')
            ->assertJsonPath('counts.overdue', 1)
            ->assertJsonPath('counts.today', 0);
    }

    public function test_the_worklist_narrows_by_owner_and_by_record(): void
    {
        $this->freezeClock();

        $team = Team::factory()->create();
        $rep = User::factory()->salesrep()->create(['team_id' => $team->id]);
        $colleague = User::factory()->salesrep()->create(['team_id' => $team->id]);
        $company = Company::factory()->ownedBy($rep)->create();

        $mine = $this->task($company, $rep, ['subject' => 'Send the revised rate sheet']);
        $theirs = $this->task($company, $colleague, ['subject' => 'Confirm the start date']);

        $this->actingAs($rep, 'api')
            ->getJson('/api/v1/follow-ups?filter[owner]=me')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $mine->id);

        $this->actingAs($rep, 'api')
            ->getJson('/api/v1/follow-ups?filter[owner]=all')
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $this->actingAs($rep, 'api')
            ->getJson('/api/v1/follow-ups?filter[owner]='.$colleague->id)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $theirs->id);

        // The deep link a record's "Follow-up" action uses.
        $this->actingAs($rep, 'api')
            ->getJson("/api/v1/follow-ups?filter[record_type]=company&filter[record_id]={$company->id}")
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $this->actingAs($rep, 'api')
            ->getJson('/api/v1/follow-ups?filter[record_type]=opportunity')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_the_worklist_searches_and_narrows_by_due_window(): void
    {
        $this->freezeClock();

        $rep = User::factory()->salesrep()->create();
        $company = Company::factory()->ownedBy($rep)->create();

        $this->task($company, $rep, [
            'subject' => 'Send the revised rate sheet',
            'body' => 'Cover the two supervisor roles.',
            'due_at' => Carbon::now()->addDays(2),
        ]);
        $this->task($company, $rep, [
            'subject' => 'Confirm the 40-seat start date',
            'due_at' => Carbon::now()->addDays(9),
        ]);

        $this->actingAs($rep, 'api')
            ->getJson('/api/v1/follow-ups?search=rate%20sheet')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.subject', 'Send the revised rate sheet');

        // The notes are searchable too — a rep remembers the detail, not the title.
        $this->actingAs($rep, 'api')
            ->getJson('/api/v1/follow-ups?search=supervisor')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->actingAs($rep, 'api')
            ->getJson('/api/v1/follow-ups?filter[due_from]='.Carbon::now()->addDay()->toDateString()
                .'&filter[due_to]='.Carbon::now()->addDays(5)->toDateString())
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.subject', 'Send the revised rate sheet');
    }

    public function test_a_salesrep_sees_only_follow_ups_on_their_own_records(): void
    {
        $this->freezeClock();

        $team = Team::factory()->create();
        $otherTeam = Team::factory()->create();
        $rep = User::factory()->salesrep()->create(['team_id' => $team->id]);
        $colleague = User::factory()->salesrep()->create(['team_id' => $team->id]);
        $stranger = User::factory()->salesrep()->create(['team_id' => $otherTeam->id]);

        $mine = $this->task(Company::factory()->ownedBy($rep)->create(), $rep);
        $this->task(Company::factory()->ownedBy($colleague)->create(), $colleague);
        $this->task(Company::factory()->ownedBy($stranger)->create(), $stranger);

        $this->actingAs($rep, 'api')
            ->getJson('/api/v1/follow-ups?filter[state]=all')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $mine->id);

        // A manager covers the team's records, and neither team reaches the other.
        $manager = User::factory()->manager()->create(['team_id' => $team->id]);

        $this->actingAs($manager, 'api')
            ->getJson('/api/v1/follow-ups?filter[state]=all')
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $admin = User::factory()->admin()->create();

        $this->actingAs($admin, 'api')
            ->getJson('/api/v1/follow-ups?filter[state]=all')
            ->assertOk()
            ->assertJsonCount(3, 'data');
    }

    public function test_the_worklist_carries_the_rights_to_act_on_each_row(): void
    {
        $this->freezeClock();

        $team = Team::factory()->create();
        $manager = User::factory()->manager()->create(['team_id' => $team->id]);
        $rep = User::factory()->salesrep()->create(['team_id' => $team->id]);
        $company = Company::factory()->ownedBy($rep)->create();

        $task = $this->task($company, $rep);

        // The owner may move it; a manager over the team may too.
        $this->actingAs($rep, 'api')
            ->getJson('/api/v1/follow-ups')
            ->assertOk()
            ->assertJsonPath('data.0.can_update', true)
            ->assertJsonPath('data.0.can_transition', true);

        $this->actingAs($manager, 'api')
            ->getJson('/api/v1/follow-ups')
            ->assertOk()
            ->assertJsonPath('data.0.can_update', true)
            ->assertJsonPath('data.0.can_transition', true);

        // An admin who is not the owner still may, but never through a deleted route.
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin, 'api')
            ->getJson('/api/v1/follow-ups')
            ->assertOk()
            ->assertJsonPath('data.0.can_update', true)
            ->assertJsonPath('data.0.can_delete', false);

        $this->assertSame($task->id, $task->fresh()->id);
    }

    public function test_the_worklist_never_lists_an_ordinary_activity(): void
    {
        $this->freezeClock();

        $rep = User::factory()->salesrep()->create();
        $company = Company::factory()->ownedBy($rep)->create();

        $company->activities()->create([
            'type' => ActivityType::Note,
            'subject' => 'Called about the roster',
            'status' => ActivityStatus::Open,
            'occurred_at' => Carbon::now(),
            'owner_id' => $rep->id,
        ]);

        $this->actingAs($rep, 'api')
            ->getJson('/api/v1/follow-ups?filter[state]=all')
            ->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('meta.total', 0);

        // The module's own writing never leaks a task type into the quick log.
        $this->assertSame(0, Activity::where('type', ActivityType::Task)->count());
    }
}
