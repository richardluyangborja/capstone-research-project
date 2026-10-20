<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Contact;
use App\Models\Survey;
use App\Models\SurveyInvitation;
use App\Models\SurveyResponse;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Slice S3 — the response book, the satisfaction summary and the account card
 * (spec 13 §S3, ADR-0019 §6–9).
 *
 * Nothing is stored twice: CSAT is the mean of the per-response scores, NPS is the promoter
 * share minus the detractor share over the answers that carried a promoter question, the bands
 * are counts and the response rate pairs answers with the invitations that could be answered
 * (a failed or withdrawn send is not in the denominator). Every view is scoped by the account,
 * so a salesrep counts their own, a manager the team's, an admin everything.
 */
class SatisfactionViewTest extends TestCase
{
    use RefreshDatabase;

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
     * A scored answer set on this account, with the invitation that produced it.
     */
    private function answer(
        Company $company,
        Survey $survey,
        float $csat,
        ?int $nps,
        ?Contact $contact = null,
        ?Carbon $submittedAt = null,
    ): SurveyResponse {
        $invitation = SurveyInvitation::factory()
            ->forRecord($survey, $company, $contact)
            ->submitted()
            ->create();

        return SurveyResponse::factory()
            ->forInvitation($invitation)
            ->scored($csat, $nps)
            ->create([
                'submitted_at' => $submittedAt ?? now(),
                'needs_attention' => $csat <= (float) config('crm.surveys.attention_csat_max')
                    || ($nps !== null && $nps <= (int) config('crm.surveys.attention_nps_max')),
            ]);
    }

    public function test_the_summary_derives_csat_nps_bands_and_the_response_rate(): void
    {
        $manager = User::factory()->manager()->create();
        $company = Company::factory()->client()->ownedBy($manager)->create(['name' => 'Harbor Logistics']);
        $survey = $this->survey($manager);

        $promoter = $this->answer($company, $survey, 5.0, 10, submittedAt: now()->subDays(6));
        $detractor = $this->answer($company, $survey, 2.0, 4, submittedAt: now()->subDays(2));

        // Two more sends an answer could not come from: one never arrived, one was withdrawn.
        SurveyInvitation::factory()->forRecord($survey, $company)->failed()->create();
        SurveyInvitation::factory()->forRecord($survey, $company)->cancelled()->create();
        // And one still open — it belongs in the rate's denominator.
        SurveyInvitation::factory()->forRecord($survey, $company)->create();

        $this->actingAs($manager, 'api')
            ->getJson('/api/v1/satisfaction/summary')
            ->assertOk()
            ->assertJsonPath('data.responses', 2)
            ->assertJsonPath('data.csat', 3.5)
            ->assertJsonPath('data.nps', 0)
            ->assertJsonPath('data.promoters', 1)
            ->assertJsonPath('data.passives', 0)
            ->assertJsonPath('data.detractors', 1)
            ->assertJsonPath('data.answered', 2)
            ->assertJsonPath('data.attention', 1)
            ->assertJsonPath('data.invitations', 3)
            ->assertJsonPath('data.response_rate', 66.7)
            // Newest first, so the unhappy answer is the one a manager sees at the top.
            ->assertJsonPath('data.recent.0.id', $detractor->id)
            ->assertJsonPath('data.recent.1.id', $promoter->id)
            ->assertJsonPath('data.recent.0.company.name', 'Harbor Logistics')
            ->assertJsonPath('data.recent.0.band', 'detractor');
    }

    public function test_an_answer_without_the_promoter_question_counts_for_csat_but_not_nps(): void
    {
        $manager = User::factory()->manager()->create();
        $company = Company::factory()->ownedBy($manager)->create();
        $survey = $this->survey($manager);

        $this->answer($company, $survey, 5.0, 10);
        $this->answer($company, $survey, 4.0, null);

        $this->actingAs($manager, 'api')
            ->getJson('/api/v1/satisfaction/summary')
            ->assertOk()
            ->assertJsonPath('data.responses', 2)
            ->assertJsonPath('data.csat', 4.5)
            // Only the promoter answered the NPS question: one promoter out of one is 100.
            ->assertJsonPath('data.nps', 100)
            ->assertJsonPath('data.answered', 1)
            ->assertJsonPath('data.passives', 0);
    }

