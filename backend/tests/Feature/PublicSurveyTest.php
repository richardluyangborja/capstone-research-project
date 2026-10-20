<?php

namespace Tests\Feature;

use App\Enums\SurveyQuestionType;
use App\Models\Activity;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Survey;
use App\Models\SurveyAnswer;
use App\Models\SurveyInvitation;
use App\Models\SurveyQuestion;
use App\Models\SurveyResponse;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Slice S2 — the client's page (spec 13 §S2, §Business rules 6–7).
 *
 * The token is the credential: an unknown one is 404, a dead one 410, and a valid one returns
 * only the survey being asked. A submission is validated by question type, stored once, scored
 * once, and kills the link.
 */
class PublicSurveyTest extends TestCase
{
    use RefreshDatabase;

    private Survey $survey;

    private SurveyInvitation $invitation;

    private SurveyQuestion $rating;

    private SurveyQuestion $nps;

    private SurveyQuestion $text;

    protected function setUp(): void
    {
        parent::setUp();

        $manager = User::factory()->manager()->create();
        $company = Company::factory()->client()->ownedBy($manager)->create();
        $contact = Contact::factory()->forCompany($company)->primary()->create();

        $this->survey = Survey::factory()
            ->active()
            ->authoredBy($manager)
            ->withQuestions([
                ['type' => 'rating', 'prompt' => 'How satisfied are you with the deployment?'],
                ['type' => 'nps', 'prompt' => 'How likely are you to recommend us?'],
                ['type' => 'text', 'prompt' => 'Anything we should know?', 'required' => false],
            ])
            ->create(['name' => 'Post-deployment check-in', 'description' => 'How the first weeks went.']);

        $this->rating = $this->survey->questions->firstWhere('type', SurveyQuestionType::Rating);
        $this->nps = $this->survey->questions->firstWhere('type', SurveyQuestionType::Nps);
        $this->text = $this->survey->questions->firstWhere('type', SurveyQuestionType::Text);

        $this->invitation = SurveyInvitation::factory()
            ->forRecord($this->survey, $company, $contact)
            ->create(['created_by' => $manager->id]);
    }

    /**
     * @param  array<int, array<string, mixed>>  $answers
     * @return array<string, mixed>
     */
    private function answers(array $answers): array
    {
        return ['answers' => $answers];
    }

    /**
     * A complete, happy answer set.
     *
     * @return array<int, array<string, mixed>>
     */
    private function happyAnswers(): array
    {
        return [
            ['question_id' => $this->rating->id, 'value_number' => 5],
            ['question_id' => $this->nps->id, 'value_number' => 10],
            ['question_id' => $this->text->id, 'value_text' => 'The crew settled in quickly.'],
        ];
    }

    public function test_the_form_opens_without_a_token_and_returns_only_that_surveys_questions(): void
    {
        $this->getJson("/api/v1/public/surveys/{$this->invitation->token}")
            ->assertOk()
            ->assertJsonPath('data.survey.name', 'Post-deployment check-in')
            ->assertJsonPath('data.survey.description', 'How the first weeks went.')
            ->assertJsonPath('data.company.name', $this->invitation->company->name)
            ->assertJsonCount(3, 'data.survey.questions')
            ->assertJsonPath('data.survey.questions.0.type', 'rating')
            ->assertJsonPath('data.survey.questions.0.bounds.min', 1)
            ->assertJsonPath('data.survey.questions.0.bounds.max', 5)
            ->assertJsonPath('data.survey.questions.1.bounds.max', 10)
            ->assertJsonPath('data.survey.questions.1.required', true)
            ->assertJsonMissingPath('data.survey.questions.0.answer');
    }

    public function test_an_unknown_token_is_404_and_a_dead_one_is_410(): void
    {
        $this->getJson('/api/v1/public/surveys/not-a-real-token')->assertNotFound();

        foreach (['expired', 'submitted', 'cancelled', 'failed'] as $state) {
            $dead = SurveyInvitation::factory()
                ->forRecord($this->survey, $this->invitation->company)
                ->{$state}()
                ->create();

            $this->getJson("/api/v1/public/surveys/{$dead->token}")->assertStatus(410);
            $this->postJson("/api/v1/public/surveys/{$dead->token}/responses", $this->answers([
                ['question_id' => $this->rating->id, 'value_number' => 4],
                ['question_id' => $this->nps->id, 'value_number' => 9],
            ]))->assertStatus(410);
        }
    }

