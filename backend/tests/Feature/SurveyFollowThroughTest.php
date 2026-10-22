<?php

namespace Tests\Feature;

use App\Enums\ActivityStatus;
use App\Enums\ActivityType;
use App\Enums\SurveyQuestionType;
use App\Events\SurveyResponseFlagged;
use App\Models\Activity;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Survey;
use App\Models\SurveyInvitation;
use App\Models\SurveyQuestion;
use App\Models\SurveyResponse;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Slice S4 — the closed loop (spec 13 §S4, ADR-0019 §7).
 *
 * An unhappy answer is not only a row: it notifies the account owner once and raises one
 * module-04 task on the account, keyed so a repeated event cannot raise a second. The owner (or
 * a manager over the team) closes the loop with a note; resolving twice is a 422 and the answer's
 * own flag is never cleared.
 */
class SurveyFollowThroughTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    private User $owner;

    private Company $company;

    private Survey $survey;

    private SurveyInvitation $invitation;

    private SurveyQuestion $rating;

    private SurveyQuestion $nps;

    private SurveyQuestion $text;

    protected function setUp(): void
    {
        parent::setUp();

        $team = Team::factory()->create();
        $this->manager = User::factory()->manager()->create(['team_id' => $team->id]);
        $this->owner = User::factory()->salesrep()->create(['team_id' => $team->id]);
        $this->company = Company::factory()->client()->ownedBy($this->owner)->create();
        $contact = Contact::factory()->forCompany($this->company)->primary()->create();

        $this->survey = Survey::factory()
            ->active()
            ->authoredBy($this->manager)
            ->withQuestions([
                ['type' => 'rating', 'prompt' => 'How satisfied are you with the deployment?'],
                ['type' => 'nps', 'prompt' => 'How likely are you to recommend us?'],
                ['type' => 'text', 'prompt' => 'Anything we should know?', 'required' => false],
            ])
            ->create(['name' => 'Post-deployment check-in']);

        $this->rating = $this->survey->questions->firstWhere('type', SurveyQuestionType::Rating);
        $this->nps = $this->survey->questions->firstWhere('type', SurveyQuestionType::Nps);
        $this->text = $this->survey->questions->firstWhere('type', SurveyQuestionType::Text);

        $this->invitation = SurveyInvitation::factory()
            ->forRecord($this->survey, $this->company, $contact)
            ->create(['created_by' => $this->manager->id]);
    }

    /**
     * Submit the public form the way a client would.
     */
    private function submit(int $rating, int $nps): SurveyResponse
    {
        $this->postJson("/api/v1/public/surveys/{$this->invitation->token}/responses", [
            'answers' => [
                ['question_id' => $this->rating->id, 'value_number' => $rating],
                ['question_id' => $this->nps->id, 'value_number' => $nps],
            ],
        ])->assertCreated();

        return SurveyResponse::query()->latest('submitted_at')->firstOrFail();
    }

    public function test_an_unhappy_answer_notifies_the_owner_once_and_raises_one_task(): void
    {
        $response = $this->submit(2, 4);

        $this->assertTrue($response->needs_attention);

        $notification = $this->owner->notifications()->sole();
        $this->assertSame('survey_attention', $notification->data['type']);
        $this->assertSame('/satisfaction/responses/'.$response->id, $notification->data['url']);
        $this->assertSame(
            'Unhappy feedback from '.$this->company->name.': CSAT 2 / 5 · NPS 4 / 10.',
            $notification->data['message'],
        );

        $task = Activity::query()->where('source_key', 'satisfaction:'.$response->id)->sole();

        $this->assertSame(ActivityType::Task, $task->type);
        $this->assertSame(ActivityStatus::Open, $task->status);
        $this->assertSame($this->owner->id, $task->owner_id);
        $this->assertSame($this->company->getMorphClass(), $task->subject_type);
        $this->assertSame($this->company->id, $task->subject_id);
        $this->assertStringContainsString('CSAT 2 / 5', (string) $task->subject);
        $this->assertNotNull($task->due_at);

        // A repeated flag is a no-op: one notification, one task.
        SurveyResponseFlagged::dispatch($response);

        $this->assertSame(1, $this->owner->notifications()->count());
        $this->assertSame(1, Activity::query()->where('source_key', 'satisfaction:'.$response->id)->count());
    }

    public function test_a_happy_answer_raises_no_task_and_no_notification(): void
    {
        $response = $this->submit(5, 10);

        $this->assertFalse($response->needs_attention);
        $this->assertSame(0, $this->owner->notifications()->count());
        $this->assertSame(0, Activity::query()->whereNotNull('source_key')->count());
    }

    public function test_the_owner_resolves_with_a_note_and_the_attention_view_empties(): void
    {
        $response = $this->submit(2, 4);

        $this->actingAs($this->owner, 'api')
            ->postJson("/api/v1/survey-responses/{$response->id}/resolve", [
                'note' => 'Called Grace, apologised for the late replacements and confirmed the Monday crew.',
            ])
            ->assertOk()
            ->assertJsonPath('data.resolved', true)
            ->assertJsonPath('data.can_resolve', false)
            ->assertJsonPath('data.resolved_by.name', $this->owner->name)
            ->assertJsonPath(
                'data.resolution_note',
                'Called Grace, apologised for the late replacements and confirmed the Monday crew.',
            );

        $response->refresh();

        // The answer was unhappy; the resolution says it was dealt with.
        $this->assertTrue($response->needs_attention);
        $this->assertNotNull($response->resolved_at);
        $this->assertSame($this->owner->id, $response->resolved_by);

        // The note is on the account timeline, and the task stays open until the call happens.
        $activity = Activity::query()
            ->where('type', ActivityType::System)
            ->where('subject', 'like', 'Survey response resolved%')
            ->sole();

        $this->assertSame($this->owner->id, $activity->owner_id);
        $this->assertSame(
            'Called Grace, apologised for the late replacements and confirmed the Monday crew.',
            $activity->body,
        );

        $this->assertSame(
            ActivityStatus::Open,
            Activity::query()->where('source_key', 'satisfaction:'.$response->id)->sole()->status,
        );

        // "Who is unhappy right now" no longer counts it, and the attention filter misses it.
        $this->actingAs($this->owner, 'api')
            ->getJson('/api/v1/satisfaction/summary')
            ->assertOk()
            ->assertJsonPath('data.attention', 0)
            ->assertJsonCount(0, 'data.attention_responses');

        $this->actingAs($this->owner, 'api')
            ->getJson('/api/v1/survey-responses?filter[attention]=true')
            ->assertOk()
            ->assertJsonPath('meta.total', 0);

        // The book still shows the answer, now resolved.
        $this->actingAs($this->owner, 'api')
            ->getJson('/api/v1/survey-responses')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.resolved', true);
    }

    public function test_resolving_twice_is_a_422(): void
    {
        $response = $this->submit(2, 4);

        $this->actingAs($this->owner, 'api')
            ->postJson("/api/v1/survey-responses/{$response->id}/resolve", ['note' => 'Called the client.'])
            ->assertOk();

        $this->actingAs($this->owner, 'api')
            ->postJson("/api/v1/survey-responses/{$response->id}/resolve", ['note' => 'Called again.'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('response');
    }

    public function test_only_the_owner_or_a_manager_may_resolve(): void
    {
        $response = $this->submit(2, 4);
        $stranger = User::factory()->salesrep()->create();

        $this->actingAs($stranger, 'api')
            ->postJson("/api/v1/survey-responses/{$response->id}/resolve", ['note' => 'Not mine.'])
            ->assertForbidden();

        $this->actingAs($this->manager, 'api')
            ->getJson("/api/v1/survey-responses/{$response->id}")
            ->assertOk()
            ->assertJsonPath('data.can_resolve', true);

        $this->actingAs($this->manager, 'api')
            ->postJson("/api/v1/survey-responses/{$response->id}/resolve", [
                'note' => 'Maria called the client and moved the crew.',
            ])
            ->assertOk()
            ->assertJsonPath('data.resolved_by.name', $this->manager->name);
    }

    public function test_a_resolution_needs_a_note_and_a_response_that_did_not_need_attention_cannot_be_resolved(): void
    {
        $unhappy = $this->submit(2, 4);

        $this->actingAs($this->owner, 'api')
            ->postJson("/api/v1/survey-responses/{$unhappy->id}/resolve", ['note' => ''])
            ->assertStatus(422)
            ->assertJsonValidationErrors('note');

        // One submission per invitation, so use a second invitation for the happy answer.
        $happyInvitation = SurveyInvitation::factory()
            ->forRecord($this->survey, $this->company)
            ->create(['created_by' => $this->manager->id]);

        $this->postJson("/api/v1/public/surveys/{$happyInvitation->token}/responses", [
            'answers' => [
                ['question_id' => $this->rating->id, 'value_number' => 5],
                ['question_id' => $this->nps->id, 'value_number' => 10],
            ],
        ])->assertCreated();

        $happy = SurveyResponse::query()->latest('submitted_at')->firstOrFail();

        $this->actingAs($this->owner, 'api')
            ->postJson("/api/v1/survey-responses/{$happy->id}/resolve", [
                'note' => 'Nothing to fix.',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('response');
    }
}