    public function test_the_summary_follows_the_callers_scope(): void
    {
        $team = (string) Str::ulid();
        $manager = User::factory()->manager()->create(['team_id' => $team]);
        $rep = User::factory()->salesrep()->create(['team_id' => $team]);
        $teammate = User::factory()->salesrep()->create(['team_id' => $team]);
        $stranger = User::factory()->salesrep()->create(['team_id' => (string) Str::ulid()]);

        $survey = $this->survey($manager);
        $own = Company::factory()->ownedBy($rep)->create();
        $sameTeam = Company::factory()->ownedBy($teammate)->create();
        $other = Company::factory()->ownedBy($stranger)->create();

        $this->answer($own, $survey, 5.0, 10);
        $this->answer($sameTeam, $survey, 2.0, 4);
        $this->answer($other, $survey, 1.0, 2);

        $this->actingAs($rep, 'api')
            ->getJson('/api/v1/satisfaction/summary')
            ->assertOk()
            ->assertJsonPath('data.responses', 1)
            ->assertJsonPath('data.csat', 5)
            ->assertJsonCount(1, 'data.recent');

        $this->actingAs($manager, 'api')
            ->getJson('/api/v1/satisfaction/summary')
            ->assertOk()
            ->assertJsonPath('data.responses', 2)
            ->assertJsonPath('data.csat', 3.5)
            ->assertJsonPath('data.attention', 1);

        $this->actingAs($stranger, 'api')
            ->getJson('/api/v1/satisfaction/summary')
            ->assertOk()
            ->assertJsonPath('data.responses', 1)
            ->assertJsonPath('data.csat', 1);
    }

