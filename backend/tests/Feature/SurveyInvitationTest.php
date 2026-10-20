<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Contact;
use App\Models\Survey;
use App\Models\SurveyInvitation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Slice S2 — sending a survey and the invitation book (spec 13 §S2, ADR-0019 §3–5).
 *
 * A send is a company action: it needs that account's `update` right, it goes through the mocked
 * gateway, and it leaves a real `Communication` behind. A rejection is a state (`failed`) that
 * `resend` retries, never an HTTP error; an invitation is cancelled or resent, never deleted.
 */
class SurveyInvitationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * An active survey with the three shapes the check-in asks: a rating, the promoter question
     * and a free-text note.
     */
    private function survey(User $author): Survey
    {
        return Survey::factory()
            ->active()
            ->authoredBy($author)
            ->withQuestions([
                ['type' => 'rating', 'prompt' => 'How satisfied are you with the deployment?'],
                ['type' => 'nps', 'prompt' => 'How likely are you to recommend us?'],
                ['type' => 'text', 'prompt' => 'Anything we should know?', 'required' => false],
            ])
            ->create(['name' => 'Post-deployment check-in']);
    }

    /**
     * @return array<string, mixed>
     */
    private function sendPayload(Company $company, ?Contact $contact = null, array $overrides = []): array
    {
        return $overrides + [
            'company_id' => $company->id,
            'contact_id' => $contact?->id,
            'recipient_email' => $contact === null ? 'client@example.test' : null,
            'message' => 'A quick check-in now that the crew has settled in.',
        ];
    }

    public function test_a_manager_sends_a_survey_and_the_email_becomes_history(): void
    {
        $manager = User::factory()->manager()->create();
        $company = Company::factory()->client()->ownedBy($manager)->create();
        $contact = Contact::factory()->forCompany($company)->primary()->create(['email' => 'grace@harbor.test']);
        $survey = $this->survey($manager);

        $response = $this->actingAs($manager, 'api')
            ->postJson("/api/v1/surveys/{$survey->id}/invitations", $this->sendPayload($company, $contact))
            ->assertCreated()
            ->assertJsonPath('data.state', 'sent')
            ->assertJsonPath('data.status', 'sent')
            ->assertJsonPath('data.recipient_name', $contact->name)
            ->assertJsonPath('data.recipient_email', 'grace@harbor.test')
            ->assertJsonPath('data.company.name', $company->name)
            ->assertJsonPath('data.survey.name', 'Post-deployment check-in')
            ->assertJsonPath('data.can_cancel', true)
            ->assertJsonPath('data.can_resend', true);

        $invitation = SurveyInvitation::query()->sole();

        $this->assertMatchesRegularExpression('/^SRV-\d{4}-\d{4}$/', (string) $invitation->reference);
        $this->assertSame(48, strlen($invitation->token));
        $this->assertSame($company->id, $invitation->company_id);
        $this->assertNotNull($invitation->communication_id);
        $this->assertNotNull($invitation->expires_at);

        $communication = $invitation->communication;

        $this->assertSame('sent', $communication?->status->value);
        $this->assertSame('grace@harbor.test', $communication?->to_address);
        $this->assertNotNull($communication?->external_id);
        $this->assertStringContainsString($invitation->publicUrl(), (string) $communication?->body);

        $response->assertJsonPath('data.public_url', $invitation->publicUrl());
    }

    public function test_the_public_link_comes_from_configuration_and_not_the_request_host(): void
    {
        config(['crm.surveys.public_url' => 'https://crm.primepower.com']);

        $manager = User::factory()->manager()->create();
        $company = Company::factory()->ownedBy($manager)->create();
        $contact = Contact::factory()->forCompany($company)->create();
        $survey = $this->survey($manager);

        $response = $this->actingAs($manager, 'api')
            ->withHeader('Host', 'localhost:8000')
            ->postJson("/api/v1/surveys/{$survey->id}/invitations", $this->sendPayload($company, $contact))
            ->assertCreated();

        $url = (string) $response->json('data.public_url');

        $this->assertStringStartsWith('https://crm.primepower.com/survey/', $url);
    }

    public function test_a_forced_gateway_failure_is_a_delivery_outcome_and_resend_retries_it(): void
    {
        config(['crm.integrations.email.simulate_failure' => true]);

        $manager = User::factory()->manager()->create();
        $company = Company::factory()->ownedBy($manager)->create();
        $contact = Contact::factory()->forCompany($company)->create();
        $survey = $this->survey($manager);

        $this->actingAs($manager, 'api')
            ->postJson("/api/v1/surveys/{$survey->id}/invitations", $this->sendPayload($company, $contact))
            ->assertCreated()
            ->assertJsonPath('data.state', 'failed')
            ->assertJsonPath('data.status', 'failed');

        $invitation = SurveyInvitation::query()->sole();

        $this->assertNotNull($invitation->failed_reason);
        $this->assertSame('failed', $invitation->communication?->status->value);
        // A failed link never reached the client, so there is nothing to copy.
        $this->actingAs($manager, 'api')
            ->getJson("/api/v1/survey-invitations/{$invitation->id}")
            ->assertOk()
            ->assertJsonMissingPath('data.public_url');

        config(['crm.integrations.email.simulate_failure' => false]);

        $this->actingAs($manager, 'api')
            ->postJson("/api/v1/survey-invitations/{$invitation->id}/resend")
            ->assertOk()
            ->assertJsonPath('data.state', 'sent')
            ->assertJsonPath('data.failed_reason', null);

        $this->assertSame('sent', $invitation->fresh()?->communication?->status->value);
    }

    public function test_sending_needs_the_accounts_update_right(): void
    {
        $owner = User::factory()->salesrep()->create();
        $other = User::factory()->salesrep()->create();
        $company = Company::factory()->ownedBy($owner)->create();
        $survey = $this->survey(User::factory()->manager()->create());

        $this->actingAs($other, 'api')
            ->postJson("/api/v1/surveys/{$survey->id}/invitations", $this->sendPayload($company))
            ->assertForbidden();

        $this->assertSame(0, SurveyInvitation::query()->count());
    }

    public function test_an_inactive_or_empty_survey_cannot_be_sent(): void
    {
        $manager = User::factory()->manager()->create();
        $company = Company::factory()->ownedBy($manager)->create();

        $draft = Survey::factory()->authoredBy($manager)->withQuestions([
            ['type' => 'rating', 'prompt' => 'How was it?'],
        ])->create();

        $this->actingAs($manager, 'api')
            ->postJson("/api/v1/surveys/{$draft->id}/invitations", $this->sendPayload($company))
            ->assertStatus(422)
            ->assertJsonValidationErrors('survey_id');

        $empty = Survey::factory()->active()->authoredBy($manager)->create();

        $this->actingAs($manager, 'api')
            ->postJson("/api/v1/surveys/{$empty->id}/invitations", $this->sendPayload($company))
            ->assertStatus(422)
            ->assertJsonValidationErrors('survey_id');
    }

    public function test_a_send_needs_somewhere_to_go_and_a_contact_of_the_same_account(): void
    {
        $manager = User::factory()->manager()->create();
        $company = Company::factory()->ownedBy($manager)->create();
        $elsewhere = Company::factory()->create();
        $stranger = Contact::factory()->forCompany($elsewhere)->create();
        $survey = $this->survey($manager);

        $this->actingAs($manager, 'api')
            ->postJson("/api/v1/surveys/{$survey->id}/invitations", [
                'company_id' => $company->id,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('recipient_email');

        $this->actingAs($manager, 'api')
            ->postJson("/api/v1/surveys/{$survey->id}/invitations", [
                'company_id' => $company->id,
                'contact_id' => $stranger->id,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('contact_id');

        // A typed address is enough when the account has no contact with an email on file.
        $this->actingAs($manager, 'api')
            ->postJson("/api/v1/surveys/{$survey->id}/invitations", [
                'company_id' => $company->id,
                'recipient_name' => 'Grace Lim',
                'recipient_email' => 'grace@client.test',
            ])
            ->assertCreated()
            ->assertJsonPath('data.recipient_email', 'grace@client.test');
    }

    public function test_once_sent_the_question_set_is_frozen_while_the_wording_stays_editable(): void
    {
        $manager = User::factory()->manager()->create();
        $company = Company::factory()->ownedBy($manager)->create();
        $survey = $this->survey($manager);

        $this->actingAs($manager, 'api')
            ->postJson("/api/v1/surveys/{$survey->id}/invitations", $this->sendPayload($company))
            ->assertCreated();

        $this->actingAs($manager, 'api')
            ->getJson("/api/v1/surveys/{$survey->id}")
            ->assertOk()
            ->assertJsonPath('data.questions_editable', false)
            ->assertJsonPath('data.invitations_count', 1);

        $this->actingAs($manager, 'api')
            ->patchJson("/api/v1/surveys/{$survey->id}", [
                'questions' => [
                    ['type' => 'rating', 'prompt' => 'A new question?'],
                ],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('questions');

        $this->actingAs($manager, 'api')
            ->patchJson("/api/v1/surveys/{$survey->id}", ['name' => 'Post-deployment check-in (2026)'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Post-deployment check-in (2026)');
    }

    public function test_the_book_is_scoped_to_the_callers_accounts_and_filters_on_the_derived_state(): void
    {
        $manager = User::factory()->manager()->create();
        $rep = User::factory()->salesrep()->create();
        $survey = $this->survey($manager);

        $own = Company::factory()->ownedBy($rep)->create();
        $other = Company::factory()->ownedBy(User::factory()->salesrep()->create())->create();

        SurveyInvitation::factory()->forRecord($survey, $own)->create(['created_by' => $rep->id]);
        SurveyInvitation::factory()->forRecord($survey, $own)->expired()->create(['created_by' => $rep->id]);
        SurveyInvitation::factory()->forRecord($survey, $other)->create();

        $this->actingAs($rep, 'api')
            ->getJson('/api/v1/survey-invitations')
            ->assertOk()
            ->assertJsonPath('meta.total', 2);

        $this->actingAs($rep, 'api')
            ->getJson('/api/v1/survey-invitations?filter[state]=expired')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.state', 'expired');
    }

    public function test_cancelling_stops_the_link_and_a_cancelled_invitation_cannot_be_resent(): void
    {
        $manager = User::factory()->manager()->create();
        $company = Company::factory()->ownedBy($manager)->create();
        $survey = $this->survey($manager);

        $this->actingAs($manager, 'api')
            ->postJson("/api/v1/surveys/{$survey->id}/invitations", $this->sendPayload($company, null, [
                'recipient_email' => 'grace@client.test',
            ]))
            ->assertCreated();

        $invitation = SurveyInvitation::query()->sole();

        $this->getJson("/api/v1/public/surveys/{$invitation->token}")->assertOk();

        $this->actingAs($manager, 'api')
            ->postJson("/api/v1/survey-invitations/{$invitation->id}/cancel")
            ->assertOk()
            ->assertJsonPath('data.state', 'cancelled')
            ->assertJsonPath('data.can_cancel', false)
            ->assertJsonPath('data.can_resend', false);

        $this->getJson("/api/v1/public/surveys/{$invitation->token}")->assertStatus(410);

        $this->actingAs($manager, 'api')
            ->postJson("/api/v1/survey-invitations/{$invitation->id}/resend")
            ->assertStatus(422)
            ->assertJsonValidationErrors('status');
    }

    public function test_a_response_cannot_be_cancelled_and_a_salesrep_cannot_withdraw_someone_elses_send(): void
    {
        $manager = User::factory()->manager()->create();
        $rep = User::factory()->salesrep()->create();
        $company = Company::factory()->ownedBy($rep)->create();
        $survey = $this->survey($manager);

        $submitted = SurveyInvitation::factory()->forRecord($survey, $company)->submitted()->create([
            'created_by' => $rep->id,
        ]);
        $byManager = SurveyInvitation::factory()->forRecord($survey, $company)->create([
            'created_by' => $manager->id,
        ]);

        $this->actingAs($rep, 'api')
            ->postJson("/api/v1/survey-invitations/{$submitted->id}/cancel")
            ->assertStatus(422)
            ->assertJsonValidationErrors('status');

        $this->actingAs($rep, 'api')
            ->postJson("/api/v1/survey-invitations/{$byManager->id}/cancel")
            ->assertForbidden();
    }

    public function test_the_account_tab_lists_its_sends_and_respects_the_account_scope(): void
    {
        $manager = User::factory()->manager()->create();
        $rep = User::factory()->salesrep()->create();
        $survey = $this->survey($manager);
        $company = Company::factory()->ownedBy($rep)->create();

        SurveyInvitation::factory()->forRecord($survey, $company)->create(['created_by' => $rep->id]);
        SurveyInvitation::factory()->forRecord($survey, $company)->expired()->create(['created_by' => $rep->id]);

        $this->actingAs($rep, 'api')
            ->getJson("/api/v1/companies/{$company->id}/survey-invitations")
            ->assertOk()
            ->assertJsonPath('meta.total', 2);

        $this->actingAs($rep, 'api')
            ->getJson("/api/v1/companies/{$company->id}/survey-invitations?filter[state]=expired")
            ->assertOk()
            ->assertJsonPath('meta.total', 1);

        $this->actingAs(User::factory()->salesrep()->create(), 'api')
            ->getJson("/api/v1/companies/{$company->id}/survey-invitations")
            ->assertForbidden();
    }
}
