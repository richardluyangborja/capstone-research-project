<?php

namespace Tests\Feature;

use App\Enums\CommunicationDirection;
use App\Enums\CommunicationStatus;
use App\Integrations\EmailGateway;
use App\Integrations\MockEmailGateway;
use App\Models\Communication;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Opportunity;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

/**
 * Slice S2 — the outbound email path: a draft goes to the mocked gateway, a rejected send is
 * retryable, and the replies the mailbox holds are pulled back onto the record they answer
 * (spec 11 §S2, ADR-0017 §4).
 */
class CommunicationEmailTest extends TestCase
{
    use RefreshDatabase;

    private function dealFor(User $owner): Opportunity
    {
        return Opportunity::factory()
            ->forCompany(Company::factory()->ownedBy($owner)->create())
            ->ownedBy($owner)
            ->create();
    }

    /**
     * Compose a draft through the API and send it, the way the UI does it.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function sendDraft(User $author, Opportunity $deal, string $subject, array $overrides = []): Communication
    {
        $created = $this->actingAs($author, 'api')
            ->postJson('/api/v1/communications', array_merge([
                'subject_type' => 'opportunity',
                'subject_id' => $deal->id,
                'channel' => 'email',
                'direction' => 'outbound',
                'status' => 'draft',
                'subject' => $subject,
                'body' => 'Body of: '.$subject,
                'to_address' => 'buyer@sunrise.test',
            ], $overrides))
            ->assertCreated();

        $id = $created->json('data.id');

        $this->actingAs($author, 'api')
            ->postJson("/api/v1/communications/{$id}/send")
            ->assertOk();

        return Communication::query()->findOrFail($id);
    }

    public function test_an_outbound_email_can_be_written_as_a_draft_without_touching_the_timeline(): void
    {
        $rep = User::factory()->salesrep()->create();
        $deal = $this->dealFor($rep);
        $deal->last_activity_at = now()->subDays(30);
        $deal->save();

        $response = $this->actingAs($rep, 'api')
            ->postJson('/api/v1/communications', [
                'subject_type' => 'opportunity',
                'subject_id' => $deal->id,
                'channel' => 'email',
                'direction' => 'outbound',
                'status' => 'draft',
                'subject' => 'Revised rate card',
                'body' => 'Attached, as promised.',
                'to_address' => 'buyer@sunrise.test',
            ])
            ->assertCreated()
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.can_send', true);

        // Nothing has happened yet, so there is no timeline row and the aging clock holds.
        $this->assertDatabaseMissing('activities', ['communication_id' => $response->json('data.id')]);
        $this->assertTrue(
            $deal->fresh()->last_activity_at->lessThan(now()->subDays(29)),
        );
    }

    public function test_only_an_outbound_email_can_be_a_draft(): void
    {
        $rep = User::factory()->salesrep()->create();
        $company = Company::factory()->ownedBy($rep)->create();

        // A call is not written and sent later.
        $this->actingAs($rep, 'api')
            ->postJson('/api/v1/communications', [
                'subject_type' => 'company',
                'subject_id' => $company->id,
                'channel' => 'call',
                'direction' => 'outbound',
                'status' => 'draft',
                'subject' => 'Ringing them now',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('status');

        // Neither is something the client sent us.
        $this->actingAs($rep, 'api')
            ->postJson('/api/v1/communications', [
                'subject_type' => 'company',
                'subject_id' => $company->id,
                'channel' => 'email',
                'direction' => 'inbound',
                'status' => 'draft',
                'subject' => 'Their enquiry',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('status');
    }

    public function test_a_sent_draft_reaches_the_gateway_and_restarts_the_records_clock(): void
    {
        $rep = User::factory()->salesrep()->create();
        $deal = $this->dealFor($rep);
        $deal->last_activity_at = now()->subDays(30);
        $deal->save();

        $created = $this->actingAs($rep, 'api')
            ->postJson('/api/v1/communications', [
                'subject_type' => 'opportunity',
                'subject_id' => $deal->id,
                'channel' => 'email',
                'direction' => 'outbound',
                'status' => 'draft',
                'subject' => 'Revised rate card',
                'body' => 'Attached, as promised.',
                'to_address' => 'buyer@sunrise.test',
            ])
            ->assertCreated();

        $id = $created->json('data.id');
        $reference = $created->json('data.reference');

        $this->actingAs($rep, 'api')
            ->postJson("/api/v1/communications/{$id}/send")
            ->assertOk()
            ->assertJsonPath('data.status', 'sent')
            ->assertJsonPath('data.external_id', MockEmailGateway::externalIdFor($reference))
            ->assertJsonPath('meta.source', 'Email (mocked)');

        // A sent message is history: it is on the timeline and the deal's clock moved.
        $this->assertDatabaseHas('activities', [
            'communication_id' => $id,
            'type' => 'email',
            'subject' => 'Revised rate card',
        ]);
        $this->assertTrue($deal->fresh()->last_activity_at->greaterThan(now()->subMinute()));

        // Having gone out, it is a record of what was said — no longer the author's to rewrite.
        $this->actingAs($rep, 'api')
            ->patchJson("/api/v1/communications/{$id}", ['subject' => 'Rewritten'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('status');
    }

    public function test_a_rejected_send_is_kept_with_its_reason_and_a_retry_sends_it(): void
    {
        Config::set('crm.integrations.email.simulate_failure', true);

        $rep = User::factory()->salesrep()->create();
        $deal = $this->dealFor($rep);

        $created = $this->actingAs($rep, 'api')
            ->postJson('/api/v1/communications', [
                'subject_type' => 'opportunity',
                'subject_id' => $deal->id,
                'channel' => 'email',
                'direction' => 'outbound',
                'status' => 'draft',
                'subject' => 'Rate card',
                'body' => 'As discussed.',
                'to_address' => 'buyer@sunrise.test',
            ])
            ->assertCreated();

        $id = $created->json('data.id');

        // The gateway rejects it, but that is a delivery outcome, not an HTTP error.
        $this->actingAs($rep, 'api')
            ->postJson("/api/v1/communications/{$id}/send")
            ->assertOk()
            ->assertJsonPath('data.status', 'failed')
            ->assertJsonPath('data.external_id', null)
            ->assertJsonPath('data.can_send', true)
            ->assertJsonPath('meta.source', 'Email (mocked)');

        $this->assertNotNull(Communication::query()->findOrFail($id)->failed_reason);

        // The failed attempt still shows on the timeline: the rep tried to reach them.
        $this->assertDatabaseHas('activities', ['communication_id' => $id]);

        Config::set('crm.integrations.email.simulate_failure', false);

        $this->actingAs($rep, 'api')
            ->postJson("/api/v1/communications/{$id}/retry")
            ->assertOk()
            ->assertJsonPath('data.status', 'sent')
            ->assertJsonPath('data.failed_reason', null)
            ->assertJsonPath('meta.source', 'Email (mocked)');

        $this->assertSame(
            MockEmailGateway::externalIdFor($created->json('data.reference')),
            Communication::query()->findOrFail($id)->external_id,
        );
    }

    public function test_sending_requires_a_draft_and_retrying_requires_a_rejected_send(): void
    {
        $rep = User::factory()->salesrep()->create();
        $deal = $this->dealFor($rep);

        $sent = $this->sendDraft($rep, $deal, 'Already out');
        $draft = Communication::factory()->about($deal, $rep)->status(CommunicationStatus::Draft)->create();
        $call = Communication::factory()->about($deal, $rep)->call()->create();

        $this->actingAs($rep, 'api')
            ->postJson("/api/v1/communications/{$sent->id}/send")
            ->assertStatus(422)
            ->assertJsonValidationErrors('status');

        $this->actingAs($rep, 'api')
            ->postJson("/api/v1/communications/{$draft->id}/retry")
            ->assertStatus(422)
            ->assertJsonValidationErrors('status');

        // A call never goes near the mail gateway, even in a state the email path accepts.
        $this->actingAs($rep, 'api')
            ->postJson("/api/v1/communications/{$call->id}/send")
            ->assertStatus(422)
            ->assertJsonValidationErrors('status');
    }

    public function test_sending_needs_a_recipient_and_takes_the_contact_when_none_was_typed(): void
    {
        $rep = User::factory()->salesrep()->create();
        $company = Company::factory()->ownedBy($rep)->create();
        $contact = Contact::factory()->forCompany($company)->create(['email' => 'hr@sunrise.test']);
        $deal = Opportunity::factory()->forCompany($company)->ownedBy($rep)->create();

        // No address, nobody to fall back on: a draft is fine to keep, sending it is not.
        $bare = $this->actingAs($rep, 'api')
            ->postJson('/api/v1/communications', [
                'subject_type' => 'opportunity',
                'subject_id' => $deal->id,
                'channel' => 'email',
                'direction' => 'outbound',
                'status' => 'draft',
                'subject' => 'Nobody to send it to',
            ])
            ->assertCreated();

        $this->actingAs($rep, 'api')
            ->postJson("/api/v1/communications/{$bare->json('data.id')}/send")
            ->assertStatus(422)
            ->assertJsonValidationErrors('to_address');

        // With a contact picked, the address is taken from the person.
        $withContact = $this->actingAs($rep, 'api')
            ->postJson('/api/v1/communications', [
                'subject_type' => 'opportunity',
                'subject_id' => $deal->id,
                'contact_id' => $contact->id,
                'channel' => 'email',
                'direction' => 'outbound',
                'status' => 'draft',
                'subject' => 'Sent to the contact',
                'body' => 'Hello.',
            ])
            ->assertCreated();

        $id = $withContact->json('data.id');

        $this->actingAs($rep, 'api')
            ->postJson("/api/v1/communications/{$id}/send")
            ->assertOk()
            ->assertJsonPath('data.status', 'sent')
            ->assertJsonPath('data.to_address', 'hr@sunrise.test');
    }

    public function test_sending_is_work_on_the_record_so_it_needs_rights_on_it(): void
    {
        $rep = User::factory()->salesrep()->create();
        $other = User::factory()->salesrep()->create();
        $deal = $this->dealFor($rep);
        $draft = Communication::factory()->about($deal, $rep)->status(CommunicationStatus::Draft)->create();

        $this->actingAs($other, 'api')
            ->postJson("/api/v1/communications/{$draft->id}/send")
            ->assertForbidden();

        $this->actingAs($other, 'api')
            ->getJson("/api/v1/communications/{$draft->id}")
            ->assertForbidden();

        $this->assertSame(CommunicationStatus::Draft, $draft->fresh()->status);
    }

    public function test_the_mocked_mailbox_pulls_a_reply_back_onto_the_record_it_answers(): void
    {
        $rep = User::factory()->salesrep()->create();
        $deal = $this->dealFor($rep);
        $sent = $this->sendDraft($rep, $deal, 'Commercial terms');

        $this->artisan('communication:sync-inbound')->assertSuccessful();

        $reply = Communication::query()
            ->where('direction', CommunicationDirection::Inbound)
            ->where('external_id', MockEmailGateway::replyExternalIdFor($sent->reference))
            ->first();

        $this->assertNotNull($reply);
        $this->assertSame(CommunicationStatus::Received, $reply->status);
        $this->assertSame($deal->id, $reply->subject_id);
        $this->assertSame('Re: Commercial terms', $reply->subject);
        $this->assertSame($rep->id, $reply->owner_id);
        $this->assertDatabaseHas('activities', ['communication_id' => $reply->id]);

        // Pulling again finds nothing new: the same reply is not recorded twice.
        $this->artisan('communication:sync-inbound')->assertSuccessful();
        $this->assertSame(1, Communication::query()->where('external_id', $reply->external_id)->count());
    }

    public function test_the_pull_drops_a_reply_it_cannot_place(): void
    {
        $rep = User::factory()->salesrep()->create();
        $deal = $this->dealFor($rep);

        // A sent message with no gateway id is one the mailbox cannot have answered.
        Communication::factory()->about($deal, $rep)->create([
            'status' => CommunicationStatus::Sent,
            'external_id' => null,
        ]);

        $this->assertSame(0, Communication::pullInbound($this->app->make(EmailGateway::class)));
        $this->assertSame(0, Communication::query()->where('direction', CommunicationDirection::Inbound)->count());
    }

    public function test_pulling_the_mailbox_is_an_admin_action(): void
    {
        $rep = User::factory()->salesrep()->create();
        $manager = User::factory()->manager()->create();
        $admin = User::factory()->admin()->create();

        $this->actingAs($rep, 'api')
            ->postJson('/api/v1/communications/sync-inbound')
            ->assertForbidden();

        $this->actingAs($manager, 'api')
            ->postJson('/api/v1/communications/sync-inbound')
            ->assertForbidden();

        $this->actingAs($admin, 'api')
            ->postJson('/api/v1/communications/sync-inbound')
            ->assertOk()
            ->assertJsonPath('data.recorded', 0)
            ->assertJsonPath('meta.source', 'Email (mocked)');
    }

    public function test_the_payload_says_whether_the_caller_may_send_this_message(): void
    {
        $rep = User::factory()->salesrep()->create();
        $other = User::factory()->salesrep()->create();
        $deal = $this->dealFor($rep);

        $draft = Communication::factory()->about($deal, $rep)->status(CommunicationStatus::Draft)->create();
        $sent = Communication::factory()->about($deal, $rep)->create();
        $call = Communication::factory()->about($deal, $rep)->call()->create();

        $this->actingAs($rep, 'api')
            ->getJson("/api/v1/communications/{$draft->id}")
            ->assertOk()
            ->assertJsonPath('data.can_send', true);

        $this->actingAs($rep, 'api')
            ->getJson("/api/v1/communications/{$sent->id}")
            ->assertOk()
            ->assertJsonPath('data.can_send', false);

        $this->actingAs($rep, 'api')
            ->getJson("/api/v1/communications/{$call->id}")
            ->assertOk()
            ->assertJsonPath('data.can_send', false);

        // The message is a draft, but the record it hangs off is not the caller's to work on.
        $this->actingAs($other, 'api')
            ->getJson("/api/v1/communications/{$draft->id}")
            ->assertForbidden();
    }
}