    public function test_the_book_narrows_by_band_attention_dates_search_and_scope(): void
    {
        $team = (string) Str::ulid();
        $manager = User::factory()->manager()->create(['team_id' => $team]);
        $rep = User::factory()->salesrep()->create(['team_id' => $team]);
        $other = User::factory()->salesrep()->create(['team_id' => (string) Str::ulid()]);
        $survey = $this->survey($manager);

        $harbor = Company::factory()->ownedBy($rep)->create(['name' => 'Harbor Logistics']);
        $sunrise = Company::factory()->ownedBy($rep)->create(['name' => 'Sunrise Foods Inc']);

        $this->answer($harbor, $survey, 5.0, 10, submittedAt: now()->subDays(3));
        $this->answer($harbor, $survey, 2.0, 4, submittedAt: now()->subDays(2));
        $this->answer($sunrise, $survey, 4.0, 8, submittedAt: now()->subDay());

        $this->actingAs($manager, 'api')
            ->getJson('/api/v1/survey-responses')
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('data.0.company.name', 'Sunrise Foods Inc');

        $this->actingAs($manager, 'api')
            ->getJson('/api/v1/survey-responses?filter[band]=detractor')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.csat', 2);

        $this->actingAs($manager, 'api')
            ->getJson('/api/v1/survey-responses?filter[attention]=true')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.needs_attention', true);

        $this->actingAs($manager, 'api')
            ->getJson('/api/v1/survey-responses?search=sunrise')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.company.name', 'Sunrise Foods Inc');

        $this->actingAs($manager, 'api')
            ->getJson('/api/v1/survey-responses?filter[date_from]='.now()->addDay()->toDateString())
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->actingAs($manager, 'api')
            ->getJson('/api/v1/survey-responses?filter[date_to]='.now()->subDays(30)->toDateString())
            ->assertOk()
            ->assertJsonCount(0, 'data');

        // An answer belongs to whoever may see the account — not to everyone.
        $this->actingAs($other, 'api')
            ->getJson('/api/v1/survey-responses')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_the_detail_renders_the_answers_in_the_order_they_were_asked(): void
    {
        $manager = User::factory()->manager()->create();
        $stranger = User::factory()->salesrep()->create();
        $company = Company::factory()->ownedBy($manager)->create();
        $contact = Contact::factory()->forCompany($company)->primary()->create(['name' => 'Grace Lim']);
        $survey = $this->survey($manager);

        $invitation = SurveyInvitation::factory()->forRecord($survey, $company, $contact)->create();
        $response = SurveyResponse::record($invitation, [
            ['question_id' => $survey->questions[2]->id, 'value_text' => 'The crew settled in quickly.'],
            ['question_id' => $survey->questions[0]->id, 'value_number' => 5],
            ['question_id' => $survey->questions[1]->id, 'value_number' => 9],
        ]);

        $this->actingAs($manager, 'api')
            ->getJson("/api/v1/survey-responses/{$response->id}")
            ->assertOk()
            ->assertJsonPath('data.csat', 5)
            ->assertJsonPath('data.nps', 9)
            ->assertJsonPath('data.band', 'promoter')
            ->assertJsonPath('data.contact.name', 'Grace Lim')
            ->assertJsonPath('data.invitation.reference', $invitation->reference)
            // Asked order, not insertion order.
            ->assertJsonPath('data.answers.0.question.prompt', 'How satisfied are you with the deployment?')
            ->assertJsonPath('data.answers.0.value_number', 5)
            ->assertJsonPath('data.answers.1.question.type', 'nps')
            ->assertJsonPath('data.answers.2.value_text', 'The crew settled in quickly.');

        $this->actingAs($stranger, 'api')
            ->getJson("/api/v1/survey-responses/{$response->id}")
            ->assertForbidden();
    }

    public function test_the_account_card_shows_the_latest_answer_and_the_trend(): void
    {
        $manager = User::factory()->manager()->create();
        $stranger = User::factory()->salesrep()->create();
        $company = Company::factory()->client()->ownedBy($manager)->create();
        $survey = $this->survey($manager);

        $this->answer($company, $survey, 2.0, 4, submittedAt: now()->subDays(10));
        $this->answer($company, $survey, 5.0, 10, submittedAt: now()->subDay());

        $this->actingAs($manager, 'api')
            ->getJson("/api/v1/companies/{$company->id}/satisfaction")
            ->assertOk()
            ->assertJsonPath('data.responses', 2)
            ->assertJsonPath('data.csat', 3.5)
            ->assertJsonPath('data.nps', 0)
            ->assertJsonPath('data.attention', 1)
            ->assertJsonPath('data.invitations', 2)
            ->assertJsonPath('data.response_rate', 100)
            ->assertJsonPath('data.latest.csat', 5)
            ->assertJsonPath('data.latest.band', 'promoter')
            ->assertJsonPath('data.latest.survey.name', 'Post-deployment check-in')
            // Oldest first, so a chart reads left to right.
            ->assertJsonPath('data.trend.0.csat', 2)
            ->assertJsonPath('data.trend.1.csat', 5)
            ->assertJsonCount(2, 'data.recent');

        $this->actingAs($stranger, 'api')
            ->getJson("/api/v1/companies/{$company->id}/satisfaction")
            ->assertForbidden();
    }

    public function test_the_library_counts_the_answers_it_has_gathered(): void
    {
        $manager = User::factory()->manager()->create();
        $company = Company::factory()->ownedBy($manager)->create();
        $survey = $this->survey($manager);

        $this->answer($company, $survey, 5.0, 10);

        $this->actingAs($manager, 'api')
            ->getJson('/api/v1/surveys')
            ->assertOk()
            ->assertJsonPath('data.0.responses_count', 1);

        $this->actingAs($manager, 'api')
            ->getJson("/api/v1/surveys/{$survey->id}")
            ->assertOk()
            ->assertJsonPath('data.responses_count', 1);
    }
}
