<?php

namespace Tests\Feature;

use App\Enums\CommunicationChannel;
use App\Enums\CommunicationDirection;
use App\Enums\CommunicationStatus;
use App\Models\Communication;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Contract;
use App\Models\Lead;
use App\Models\Opportunity;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CommunicationTest extends TestCase
{
    use RefreshDatabase;

    private function dealFor(User $owner): Opportunity
    {
        return Opportunity::factory()
            ->forCompany(Company::factory()->ownedBy($owner)->create())
            ->ownedBy($owner)
            ->create();
    }

    public function test_a_communication_can_be_logged_against_a_deal(): void
    {
        $rep = User::factory()->salesrep()->create();
        $deal = $this->dealFor($rep);

        $response = $this->actingAs($rep, 'api')
            ->postJson('/api/v1/communications', [
                'subject_type' => 'opportunity',
                'subject_id' => $deal->id,
                'channel' => 'email',
                'direction' => 'outbound',
                'subject' => 'Revised rate sheet',
                'body' => 'Rates hold for 30 days.',
                'occurred_at' => now()->subDay()->toIso8601String(),
            ])
            ->assertCreated()
            ->assertJsonPath('data.subject_type', 'opportunity')
            ->assertJsonPath('data.subject_id', $deal->id)
            ->assertJsonPath('data.channel', 'email')
            ->assertJsonPath('data.direction', 'outbound')
            // An electronic outbound message defaults to `sent`.
            ->assertJsonPath('data.status', 'sent')
            ->assertJsonPath('data.record.type', 'opportunity')
            ->assertJsonPath('data.record.label', $deal->name)
            ->assertJsonPath('data.owner.id', $rep->id)
            ->assertJsonPath('data.is_editable', false)
            ->assertJsonPath('data.can_update', true);

        $this->assertMatchesRegularExpression(
            '/^COM-\d{4}-\d{4}$/',
            (string) $response->json('data.reference'),
        );

        $communication = Communication::findOrFail($response->json('data.id'));

        // The mirror lands on the deal's existing timeline and points back at the record.
        $this->assertDatabaseHas('activities', [
            'communication_id' => $communication->id,
            'subject_type' => Opportunity::class,
            'subject_id' => $deal->id,
            'type' => 'email',
            'subject' => 'Revised rate sheet',
        ]);

        // Logging a touch restarts the deal's aging clock.
        $this->assertNotNull($deal->refresh()->last_activity_at);
    }

    public function test_a_communication_can_be_logged_against_every_kind_of_record(): void
    {
        $rep = User::factory()->salesrep()->create();
        $company = Company::factory()->ownedBy($rep)->create();
        $contact = Contact::factory()->forCompany($company)->create();
        $deal = Opportunity::factory()->forCompany($company)->ownedBy($rep)->create();
        $contract = Contract::factory()->forCompany($company)->create();
        $lead = Lead::factory()->create(['owner_id' => $rep->id]);

        $subjects = [
            'lead' => $lead->id,
            'company' => $company->id,
            'contact' => $contact->id,
            'opportunity' => $deal->id,
            'contract' => $contract->id,
        ];

        foreach ($subjects as $type => $id) {
            $this->actingAs($rep, 'api')
                ->postJson('/api/v1/communications', [
                    'subject_type' => $type,
                    'subject_id' => $id,
                    'channel' => 'call',
                    'direction' => 'outbound',
                    'subject' => 'Intro call',
                ])
                ->assertCreated()
                // A call is recorded as it happened.
                ->assertJsonPath('data.status', 'logged')
                ->assertJsonPath('data.record.type', $type);
        }

        $this->assertSame(5, Communication::count());
    }

    public function test_an_inbound_message_is_received_by_default(): void
    {
        $rep = User::factory()->salesrep()->create();
        $deal = $this->dealFor($rep);

        $this->actingAs($rep, 'api')
            ->postJson('/api/v1/communications', [
                'subject_type' => 'opportunity',
                'subject_id' => $deal->id,
                'channel' => 'email',
                'direction' => 'inbound',
                'subject' => 'Re: Revised rate sheet',
            ])
            ->assertCreated()
            ->assertJsonPath('data.status', 'received');
    }

    public function test_logging_requires_a_subject_and_a_channel(): void
    {
        $rep = User::factory()->salesrep()->create();

        $this->actingAs($rep, 'api')
            ->postJson('/api/v1/communications', ['subject' => 'No subject record'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['subject_type', 'subject_id', 'channel', 'direction']);
    }

    public function test_logging_against_a_record_that_does_not_exist_is_rejected(): void
    {
        $rep = User::factory()->salesrep()->create();

        $this->actingAs($rep, 'api')
            ->postJson('/api/v1/communications', [
                'subject_type' => 'company',
                'subject_id' => str_repeat('0', 26),
                'channel' => 'call',
                'direction' => 'outbound',
                'subject' => 'Ghost call',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('subject_id');
    }

    public function test_a_scheduled_status_belongs_to_the_follow_up_module(): void
    {
        $rep = User::factory()->salesrep()->create();
        $deal = $this->dealFor($rep);

        $this->actingAs($rep, 'api')
            ->postJson('/api/v1/communications', [
                'subject_type' => 'opportunity',
                'subject_id' => $deal->id,
                'channel' => 'email',
                'direction' => 'outbound',
                'status' => 'scheduled',
                'subject' => 'Tomorrow follow-up',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('status');
    }

    public function test_a_communication_cannot_be_logged_on_a_record_outside_scope(): void
    {
        $rep = User::factory()->salesrep()->create();
        $other = User::factory()->salesrep()->create();
        $theirDeal = $this->dealFor($other);

        $this->actingAs($rep, 'api')
            ->postJson('/api/v1/communications', [
                'subject_type' => 'opportunity',
                'subject_id' => $theirDeal->id,
                'channel' => 'call',
                'direction' => 'outbound',
                'subject' => 'Not my deal',
            ])
            ->assertForbidden();

        // A manager covers their team, so the same deal is fair game.
        $team = Team::factory()->create();
        $other->update(['team_id' => $team->id]);
        $manager = User::factory()->manager()->create(['team_id' => $team->id]);

        $this->actingAs($manager, 'api')
            ->postJson('/api/v1/communications', [
                'subject_type' => 'opportunity',
                'subject_id' => $theirDeal->id,
                'channel' => 'call',
                'direction' => 'outbound',
                'subject' => 'Check-in call',
            ])
            ->assertCreated();
    }

    public function test_a_sent_message_is_immutable_and_a_logged_one_is_the_authors_to_correct(): void
    {
        $rep = User::factory()->salesrep()->create();
        $other = User::factory()->salesrep()->create();
        $deal = $this->dealFor($rep);

        $sent = Communication::factory()->about($deal, $rep)->create([
            'channel' => CommunicationChannel::Email,
            'direction' => CommunicationDirection::Outbound,
            'status' => CommunicationStatus::Sent,
        ]);

        $logged = Communication::factory()->about($deal, $rep)->call()->create();

        $this->actingAs($rep, 'api')
            ->patchJson("/api/v1/communications/{$sent->id}", ['subject' => 'Rewritten'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('status');

        // Someone else may not touch the author's record.
        $this->actingAs($other, 'api')
            ->patchJson("/api/v1/communications/{$logged->id}", ['subject' => 'Mine now'])
            ->assertForbidden();

        // The author corrects the note, and the timeline row follows.
        $this->actingAs($rep, 'api')
            ->patchJson("/api/v1/communications/{$logged->id}", ['subject' => 'Corrected call note'])
            ->assertOk()
            ->assertJsonPath('data.subject', 'Corrected call note');

        $this->assertDatabaseHas('activities', [
            'communication_id' => $logged->id,
            'subject' => 'Corrected call note',
        ]);
    }

    public function test_deleting_retires_the_mirror_and_restore_brings_both_back(): void
    {
        $rep = User::factory()->salesrep()->create();
        $deal = $this->dealFor($rep);
        $communication = Communication::factory()->about($deal, $rep)->call()->create();

        $this->actingAs($rep, 'api')
            ->deleteJson("/api/v1/communications/{$communication->id}")
            ->assertNoContent();

        $this->assertSoftDeleted('communications', ['id' => $communication->id]);
        $this->assertDatabaseMissing('activities', ['communication_id' => $communication->id]);

        // A salesrep may not restore — that is a manager+ action.
        $this->actingAs($rep, 'api')
            ->postJson("/api/v1/communications/{$communication->id}/restore")
            ->assertForbidden();

        $manager = User::factory()->manager()->create([
            'team_id' => $rep->team_id ?? Team::factory()->create()->id,
        ]);
        $rep->update(['team_id' => $manager->team_id]);

        $this->actingAs($manager, 'api')
            ->postJson("/api/v1/communications/{$communication->id}/restore")
            ->assertOk();

        $this->assertNotSoftDeleted('communications', ['id' => $communication->id]);
        $this->assertDatabaseHas('activities', ['communication_id' => $communication->id]);
    }

    public function test_the_book_filters_summarizes_and_scopes_by_subject_visibility(): void
    {
        $rep = User::factory()->salesrep()->create();
        $other = User::factory()->salesrep()->create();
        $company = Company::factory()->ownedBy($rep)->create();
        $theirCompany = Company::factory()->ownedBy($other)->create();

        Communication::factory()->about($company, $rep)->create([
            'direction' => CommunicationDirection::Outbound,
            'channel' => CommunicationChannel::Email,
            'subject' => 'Rate sheet',
            'occurred_at' => now()->subDays(2),
        ]);
        Communication::factory()->about($company, $rep)->inbound()->create([
            'channel' => CommunicationChannel::Email,
            'subject' => 'Re: Rate sheet',
            'occurred_at' => now()->subDay(),
        ]);
        Communication::factory()->about($company, $rep)->call()->create([
            'occurred_at' => now()->subDays(3),
        ]);
        Communication::factory()->about($theirCompany, $other)->create();

        $this->actingAs($rep, 'api')
            ->getJson('/api/v1/communications')
            ->assertOk()
            ->assertJsonPath('summary.all', 3)
            ->assertJsonPath('summary.by_channel.email', 2)
            ->assertJsonPath('summary.by_channel.call', 1)
            ->assertJsonPath('summary.by_direction.outbound', 2)
            ->assertJsonPath('summary.by_direction.inbound', 1)
            ->assertJsonCount(3, 'data');

        $this->actingAs($rep, 'api')
            ->getJson('/api/v1/communications?filter[channel]=email')
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $this->actingAs($rep, 'api')
            ->getJson('/api/v1/communications?filter[direction]=inbound')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->actingAs($rep, 'api')
            ->getJson('/api/v1/communications?filter[date_from]='.now()->subDay()->toDateString())
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->actingAs($rep, 'api')
            ->getJson('/api/v1/communications?search=rate')
            ->assertOk()
            ->assertJsonCount(2, 'data');

        // An admin sees every record; the other rep sees none of this company's history.
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin, 'api')
            ->getJson('/api/v1/communications')
            ->assertOk()
            ->assertJsonPath('summary.all', 4);

        $this->actingAs($other, 'api')
            ->getJson("/api/v1/companies/{$company->id}/communications")
            ->assertForbidden();
    }

    public function test_the_nested_history_returns_only_that_records_messages(): void
    {
        $rep = User::factory()->salesrep()->create();
        $company = Company::factory()->ownedBy($rep)->create();
        $deal = Opportunity::factory()->forCompany($company)->ownedBy($rep)->create();
        $otherDeal = Opportunity::factory()->forCompany($company)->ownedBy($rep)->create();

        Communication::factory()->about($deal, $rep)->count(2)->create();
        Communication::factory()->about($otherDeal, $rep)->create();

        $this->actingAs($rep, 'api')
            ->getJson("/api/v1/opportunities/{$deal->id}/communications")
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('data.0.record.type', 'opportunity')
            ->assertJsonPath('data.0.record.id', $deal->id);

        $this->actingAs($rep, 'api')
            ->getJson('/api/v1/contracts/'.Contract::factory()->forCompany($company)->create()->id.'/communications')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_the_account_detail_counts_its_communication_history(): void
    {
        $rep = User::factory()->salesrep()->create();
        $company = Company::factory()->ownedBy($rep)->create();
        $deal = Opportunity::factory()->forCompany($company)->ownedBy($rep)->create();

        Communication::factory()->about($company, $rep)->count(2)->create();
        Communication::factory()->about($deal, $rep)->create();

        // Only the account's own history counts, not the messages on its deals.
        $this->actingAs($rep, 'api')
            ->getJson("/api/v1/companies/{$company->id}")
            ->assertOk()
            ->assertJsonPath('data.counts.communications', 2);
    }
}
