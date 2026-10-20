<?php

namespace Tests\Feature;

use App\Models\Survey;
use App\Models\SurveyQuestion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Slice S1 — the survey library (spec 13 §S1, ADR-0019 §1).
 *
 * A survey is house wording: every role reads the library, only manager+ authors. The question set
 * is an ordered, validated aggregate — five types, at most one promoter question, and a survey
 * cannot be active without a question to ask.
 */
class SurveyLibraryTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  array<int, array<string, mixed>>  $questions
     * @return array<string, mixed>
     */
    private function payload(array $questions = [], array $overrides = []): array
    {
        return $overrides + [
            'name' => 'Post-deployment check-in',
            'description' => 'How the first weeks went.',
            'questions' => $questions ?: [
                [
                    'type' => 'rating',
                    'prompt' => 'How satisfied are you with the deployment?',
                    'required' => true,
                ],
                [
                    'type' => 'nps',
                    'prompt' => 'How likely are you to recommend us?',
                ],
            ],
        ];
    }

    public function test_the_library_is_readable_by_every_role_and_tells_a_salesrep_they_cannot_author(): void
    {
        $manager = User::factory()->manager()->create();
        $rep = User::factory()->salesrep()->create();
        Survey::factory()->authoredBy($manager)->withQuestions([
            ['type' => 'rating', 'prompt' => 'How was it?'],
        ])->create(['name' => 'Post-deployment check-in']);

        $this->actingAs($rep, 'api')
            ->getJson('/api/v1/surveys')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.name', 'Post-deployment check-in')
            ->assertJsonPath('data.0.questions_count', 1)
            ->assertJsonPath('data.0.author.name', $manager->name)
            ->assertJsonPath('data.0.can_update', false)
            ->assertJsonPath('data.0.can_delete', false)
            ->assertJsonPath('data.0.can_restore', false)
            ->assertJsonPath('data.0.can_duplicate', false);
    }

    public function test_a_salesrep_cannot_create_edit_retire_restore_or_duplicate_a_survey(): void
    {
        $manager = User::factory()->manager()->create();
        $rep = User::factory()->salesrep()->create();
        $survey = Survey::factory()->authoredBy($manager)->create();

        $this->actingAs($rep, 'api')->postJson('/api/v1/surveys', $this->payload())
            ->assertForbidden();

        $this->actingAs($rep, 'api')
            ->patchJson("/api/v1/surveys/{$survey->id}", ['name' => 'Rep survey'])
            ->assertForbidden();

        $this->actingAs($rep, 'api')->deleteJson("/api/v1/surveys/{$survey->id}")
            ->assertForbidden();

        $this->actingAs($rep, 'api')->postJson("/api/v1/surveys/{$survey->id}/restore")
            ->assertForbidden();

        $this->actingAs($rep, 'api')->postJson("/api/v1/surveys/{$survey->id}/duplicate")
            ->assertForbidden();

        $this->assertNull($survey->fresh()->deleted_at);
    }

    public function test_a_manager_authors_a_survey_with_its_questions_in_order(): void
    {
        $manager = User::factory()->manager()->create();

        $response = $this->actingAs($manager, 'api')->postJson('/api/v1/surveys', $this->payload([
            [
                'type' => 'rating',
                'prompt' => '  How satisfied are you with the deployment?  ',
                'help' => 'From day one to today.',
            ],
            [
                'type' => 'nps',
                'prompt' => 'How likely are you to recommend us?',
            ],
            [
                'type' => 'single_choice',
                'prompt' => 'How is the headcount?',
                'options' => ['Short', 'Just right', 'More than we need'],
            ],
            [
                'type' => 'text',
                'prompt' => 'Anything else?',
                'required' => false,
            ],
        ], ['name' => '  Post-deployment check-in  ', 'is_active' => true]));

        $response->assertCreated()
            ->assertJsonPath('data.name', 'Post-deployment check-in')
            ->assertJsonPath('data.is_active', true)
            ->assertJsonPath('data.author.name', $manager->name)
            ->assertJsonPath('data.can_update', true)
            ->assertJsonPath('data.questions_count', 4)
            ->assertJsonPath('data.questions.0.position', 1)
            ->assertJsonPath('data.questions.0.prompt', 'How satisfied are you with the deployment?')
            ->assertJsonPath('data.questions.0.type.value', 'rating')
            ->assertJsonPath('data.questions.0.type.label', 'Rating (1–5)')
            ->assertJsonPath('data.questions.2.type.value', 'single_choice')
            ->assertJsonPath('data.questions.2.options.1', 'Just right')
            ->assertJsonPath('data.questions.3.required', false);

        $this->assertDatabaseHas('surveys', ['name' => 'Post-deployment check-in', 'is_active' => true]);
        $this->assertDatabaseCount('survey_questions', 4);
    }

    public function test_a_survey_is_a_draft_until_it_is_activated(): void
    {
        $manager = User::factory()->manager()->create();

        $this->actingAs($manager, 'api')
            ->postJson('/api/v1/surveys', $this->payload())
            ->assertCreated()
            ->assertJsonPath('data.is_active', false);
    }

    public function test_a_choice_question_needs_options_and_a_scored_one_takes_none(): void
    {
        $manager = User::factory()->manager()->create();

        $this->actingAs($manager, 'api')
            ->postJson('/api/v1/surveys', $this->payload([
                ['type' => 'single_choice', 'prompt' => 'Pick one'],
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('questions.0.options');

        $this->actingAs($manager, 'api')
            ->postJson('/api/v1/surveys', $this->payload([
                ['type' => 'rating', 'prompt' => 'Rate us', 'options' => ['A', 'B']],
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('questions.0.options');
    }

    public function test_an_option_list_must_hold_two_to_ten_distinct_choices(): void
    {
        $manager = User::factory()->manager()->create();

        $this->actingAs($manager, 'api')
            ->postJson('/api/v1/surveys', $this->payload([
                ['type' => 'multi_choice', 'prompt' => 'Which applied?', 'options' => ['One']],
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('questions.0.options');

        $this->actingAs($manager, 'api')
            ->postJson('/api/v1/surveys', $this->payload([
                ['type' => 'multi_choice', 'prompt' => 'Which applied?', 'options' => ['Same', 'Same']],
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('questions.0.options.1');
    }

    public function test_a_survey_can_ask_the_promoter_question_only_once(): void
    {
        $manager = User::factory()->manager()->create();

        $this->actingAs($manager, 'api')->postJson('/api/v1/surveys', $this->payload([
            ['type' => 'nps', 'prompt' => 'Recommend us?'],
            ['type' => 'nps', 'prompt' => 'And again?'],
        ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('questions');
    }

    public function test_a_survey_with_no_question_cannot_be_active(): void
    {
        $manager = User::factory()->manager()->create();

        $this->actingAs($manager, 'api')
            ->postJson('/api/v1/surveys', [
                'name' => 'Empty survey',
                'is_active' => true,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('questions');

        // The draft itself is fine — it is how a survey is written before it is finished.
        $survey = $this->actingAs($manager, 'api')
            ->postJson('/api/v1/surveys', ['name' => 'Empty survey'])
            ->assertCreated()
            ->json('data');

        $this->actingAs($manager, 'api')
            ->patchJson("/api/v1/surveys/{$survey['id']}", ['is_active' => true])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('questions');

        $this->actingAs($manager, 'api')
            ->patchJson("/api/v1/surveys/{$survey['id']}", [
                'is_active' => true,
                'questions' => [['type' => 'text', 'prompt' => 'Tell us more']],
            ])
            ->assertOk()
            ->assertJsonPath('data.is_active', true)
            ->assertJsonPath('data.questions.0.prompt', 'Tell us more');
    }

    public function test_a_name_is_unique_among_live_surveys_only(): void
    {
        $manager = User::factory()->manager()->create();
        Survey::factory()->create(['name' => 'Quarterly account review']);

        $this->actingAs($manager, 'api')
            ->postJson('/api/v1/surveys', $this->payload([], ['name' => 'quarterly account review']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('name');

        // A retired survey frees its name …
        Survey::query()->where('name', 'Quarterly account review')->first()->delete();

        $this->actingAs($manager, 'api')
            ->postJson('/api/v1/surveys', $this->payload([], ['name' => 'Quarterly account review']))
            ->assertCreated();
    }

    public function test_an_edit_replaces_the_question_set_but_keeps_the_survey(): void
    {
        $manager = User::factory()->manager()->create();
        $survey = Survey::factory()->authoredBy($manager)->withQuestions([
            ['type' => 'rating', 'prompt' => 'Old first'],
            ['type' => 'text', 'prompt' => 'Old second'],
        ])->create(['name' => 'Quarterly account review']);

        $this->actingAs($manager, 'api')
            ->patchJson("/api/v1/surveys/{$survey->id}", [
                'description' => 'Rewritten.',
                'questions' => [
                    ['type' => 'text', 'prompt' => 'New first'],
                    ['type' => 'rating', 'prompt' => 'New second'],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('data.description', 'Rewritten.')
            ->assertJsonPath('data.questions.0.prompt', 'New first')
            ->assertJsonPath('data.questions.1.prompt', 'New second')
            ->assertJsonPath('data.questions.0.position', 1)
            ->assertJsonPath('data.questions.1.position', 2);

        $this->assertDatabaseMissing('survey_questions', ['prompt' => 'Old first']);
        $this->assertDatabaseHas('surveys', ['id' => $survey->id, 'name' => 'Quarterly account review']);
    }

    public function test_retiring_hides_a_survey_from_the_book_and_restore_brings_it_back(): void
    {
        $manager = User::factory()->manager()->create();
        $survey = Survey::factory()->authoredBy($manager)->withQuestions([
            ['type' => 'rating', 'prompt' => 'How was it?'],
        ])->create(['name' => 'Exit survey']);

        $this->actingAs($manager, 'api')
            ->deleteJson("/api/v1/surveys/{$survey->id}")
            ->assertNoContent();

        $this->actingAs($manager, 'api')
            ->getJson('/api/v1/surveys')
            ->assertOk()
            ->assertJsonPath('meta.total', 0);

        $this->actingAs($manager, 'api')
            ->getJson('/api/v1/surveys?filter[retired]=true')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.deleted_at', fn ($value) => $value !== null);

        // A retired survey still opens — read-only in the form, restored from the library.
        $this->actingAs($manager, 'api')
            ->getJson("/api/v1/surveys/{$survey->id}")
            ->assertOk()
            ->assertJsonPath('data.name', 'Exit survey')
            ->assertJsonPath('data.questions.0.prompt', 'How was it?')
            ->assertJsonPath('data.deleted_at', fn ($value) => $value !== null);

        $this->actingAs($manager, 'api')
            ->postJson("/api/v1/surveys/{$survey->id}/restore")
            ->assertOk()
            ->assertJsonPath('data.name', 'Exit survey')
            ->assertJsonPath('data.questions_count', 1);

        $this->assertNull($survey->fresh()->deleted_at);
    }

    public function test_restoring_a_survey_whose_name_was_reused_is_refused(): void
    {
        $manager = User::factory()->manager()->create();
        $survey = Survey::factory()->authoredBy($manager)->create(['name' => 'Exit survey']);
        $survey->delete();

        Survey::factory()->create(['name' => 'Exit survey']);

        $this->actingAs($manager, 'api')
            ->postJson("/api/v1/surveys/{$survey->id}/restore")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('name');

        $this->assertNotNull($survey->fresh()->deleted_at);
    }

    public function test_duplicating_copies_the_questions_into_a_new_draft(): void
    {
        $manager = User::factory()->manager()->create();
        $survey = Survey::factory()->authoredBy($manager)->active()->withQuestions([
            ['type' => 'rating', 'prompt' => 'How satisfied are you?', 'help' => 'Scale of one to five.'],
            ['type' => 'single_choice', 'prompt' => 'How is the headcount?', 'options' => ['Short', 'Just right']],
        ])->create(['name' => 'Quarterly account review']);

        $this->actingAs($manager, 'api')
            ->postJson("/api/v1/surveys/{$survey->id}/duplicate")
            ->assertCreated()
            ->assertJsonPath('data.name', 'Quarterly account review (copy)')
            ->assertJsonPath('data.is_active', false)
            ->assertJsonPath('data.questions_count', 2)
            ->assertJsonPath('data.questions.0.prompt', 'How satisfied are you?')
            ->assertJsonPath('data.questions.1.options.0', 'Short');

        // A second copy takes the next free label rather than failing on its own name.
        $this->actingAs($manager, 'api')
            ->postJson("/api/v1/surveys/{$survey->id}/duplicate")
            ->assertCreated()
            ->assertJsonPath('data.name', 'Quarterly account review (copy 2)');

        $this->assertSame(3, Survey::query()->count());
        // The source plus both copies ask the same question.
        $this->assertSame(3, SurveyQuestion::query()->where('prompt', 'How satisfied are you?')->count());
    }

    public function test_the_book_filters_by_active_state_and_search(): void
    {
        $manager = User::factory()->manager()->create();
        Survey::factory()->active()->create(['name' => 'Post-deployment check-in', 'description' => 'The first weeks.']);
        Survey::factory()->create(['name' => 'Deployment pulse (pilot)', 'description' => 'Still being written.']);
        Survey::factory()->create(['name' => 'Exit survey', 'description' => 'When an engagement ends.']);

        $this->actingAs($manager, 'api')
            ->getJson('/api/v1/surveys?filter[active]=true')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.name', 'Post-deployment check-in');

        $this->actingAs($manager, 'api')
            ->getJson('/api/v1/surveys?filter[active]=false')
            ->assertOk()
            ->assertJsonPath('meta.total', 2);

        $this->actingAs($manager, 'api')
            ->getJson('/api/v1/surveys?search=deployment')
            ->assertOk()
            ->assertJsonPath('meta.total', 2);

        // Active first, then by name — the book puts what can be sent on top.
        $this->actingAs($manager, 'api')
            ->getJson('/api/v1/surveys')
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Post-deployment check-in')
            ->assertJsonPath('data.1.name', 'Deployment pulse (pilot)');
    }

    public function test_showing_a_survey_lists_its_questions_in_the_order_asked(): void
    {
        $manager = User::factory()->manager()->create();
        $survey = Survey::factory()->authoredBy($manager)->withQuestions([
            ['type' => 'rating', 'prompt' => 'First', 'position' => 1],
            ['type' => 'nps', 'prompt' => 'Second', 'position' => 2],
            ['type' => 'text', 'prompt' => 'Third', 'position' => 3],
        ])->create();

        $this->actingAs($manager, 'api')
            ->getJson("/api/v1/surveys/{$survey->id}")
            ->assertOk()
            ->assertJsonPath('data.questions.0.prompt', 'First')
            ->assertJsonPath('data.questions.1.prompt', 'Second')
            ->assertJsonPath('data.questions.2.prompt', 'Third')
            ->assertJsonPath('data.questions_count', 3);
    }
}
