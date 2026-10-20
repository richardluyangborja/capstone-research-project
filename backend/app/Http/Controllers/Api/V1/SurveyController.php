<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreSurveyRequest;
use App\Http\Requests\UpdateSurveyRequest;
use App\Http\Resources\SurveyResource;
use App\Models\Survey;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The survey library (module 05 slice S1, spec 13, ADR-0019 §1).
 *
 * A survey is a reusable template with an ordered question set: every role reads the library,
 * only manager+ authors, edits, duplicates, retires or restores one. Sending is slice S2 and is
 * not a survey action — it hangs off the company, because that is whose right it needs.
 */
class SurveyController extends Controller
{
    /**
     * The library: active first, then by name, so a draft sits below what can be sent.
     *
     * `filter[retired]=true` is how a manager finds a retired survey again to restore it — the
     * same book treatment message templates get (spec 11 §S5).
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Survey::class);

        /** @var array<string, mixed> $filters */
        $filters = (array) $request->input('filter', []);

        $surveys = Survey::query()
            ->with('author:id,name')
            ->withCount(['questions', 'invitations'])
            // `filter[active]=false` is a real request, so the key's presence decides — not its truthiness.
            ->when(array_key_exists('active', $filters), fn ($query) => $query->where(
                'is_active',
                filter_var($filters['active'], FILTER_VALIDATE_BOOL),
            ))
            ->when(
                filter_var($filters['retired'] ?? false, FILTER_VALIDATE_BOOL),
                fn ($query) => $query->onlyTrashed(),
            )
            ->search($request->string('search')->toString() ?: null)
            ->orderByDesc('is_active')
            ->orderBy('name')
            ->paginate(min((int) $request->integer('per_page', 25), 100))
            ->appends($request->query());

        return SurveyResource::collection($surveys);
    }

    /**
     * Author a survey with its questions (manager+). The array order is the asking order.
     */
    public function store(StoreSurveyRequest $request): JsonResponse
    {
        $data = $request->validated();

        $survey = DB::transaction(function () use ($data, $request): Survey {
            $survey = Survey::create([
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'is_active' => $data['is_active'] ?? false,
                'created_by' => $request->user()?->id,
            ]);

            $this->replaceQuestions($survey, $data['questions'] ?? []);

            return $survey;
        });

        return (new SurveyResource($this->withBookRelations($survey, withQuestions: true)))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * One survey. A **retired** one still opens: the wording and the questions remain readable
     * (the form shows them read-only), which is what makes Restore possible from the detail.
     */
    public function show(string $id): SurveyResource
    {
        $survey = Survey::withTrashed()->findOrFail($id);

        $this->authorize('view', $survey);

        return new SurveyResource($this->withBookRelations($survey, withQuestions: true));
    }

    /**
     * Edit a survey (manager+). The question set, when sent, **replaces** the stored one — it is
     * an ordered list, and reordering is the point. Slice S2 refuses the set once the survey has
     * been sent, because responses answer the questions they were asked.
     */
    public function update(UpdateSurveyRequest $request, Survey $survey): SurveyResource
    {
        $data = $request->validated();

        DB::transaction(function () use ($survey, $data): void {
            $survey->fill([
                'name' => $data['name'] ?? $survey->name,
                'description' => array_key_exists('description', $data) ? $data['description'] : $survey->description,
                'is_active' => $data['is_active'] ?? $survey->is_active,
            ])->save();

            if (array_key_exists('questions', $data)) {
                $survey->questions()->delete();
                $this->replaceQuestions($survey, $data['questions'] ?? []);
            }
        });

        return new SurveyResource($this->withBookRelations($survey, withQuestions: true));
    }

    /**
     * Retire a survey (manager+, soft). It leaves the sendable list; its row keeps the wording and
     * whatever responses it already gathered (spec 13 §Business rules 11).
     */
    public function destroy(Survey $survey): Response
    {
        $this->authorize('delete', $survey);

        $survey->delete();

        return response()->noContent();
    }

    public function restore(string $id): SurveyResource
    {
        $survey = Survey::withTrashed()->findOrFail($id);

        $this->authorize('restore', $survey);

        // A name is unique among *live* surveys: if one has taken this name since, restoring would
        // leave two surveys answering to one label in the library.
        if (Survey::nameTaken($survey->name, (string) $survey->getKey())) {
            throw ValidationException::withMessages([
                'name' => ['Another survey already uses that name, so this one cannot come back under it.'],
            ]);
        }

        $survey->restore();

        return new SurveyResource($this->withBookRelations($survey, withQuestions: true));
    }

    /**
     * Copy a survey's questions into a new inactive survey (manager+) — the Phase-1 way to version
     * one, since a sent survey's question set is frozen (spec 13 §Business rules 1).
     */
    public function duplicate(Request $request, Survey $survey): JsonResponse
    {
        $this->authorize('view', $survey);
        $this->authorize('create', Survey::class);

        $copy = DB::transaction(function () use ($survey, $request): Survey {
            $copy = Survey::create([
                'name' => $survey->copyName(),
                'description' => $survey->description,
                'is_active' => false,
                'created_by' => $request->user()?->id,
            ]);

            foreach ($survey->questions as $question) {
                $copy->questions()->create([
                    'type' => $question->type,
                    'prompt' => $question->prompt,
                    'help' => $question->help,
                    'required' => $question->required,
                    'position' => $question->position,
                    'options' => $question->options,
                ]);
            }

            return $copy;
        });

        return (new SurveyResource($this->withBookRelations($copy, withQuestions: true)))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Replace a survey's questions with the submitted set, numbering them from one. Positions are
     * the server's: the client sends an ordered array, not a numbering.
     *
     * @param  array<int, array<string, mixed>>  $questions
     */
    private function replaceQuestions(Survey $survey, array $questions): void
    {
        foreach (array_values($questions) as $index => $question) {
            $survey->questions()->create([
                'type' => $question['type'],
                'prompt' => $question['prompt'],
                'help' => $question['help'] ?? null,
                'required' => $question['required'] ?? true,
                'position' => $index + 1,
                'options' => $question['options'] ?? null,
            ]);
        }
    }

    /**
     * The relations a payload needs: the author always, the question count on a row and the
     * questions themselves on the detail the form and the preview read. The invitation count is
     * what makes `questions_editable` answerable without a second query.
     */
    private function withBookRelations(Survey $survey, bool $withQuestions = false): Survey
    {
        $survey->load('author:id,name')->loadCount(['questions', 'invitations']);

        return $withQuestions ? $survey->load('questions') : $survey;
    }
}