    public function test_a_submission_is_validated_by_question_type(): void
    {
        $this->postJson("/api/v1/public/surveys/{$this->invitation->token}/responses", $this->answers([
            ['question_id' => $this->rating->id, 'value_number' => 6],
        ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('answers.'.$this->rating->id)
            ->assertJsonValidationErrors('answers.'.$this->nps->id);

        $this->postJson("/api/v1/public/surveys/{$this->invitation->token}/responses", $this->answers([
            ['question_id' => $this->rating->id, 'value_number' => 3],
            ['question_id' => $this->nps->id, 'value_number' => 11],
        ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('answers.'.$this->nps->id);

        $this->postJson("/api/v1/public/surveys/{$this->invitation->token}/responses", $this->answers([
            ['question_id' => 'unknown-question', 'value_number' => 3],
        ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('answers');

        $this->assertSame(0, SurveyResponse::query()->count());
    }

    public function test_a_happy_submission_is_stored_once_scored_and_the_link_dies(): void
    {
        $this->postJson("/api/v1/public/surveys/{$this->invitation->token}/responses", $this->answers($this->happyAnswers()))
            ->assertCreated()
            ->assertJsonPath('data.csat', 5)
            ->assertJsonPath('data.nps', 10)
            ->assertJsonPath('data.band', 'promoter')
            ->assertJsonPath('data.needs_attention', false);

        $response = SurveyResponse::query()->sole();

        $this->assertSame($this->invitation->id, $response->invitation_id);
        $this->assertSame($this->survey->id, $response->survey_id);
        $this->assertSame(3, SurveyAnswer::query()->count());
        $this->assertSame('The crew settled in quickly.', $response->answers->firstWhere('question_id', $this->text->id)?->value_text);

        $this->assertSame('submitted', $this->invitation->fresh()?->status->value);

        // The account's timeline says it happened, without the survey tables teaching the
        // timeline about surveys.
        $activity = Activity::query()
            ->where('subject_type', (new Company)->getMorphClass())
            ->where('subject_id', $response->company_id)
            ->where('subject', 'like', 'Survey response received%')
            ->sole();
        $this->assertStringContainsString('Survey response received', (string) $activity->subject);
        $this->assertStringContainsString('CSAT 5.00/5', (string) $activity->subject);

        // The token is single use.
        $this->postJson("/api/v1/public/surveys/{$this->invitation->token}/responses", $this->answers($this->happyAnswers()))
            ->assertStatus(410);

        $this->assertSame(1, SurveyResponse::query()->count());
        $this->assertSame(3, SurveyAnswer::query()->count());
    }

    public function test_an_unhappy_submission_is_scored_as_a_detractor_and_flagged(): void
    {
        $this->postJson("/api/v1/public/surveys/{$this->invitation->token}/responses", $this->answers([
            ['question_id' => $this->rating->id, 'value_number' => 2],
            ['question_id' => $this->nps->id, 'value_number' => 4],
        ]))
            ->assertCreated()
            ->assertJsonPath('data.csat', 2)
            ->assertJsonPath('data.band', 'detractor')
            ->assertJsonPath('data.needs_attention', true);

        $this->assertSame(1, SurveyResponse::query()->count());
    }

    public function test_a_choice_answer_must_name_an_offered_option_and_only_one(): void
    {
        $choice = $this->survey->questions()->create([
            'type' => 'single_choice',
            'prompt' => 'How is the headcount matching your needs?',
            'required' => true,
            'position' => 4,
            'options' => ['Short of what we need', 'Just right'],
        ]);

        $this->postJson("/api/v1/public/surveys/{$this->invitation->token}/responses", $this->answers([
            ['question_id' => $this->rating->id, 'value_number' => 4],
            ['question_id' => $this->nps->id, 'value_number' => 8],
            ['question_id' => $choice->id, 'value_options' => ['Something else']],
        ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('answers.'.$choice->id);

        $this->postJson("/api/v1/public/surveys/{$this->invitation->token}/responses", $this->answers([
            ['question_id' => $this->rating->id, 'value_number' => 4],
            ['question_id' => $this->nps->id, 'value_number' => 8],
            ['question_id' => $choice->id, 'value_options' => ['Just right', 'Short of what we need']],
        ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('answers.'.$choice->id);

        $this->postJson("/api/v1/public/surveys/{$this->invitation->token}/responses", $this->answers([
            ['question_id' => $this->rating->id, 'value_number' => 4],
            ['question_id' => $this->nps->id, 'value_number' => 8],
            ['question_id' => $choice->id, 'value_options' => ['Just right']],
        ]))
            ->assertCreated();

        $stored = SurveyAnswer::query()->where('question_id', $choice->id)->sole();
        $this->assertSame(['Just right'], $stored->value_options);
    }

    public function test_the_page_still_works_after_the_survey_is_retired(): void
    {
        $this->survey->delete();

        $this->getJson("/api/v1/public/surveys/{$this->invitation->token}")->assertOk();
    }
}
