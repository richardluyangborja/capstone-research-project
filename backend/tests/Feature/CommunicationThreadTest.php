<?php

namespace Tests\Feature;

use App\Enums\CommunicationStatus;
use App\Models\Communication;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Opportunity;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Slice S4 — conversations (spec 11 §S4). A reply joins the message it answers rather than
 * landing beside it, the thread endpoints read an exchange back in the order it happened, and
 * a thread is visible only while every message in it is.
 */
class CommunicationThreadTest extends TestCase
{
    use RefreshDatabase;

    /** A deal the caller owns, so messages about it are theirs to work. */
    private function dealFor(User $owner): Opportunity
    {
        return Opportunity::factory()
            ->forCompany(Company::factory()->ownedBy($owner)->create())
            ->ownedBy($owner)
            ->create();
    }

    /** An email the client sent us — the thing a rep actually answers. */
    private function inboundFor(User $owner, ?Opportunity $deal = null): Communication
    {
        return Communication::factory()
            ->about($deal ?? $this->dealFor($owner), $owner)
            ->inbound()
            ->create();
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return TestResponse
     */
    private function reply(User $user, Communication $parent, array $payload = [])
    {
        return $this->actingAs($user, 'api')->postJson(
            "/api/v1/communications/{$parent->id}/reply",
            $payload,
        );
    }

    public function test_a_reply_joins_its_parents_thread_and_names_the_message_it_answers(): void
    {
        $rep = User::factory()->salesrep()->create();
        $inbound = $this->inboundFor($rep);

        $this->reply($rep, $inbound, ['body' => 'Rates hold for 30 days.'])
            ->assertCreated()
            ->assertJsonPath('data.thread_id', $inbound->id)
            ->assertJsonPath('data.in_reply_to_id', $inbound->id)
            ->assertJsonPath('data.in_reply_to.reference', $inbound->reference)
            ->assertJsonPath('data.record.type', 'opportunity')
            ->assertJsonPath('data.owner_id', $rep->id);

        // The message being answered becomes the thread's root, so a single id selects the
        // whole conversation — the original included — rather than only its replies.
        $this->assertSame($inbound->id, $inbound->fresh()->thread_id);
    }

    public function test_a_reply_inherits_the_channel_the_title_and_the_contact_and_goes_out(): void
    {
        $rep = User::factory()->salesrep()->create();
        $company = Company::factory()->ownedBy($rep)->create();
        $deal = Opportunity::factory()->forCompany($company)->ownedBy($rep)->create();
        $contact = Contact::factory()->create(['company_id' => $company->id]);

        $inbound = Communication::factory()
            ->about($deal, $rep)
            ->inbound()
            ->create(['contact_id' => $contact->id]);

        $response = $this->reply($rep, $inbound, ['body' => 'Here is the crew schedule.'])
            ->assertCreated()
            // The conversation settled the channel, the title line and the person; the caller
            // is the one answering, so the message goes out.
            ->assertJsonPath('data.channel', 'email')
            ->assertJsonPath('data.subject', $inbound->subject)
            ->assertJsonPath('data.contact_id', $contact->id)
            ->assertJsonPath('data.direction', 'outbound')
            ->assertJsonPath('data.status', 'sent');

        $this->assertSame($inbound->subject, $response->json('data.subject'));
    }

    public function test_replying_to_a_reply_answers_that_message_and_keeps_the_conversation(): void
    {
        $rep = User::factory()->salesrep()->create();
        $inbound = $this->inboundFor($rep);

        $first = $this->reply($rep, $inbound, ['body' => 'Rates hold for 30 days.'])->assertCreated();
        $firstId = $first->json('data.id');

        $second = $this->reply($rep, Communication::findOrFail($firstId), ['body' => 'And the crew is held.'])
            ->assertCreated()
            ->assertJsonPath('data.thread_id', $inbound->id)
            ->assertJsonPath('data.in_reply_to_id', $firstId);

        // The whole exchange now reads under the one thread id — original, answer and answer.
        $this->assertSame(
            3,
            Communication::where('thread_id', $inbound->id)->count(),
        );
        $this->assertSame($inbound->id, $second->json('data.thread_id'));
    }

    public function test_a_thread_returns_the_conversation_in_the_order_it_happened(): void
    {
        $rep = User::factory()->salesrep()->create();
        $deal = $this->dealFor($rep);

        $inbound = Communication::factory()
            ->about($deal, $rep)
            ->inbound()
            ->create(['occurred_at' => Carbon::now()->subDays(2)]);

        $this->reply($rep, $inbound, [
            'body' => 'Rates hold for 30 days.',
            'occurred_at' => Carbon::now()->subDay()->toIso8601String(),
        ])->assertCreated();

        $last = $this->reply($rep, $inbound->fresh(), [
            'body' => 'The crew is held until the 18th.',
            'occurred_at' => Carbon::now()->subDay()->addHour()->toIso8601String(),
        ])->assertCreated();

        $thread = $this->actingAs($rep, 'api')
            ->getJson("/api/v1/communications/threads/{$inbound->id}")
            ->assertOk()
            ->assertJsonPath('data.thread_id', $inbound->id)
            ->assertJsonPath('data.title', $inbound->subject)
            ->assertJsonPath('data.record.type', 'opportunity')
            ->assertJsonPath('data.message_count', 3)
            ->assertJsonPath('data.messages.0.id', $inbound->id)
            ->assertJsonPath('data.messages.2.id', $last->json('data.id'));

        $this->assertCount(3, $thread->json('data.messages'));
    }

    public function test_the_thread_list_groups_conversations_and_leaves_single_messages_out(): void
    {
        $rep = User::factory()->salesrep()->create();

        $answered = $this->inboundFor($rep);
        $this->reply($rep, $answered, ['body' => 'Here is the revision.'])->assertCreated();
        $this->reply($rep, $answered->fresh(), ['body' => 'And the schedule.'])->assertCreated();

        // A message nobody answered is not a conversation and is not listed.
        $this->inboundFor($rep);

        $list = $this->actingAs($rep, 'api')
            ->getJson('/api/v1/communications/threads')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.thread_id', $answered->id)
            ->assertJsonPath('data.0.message_count', 3)
            ->assertJsonPath('data.0.last_message.reference', Communication::where('thread_id', $answered->id)->orderByDesc('occurred_at')->orderByDesc('id')->firstOrFail()->reference);

        $this->assertCount(1, $list->json('data'));
    }

    public function test_the_thread_list_can_be_narrowed_to_a_record(): void
    {
        $rep = User::factory()->salesrep()->create();
        $mine = $this->dealFor($rep);
        $other = $this->dealFor($rep);

        $wanted = $this->inboundFor($rep, $mine);
        $this->reply($rep, $wanted)->assertCreated();

        $unwanted = $this->inboundFor($rep, $other);
        $this->reply($rep, $unwanted)->assertCreated();

        $list = $this->actingAs($rep, 'api')
            ->getJson('/api/v1/communications/threads?filter[subject_type]=opportunity&filter[subject_id]='.$mine->id)
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.thread_id', $wanted->id);

        $this->assertSame(
            $wanted->id,
            collect($list->json('data'))->pluck('thread_id')->first(),
        );
    }

    public function test_a_thread_on_a_record_the_caller_cannot_see_is_hidden(): void
    {
        $team = Team::factory()->create();
        $rep = User::factory()->salesrep()->create(['team_id' => $team->id]);
        $manager = User::factory()->manager()->create(['team_id' => $team->id]);
        $outsider = User::factory()->salesrep()->create();

        $theirs = $this->inboundFor($rep);
        $this->reply($rep, $theirs)->assertCreated();

        // Another rep sees neither the conversation nor the message inside it.
        $this->actingAs($outsider, 'api')
            ->getJson("/api/v1/communications/threads/{$theirs->id}")
            ->assertNotFound();

        $list = $this->actingAs($outsider, 'api')->getJson('/api/v1/communications/threads')->assertOk();

        $this->assertSame(0, $list->json('meta.total'));
        $this->assertSame([], $list->json('data'));

        // The team's manager covers it, and an admin covers everything.
        $this->actingAs($manager, 'api')
            ->getJson("/api/v1/communications/threads/{$theirs->id}")
            ->assertOk()
            ->assertJsonPath('data.message_count', 2);

        $this->actingAs(User::factory()->admin()->create(), 'api')
            ->getJson("/api/v1/communications/threads/{$theirs->id}")
            ->assertOk();
    }

    public function test_a_thread_is_hidden_when_one_of_its_messages_is(): void
    {
        $rep = User::factory()->salesrep()->create();
        $outsider = User::factory()->salesrep()->create();

        $mine = $this->inboundFor($rep);
        $theirs = Communication::factory()->about($this->dealFor($outsider), $outsider)->inbound()->create();

        // Hand-built state: a reply always inherits its parent's record, so the API cannot
        // produce a mixed thread — which is exactly why the rule is checked rather than
        // assumed. One message the caller may not see hides the whole exchange.
        $mine->update(['thread_id' => $mine->id]);
        $theirs->update(['thread_id' => $mine->id]);

        $this->actingAs($rep, 'api')
            ->getJson("/api/v1/communications/threads/{$mine->id}")
            ->assertNotFound();
    }

    public function test_answering_follows_the_records_rights(): void
    {
        $team = Team::factory()->create();
        $rep = User::factory()->salesrep()->create(['team_id' => $team->id]);
        $manager = User::factory()->manager()->create(['team_id' => $team->id]);
        $outsider = User::factory()->salesrep()->create();

        $inbound = $this->inboundFor($rep);

        // A reply is another thing said about the record, so it needs the same right as
        // logging one: someone else's record is not the caller's to answer.
        $this->reply($outsider, $inbound)->assertForbidden();
        $this->reply($manager, $inbound)->assertCreated();

        $this->assertSame(1, Communication::where('in_reply_to_id', $inbound->id)->count());
    }

    public function test_a_message_that_never_went_out_cannot_be_answered(): void
    {
        $rep = User::factory()->salesrep()->create();
        $deal = $this->dealFor($rep);

        $locked = [
            Communication::factory()->about($deal, $rep)->status(CommunicationStatus::Draft)->create(),
            Communication::factory()->about($deal, $rep)->status(CommunicationStatus::Failed)->create(),
            Communication::factory()->about($deal, $rep)->status(CommunicationStatus::Scheduled)->create(),
        ];

        foreach ($locked as $communication) {
            $this->reply($rep, $communication)
                ->assertStatus(422)
                ->assertJsonValidationErrors('status');
        }

        $this->assertSame(0, Communication::whereNotNull('in_reply_to_id')->count());
    }

    public function test_a_reply_writes_its_timeline_mirror_and_restarts_the_clock(): void
    {
        $rep = User::factory()->salesrep()->create();
        $deal = $this->dealFor($rep);
        $inbound = Communication::factory()->about($deal, $rep)->inbound()->create();

        $deal->forceFill(['last_activity_at' => Carbon::now()->subDays(10)])->save();

        $this->reply($rep, $inbound, ['body' => 'Rates hold for 30 days.'])->assertCreated();

        $reply = Communication::where('in_reply_to_id', $inbound->id)->firstOrFail();

        $this->assertDatabaseHas('activities', [
            'communication_id' => $reply->id,
            'subject_type' => Opportunity::class,
            'subject_id' => $deal->id,
        ]);
        $this->assertTrue($deal->fresh()->last_activity_at->greaterThan(Carbon::now()->subMinute()));

        // A draft reply has not happened yet: it carries no mirror and moves no clock.
        $deal->refresh()->forceFill(['last_activity_at' => Carbon::now()->subDays(10)])->save();

        $this->reply($rep, $inbound->fresh(), [
            'status' => CommunicationStatus::Draft->value,
            'to_address' => 'rosa@sunrise.test',
        ])->assertCreated()->assertJsonPath('data.status', 'draft');

        $draft = Communication::where('in_reply_to_id', $inbound->id)
            ->where('status', CommunicationStatus::Draft)
            ->firstOrFail();

        $this->assertDatabaseMissing('activities', ['communication_id' => $draft->id]);
        $this->assertTrue($deal->fresh()->last_activity_at->lessThan(Carbon::now()->subDays(9)));
    }

    public function test_a_reply_cannot_move_to_another_record(): void
    {
        $rep = User::factory()->salesrep()->create();
        $inbound = $this->inboundFor($rep);
        $elsewhere = $this->dealFor($rep);

        // The record is inherited, never accepted: a conversation does not change hands.
        $this->reply($rep, $inbound, [
            'subject_type' => 'opportunity',
            'subject_id' => $elsewhere->id,
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['subject_type', 'subject_id']);

        $this->assertSame(0, Communication::where('subject_id', $elsewhere->id)->count());
    }

    public function test_the_payload_says_whether_a_message_may_be_answered(): void
    {
        $rep = User::factory()->salesrep()->create();
        $deal = $this->dealFor($rep);

        $received = Communication::factory()->about($deal, $rep)->inbound()->create();
        $this->reply($rep, $received)->assertCreated();

        $this->actingAs($rep, 'api')
            ->getJson("/api/v1/communications/{$received->id}")
            ->assertOk()
            ->assertJsonPath('data.can_reply', true)
            ->assertJsonPath('data.thread_id', $received->id);

        // A draft has not happened, so the control is not offered for it.
        $draft = Communication::factory()->about($deal, $rep)
            ->status(CommunicationStatus::Draft)
            ->create();

        $this->actingAs($rep, 'api')
            ->getJson("/api/v1/communications/{$draft->id}")
            ->assertOk()
            ->assertJsonPath('data.can_reply', false)
            ->assertJsonPath('data.thread_id', null);
    }

    public function test_the_inbound_sync_files_a_pulled_reply_into_the_conversation(): void
    {
        $rep = User::factory()->salesrep()->create();
        $deal = $this->dealFor($rep);

        // Send the way the UI does, so the message carries the id the mocked mailbox knows it
        // by — that is what lets the pull place the answer.
        $created = $this->actingAs($rep, 'api')->postJson('/api/v1/communications', [
            'subject_type' => 'opportunity',
            'subject_id' => $deal->id,
            'channel' => 'email',
            'direction' => 'outbound',
            'status' => 'draft',
            'subject' => 'Commercial terms for the ramp',
            'body' => 'Draft body.',
            'to_address' => 'buyer@bpo.test',
        ])->assertCreated();

        $sentId = $created->json('data.id');

        $this->actingAs($rep, 'api')
            ->postJson("/api/v1/communications/{$sentId}/send")
            ->assertOk();

        $this->artisan('communication:sync-inbound')->assertSuccessful();

        $pulled = Communication::where('in_reply_to_id', $sentId)->firstOrFail();

        // A pulled reply is a reply: it joins the conversation it answers, exactly as one
        // composed in the app does.
        $this->assertSame($sentId, $pulled->thread_id);
        $this->assertSame($sentId, Communication::findOrFail($sentId)->thread_id);

        $this->actingAs($rep, 'api')
            ->getJson("/api/v1/communications/threads/{$sentId}")
            ->assertOk()
            ->assertJsonPath('data.message_count', 2)
            ->assertJsonPath('data.messages.1.id', $pulled->id);
    }
}
