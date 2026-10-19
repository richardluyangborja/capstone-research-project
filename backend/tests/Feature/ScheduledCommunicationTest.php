<?php

namespace Tests\Feature;

use App\Enums\CommunicationStatus;
use App\Enums\ContactRoleType;
use App\Models\Activity;
use App\Models\Communication;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Lead;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

/**
 * Scheduled outbound messages (module 04 slice S2, spec 12): the second shape a follow-up
 * takes. A `Communication` with `status = scheduled` is written now, sent later — by hand
 * (**Send now**) or by an admin dispatching what has fallen due — and until then it behaves
 * like a draft: no mirror, no clock, fully editable.
 */
class ScheduledCommunicationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 10:00 on a Monday in Manila, so "today", "yesterday" and "tomorrow" are unambiguous
     * whatever the runner's own timezone is.
     */
    private function freezeClock(): void
    {
        $this->travelTo(Carbon::parse('2026-10-19 02:00:00', 'UTC'));
    }

    private function companyFor(User $owner): Company
    {
        return Company::factory()->ownedBy($owner)->create();
    }

    /**
     * A scheduled follow-up on a record, written the way the model writes one.
     */
    private function scheduled(User $owner, Company $company, Carbon $due, array $attributes = []): Communication
    {
        return Communication::factory()
            ->about($company, $owner)
            ->scheduled($due)
            ->create($attributes);
    }

    public function test_a_scheduled_message_is_written_now_and_sent_later(): void
    {
        $this->freezeClock();

        $rep = User::factory()->salesrep()->create();
        $company = $this->companyFor($rep);

        $response = $this->actingAs($rep, 'api')
            ->postJson('/api/v1/communications', [
                'subject_type' => 'company',
                'subject_id' => $company->id,
                'channel' => 'email',
                'direction' => 'outbound',
                'status' => 'scheduled',
                'subject' => 'Rate sheet follow-up — confirm the night shift',
                'body' => 'Could you confirm the night shift?',
                'to_name' => 'Rosa Dela Cruz',
                'to_address' => 'rosa@sunrise.test',
                'scheduled_for' => Carbon::now()->addDay()->toIso8601String(),
            ])
            ->assertCreated()
            ->assertJsonPath('data.status', 'scheduled')
            ->assertJsonPath('data.occurred_at', null)
            ->assertJsonPath('data.due.state', 'upcoming')
            ->assertJsonPath('data.is_editable', true)
            ->assertJsonPath('data.can_send', true);

        $message = Communication::query()->findOrFail($response->json('data.id'));

        // It has not happened: no timestamp, and no line on the record's timeline.
        $this->assertNull($message->occurred_at);
        $this->assertSame(0, $message->activities()->count());

        // It is excluded from the book's "last contacted" — nothing has been contacted (rule 4).
        $this->actingAs($rep, 'api')
            ->getJson('/api/v1/communications')
            ->assertOk()
            ->assertJsonPath('summary.scheduled', 1)
            ->assertJsonPath('summary.last_contacted_at', null);
    }

    public function test_a_scheduled_message_stays_off_the_record_clock_until_it_is_sent(): void
    {
        $this->freezeClock();

        $rep = User::factory()->salesrep()->create();
        $lead = Lead::factory()->create(['owner_id' => $rep->id, 'last_activity_at' => null]);

        $response = $this->actingAs($rep, 'api')
            ->postJson('/api/v1/communications', [
                'subject_type' => 'lead',
                'subject_id' => $lead->id,
                'channel' => 'email',
                'direction' => 'outbound',
                'status' => 'scheduled',
                'subject' => 'Follow up on the shortlist',
                'to_address' => 'dan@harbor.test',
                'scheduled_for' => Carbon::now()->addDays(2)->toIso8601String(),
            ])
            ->assertCreated();

        $this->assertNull($lead->fresh()->last_activity_at);
        $this->assertSame(0, $lead->activities()->count());

        $this->actingAs($rep, 'api')
            ->postJson('/api/v1/communications/'.$response->json('data.id').'/send')
            ->assertOk()
            ->assertJsonPath('data.status', 'sent')
            ->assertJsonPath('meta.source', 'Email (mocked)');

        // Now it has happened: the clock moved and the timeline has its line.
        $this->assertNotNull($lead->fresh()->last_activity_at);
        $this->assertSame(1, $lead->activities()->count());
    }

    public function test_send_now_takes_a_scheduled_message_out_early_and_keeps_its_due_moment(): void
    {
        $this->freezeClock();

        $rep = User::factory()->salesrep()->create();
        $company = $this->companyFor($rep);
        $message = $this->scheduled($rep, $company, Carbon::now()->addDays(3));

        $this->actingAs($rep, 'api')
            ->postJson('/api/v1/communications/'.$message->id.'/send')
            ->assertOk()
            ->assertJsonPath('data.status', 'sent')
            ->assertJsonPath('data.external_id', 'EMAIL-'.$message->reference)
            ->assertJsonPath('data.due', null);

        $message->refresh();

        // Sent early: the moment it was owed is kept, so lateness is visible rather than erased.
        $this->assertNotNull($message->occurred_at);
        $this->assertNotNull($message->scheduled_for);
        $this->assertSame(1, $message->activities()->count());
    }

    public function test_a_rejected_scheduled_send_is_parked_as_failed_with_the_reason(): void
    {
        $this->freezeClock();
        Config::set('crm.integrations.email.simulate_failure', true);

        $rep = User::factory()->salesrep()->create();
        $company = $this->companyFor($rep);
        $message = $this->scheduled($rep, $company, Carbon::now()->addHour());

        $this->actingAs($rep, 'api')
            ->postJson('/api/v1/communications/'.$message->id.'/send')
            ->assertOk()
            ->assertJsonPath('data.status', 'failed')
            ->assertJsonPath('data.failed_reason', 'The mail provider rejected the message (mocked failure).');

        // A rejected send never left, so it is out of the worklist but not out of the book.
        $this->actingAs($rep, 'api')
            ->getJson('/api/v1/follow-ups')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_an_admin_dispatches_only_what_has_fallen_due_and_only_once(): void
    {
        $this->freezeClock();

        $admin = User::factory()->admin()->create();
        $rep = User::factory()->salesrep()->create();
        $company = $this->companyFor($rep);

        $overdue = $this->scheduled($rep, $company, Carbon::now()->subHour(), ['subject' => 'Chase the contract']);
        $future = $this->scheduled($rep, $company, Carbon::now()->addDay(), ['subject' => 'Send the rate sheet']);

        $this->actingAs($admin, 'api')
            ->postJson('/api/v1/communications/dispatch-due')
            ->assertOk()
            ->assertJsonPath('data.attempted', 1)
            ->assertJsonPath('data.sent', 1)
            ->assertJsonPath('data.failed', 0)
            ->assertJsonPath('meta.source', 'Email (mocked)');

        $this->assertSame(CommunicationStatus::Sent, $overdue->fresh()->status);
        $this->assertSame(CommunicationStatus::Scheduled, $future->fresh()->status);
        $this->assertNotNull($overdue->fresh()->occurred_at);

        // Nothing is due the second time — what went out is no longer scheduled.
        $this->actingAs($admin, 'api')
            ->postJson('/api/v1/communications/dispatch-due')
            ->assertOk()
            ->assertJsonPath('data.attempted', 0);
    }

    public function test_a_scheduled_message_with_no_recipient_fails_rather_than_stalling_the_run(): void
    {
        $this->freezeClock();

        $admin = User::factory()->admin()->create();
        $rep = User::factory()->salesrep()->create();
        $company = $this->companyFor($rep);

        $message = $this->scheduled($rep, $company, Carbon::now()->subHour(), [
            'to_address' => null,
            'to_name' => null,
            'contact_id' => null,
        ]);

        $this->actingAs($admin, 'api')
            ->postJson('/api/v1/communications/dispatch-due')
            ->assertOk()
            ->assertJsonPath('data.attempted', 1)
            ->assertJsonPath('data.sent', 0)
            ->assertJsonPath('data.failed', 1);

        $message->refresh();

        $this->assertSame(CommunicationStatus::Failed, $message->status);
        $this->assertSame('No recipient — nothing was sent.', $message->failed_reason);
    }

    public function test_only_an_admin_may_dispatch_the_due_sends(): void
    {
        $this->freezeClock();

        $rep = User::factory()->salesrep()->create();
        $manager = User::factory()->manager()->create();

        $this->actingAs($rep, 'api')
            ->postJson('/api/v1/communications/dispatch-due')
            ->assertForbidden();

        $this->actingAs($manager, 'api')
            ->postJson('/api/v1/communications/dispatch-due')
            ->assertForbidden();
    }

    public function test_the_command_sends_what_has_fallen_due(): void
    {
        $this->freezeClock();

        $rep = User::factory()->salesrep()->create();
        $company = $this->companyFor($rep);
        $message = $this->scheduled($rep, $company, Carbon::now()->subMinute());

        $this->artisan('communication:send-due')->assertSuccessful();

        $this->assertSame(CommunicationStatus::Sent, $message->fresh()->status);
        $this->assertNotNull($message->fresh()->occurred_at);
    }

    public function test_the_worklist_lists_both_shapes_by_due_time(): void
    {
        $this->freezeClock();

        $rep = User::factory()->salesrep()->create();
        $company = $this->companyFor($rep);

        $task = Activity::factory()->task()->create([
            'subject' => 'Confirm the 40-seat start date',
            'owner_id' => $rep->id,
            'subject_type' => Company::class,
            'subject_id' => $company->id,
            'due_at' => Carbon::now()->addHours(3),
        ]);

        $message = $this->scheduled($rep, $company, Carbon::now()->subDay(), [
            'subject' => 'Rate sheet follow-up',
            'to_name' => 'Rosa Dela Cruz',
            'to_address' => 'rosa@sunrise.test',
        ]);

        $this->actingAs($rep, 'api')
            ->getJson('/api/v1/follow-ups')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            // What is most owed, first — a message and a task ordered by the same clock.
            ->assertJsonPath('data.0.id', $message->id)
            ->assertJsonPath('data.0.kind', 'message')
            ->assertJsonPath('data.0.status.value', 'scheduled')
            ->assertJsonPath('data.0.channel', 'email')
            ->assertJsonPath('data.0.recipient', 'Rosa Dela Cruz <rosa@sunrise.test>')
            ->assertJsonPath('data.0.due.state', 'overdue')
            ->assertJsonPath('data.0.can_send', true)
            ->assertJsonPath('data.0.can_transition', false)
            ->assertJsonPath('data.1.id', $task->id)
            ->assertJsonPath('data.1.kind', 'task')
            ->assertJsonPath('data.1.recipient', null)
            ->assertJsonPath('counts.open', 2)
            ->assertJsonPath('counts.overdue', 1)
            ->assertJsonPath('counts.today', 1);
    }

    public function test_the_worklist_can_be_narrowed_to_one_shape(): void
    {
        $this->freezeClock();

        $rep = User::factory()->salesrep()->create();
        $company = $this->companyFor($rep);

        $task = Activity::factory()->task()->create([
            'owner_id' => $rep->id,
            'subject_type' => Company::class,
            'subject_id' => $company->id,
            'due_at' => Carbon::now()->addHour(),
        ]);

        $message = $this->scheduled($rep, $company, Carbon::now()->addHours(2));

        $this->actingAs($rep, 'api')
            ->getJson('/api/v1/follow-ups?filter[kind]=message')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $message->id)
            // A message is never done, so the settled counter is a task counter.
            ->assertJsonPath('counts.done', 0);

        $this->actingAs($rep, 'api')
            ->getJson('/api/v1/follow-ups?filter[kind]=task')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $task->id);
    }

    public function test_the_worklist_follows_the_record_the_caller_may_see(): void
    {
        $this->freezeClock();

        $rep = User::factory()->salesrep()->create();
        $other = User::factory()->salesrep()->create();

        $mine = $this->scheduled($rep, $this->companyFor($rep), Carbon::now()->addHour());
        $this->scheduled($other, $this->companyFor($other), Carbon::now()->addHour());

        $this->actingAs($rep, 'api')
            ->getJson('/api/v1/follow-ups')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $mine->id);
    }

    public function test_the_book_narrows_scheduled_messages_by_due_state(): void
    {
        $this->freezeClock();

        $rep = User::factory()->salesrep()->create();
        $company = $this->companyFor($rep);

        $overdue = $this->scheduled($rep, $company, Carbon::now()->subDay());
        $upcoming = $this->scheduled($rep, $company, Carbon::now()->addDay());

        $this->actingAs($rep, 'api')
            ->getJson('/api/v1/communications?filter[due]=overdue')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $overdue->id);

        $this->actingAs($rep, 'api')
            ->getJson('/api/v1/communications?filter[due]=upcoming')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $upcoming->id);
    }

    public function test_a_lead_takes_the_sooner_of_its_task_and_its_scheduled_message(): void
    {
        $this->freezeClock();

        $rep = User::factory()->salesrep()->create();
        $lead = Lead::factory()->create(['owner_id' => $rep->id]);

        $later = $this->actingAs($rep, 'api')
            ->postJson('/api/v1/communications', [
                'subject_type' => 'lead',
                'subject_id' => $lead->id,
                'channel' => 'email',
                'direction' => 'outbound',
                'status' => 'scheduled',
                'subject' => 'Send the proposal',
                'to_address' => 'rosa@sunrise.test',
                'scheduled_for' => Carbon::now()->addDays(3)->toIso8601String(),
            ])
            ->assertCreated();

        $this->assertSame(
            Carbon::now()->addDays(3)->toDateTimeString(),
            $lead->fresh()->next_follow_up_at->toDateTimeString(),
        );

        $sooner = $this->actingAs($rep, 'api')
            ->postJson('/api/v1/tasks', [
                'subject_type' => 'lead',
                'subject_id' => $lead->id,
                'subject' => 'Confirm the shortlist with Dan',
                'due_at' => Carbon::now()->addDay()->toIso8601String(),
            ])
            ->assertCreated();

        $this->assertSame(
            Carbon::now()->addDay()->toDateTimeString(),
            $lead->fresh()->next_follow_up_at->toDateTimeString(),
        );

        // Send the message early and complete the task: nothing is owed, so the stamp clears.
        $this->actingAs($rep, 'api')
            ->postJson('/api/v1/communications/'.$later->json('data.id').'/send')
            ->assertOk();

        $this->actingAs($rep, 'api')
            ->patchJson('/api/v1/tasks/'.$sooner->json('data.id').'/status', ['status' => 'done'])
            ->assertOk();

        $this->assertNull($lead->fresh()->next_follow_up_at);
    }

    public function test_a_scheduled_message_can_be_rescheduled_but_not_made_to_have_happened(): void
    {
        $this->freezeClock();

        $rep = User::factory()->salesrep()->create();
        $company = $this->companyFor($rep);
        $message = $this->scheduled($rep, $company, Carbon::now()->addDay());

        $this->actingAs($rep, 'api')
            ->patchJson('/api/v1/communications/'.$message->id, [
                'scheduled_for' => Carbon::now()->addDays(4)->toIso8601String(),
            ])
            ->assertOk()
            ->assertJsonPath('data.scheduled_for', Carbon::now()->addDays(4)->toIso8601String())
            ->assertJsonPath('data.occurred_at', null);

        // A scheduled message has not happened: it cannot be told that it has.
        $this->actingAs($rep, 'api')
            ->patchJson('/api/v1/communications/'.$message->id, [
                'occurred_at' => Carbon::now()->toIso8601String(),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('occurred_at');

        // And a scheduled moment is not a field of a message that already went out.
        $sent = Communication::factory()->about($company, $rep)->create();

        $this->actingAs($rep, 'api')
            ->patchJson('/api/v1/communications/'.$sent->id, [
                'scheduled_for' => Carbon::now()->addDay()->toIso8601String(),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('scheduled_for');
    }

    public function test_retiring_a_scheduled_message_takes_it_out_of_the_worklist(): void
    {
        $this->freezeClock();

        $rep = User::factory()->salesrep()->create();
        $company = $this->companyFor($rep);
        $message = $this->scheduled($rep, $company, Carbon::now()->addDay());

        $this->actingAs($rep, 'api')
            ->deleteJson('/api/v1/communications/'.$message->id)
            ->assertNoContent();

        $this->actingAs($rep, 'api')
            ->getJson('/api/v1/follow-ups')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_a_scheduled_message_may_name_its_recipient_through_a_contact(): void
    {
        $this->freezeClock();

        $rep = User::factory()->salesrep()->create();
        $company = $this->companyFor($rep);

        $contact = Contact::factory()->forCompany($company)->create([
            'name' => 'Ana Reyes',
            'email' => 'ana@sunrise.test',
            'role_type' => ContactRoleType::Hr,
        ]);

        // No address typed, but the picked person has one — the send fills it in.
        $this->actingAs($rep, 'api')
            ->postJson('/api/v1/communications', [
                'subject_type' => 'company',
                'subject_id' => $company->id,
                'contact_id' => $contact->id,
                'channel' => 'email',
                'direction' => 'outbound',
                'status' => 'scheduled',
                'subject' => 'Confirm the shift patterns',
                'scheduled_for' => Carbon::now()->addDay()->toIso8601String(),
            ])
            ->assertCreated();

        $this->actingAs($rep, 'api')
            ->getJson('/api/v1/follow-ups')
            ->assertOk()
            ->assertJsonPath('data.0.recipient', 'Ana Reyes <ana@sunrise.test>');
    }

    public function test_a_scheduled_message_is_visible_with_its_record(): void
    {
        $this->freezeClock();

        $rep = User::factory()->salesrep()->create();
        $other = User::factory()->salesrep()->create();
        $company = $this->companyFor($rep);

        $message = $this->scheduled($rep, $company, Carbon::now()->addDay());

        // The record's own history carries it, so the worklist row deep-links somewhere real.
        $this->actingAs($rep, 'api')
            ->getJson('/api/v1/companies/'.$company->id.'/communications')
            ->assertOk()
            ->assertJsonPath('data.0.id', $message->id)
            ->assertJsonPath('data.0.due.state', 'upcoming');

        $this->actingAs($other, 'api')
            ->getJson('/api/v1/companies/'.$company->id.'/communications')
            ->assertForbidden();
    }
}
