<?php

namespace App\Http\Controllers\Api\V1;

use App\Events\SurveyResponseResolved;
use App\Http\Controllers\Controller;
use App\Http\Requests\ResolveSurveyResponseRequest;
use App\Http\Resources\SurveyResponseResource;
use App\Models\SurveyResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\ValidationException;

/**
 * The response book and one answer set (module 05 slice S3, spec 13, ADR-0019 §6–7).
 *
 * A response is what the client said, once: there is no update and no delete route here. The
 * reader is whoever may see the account the response belongs to (ADR-0004), which is why the
 * whole book is scoped by `visibleTo()` rather than by who sent the invitation.
 */
class SurveyResponseController extends Controller
{
    /**
     * Every answer in view, newest first — the book the summary cards link into.
     * `filter[survey|company|band|attention|date_from|date_to]` plus `search`.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', SurveyResponse::class);

        /** @var array<string, mixed> $filters */
        $filters = (array) $request->input('filter', []);

        $responses = SurveyResponse::query()
            ->with($this->bookRelations())
            ->visibleTo($request->user())
            ->when($filters['survey'] ?? null, fn ($query, $survey) => $query->where('survey_id', $survey))
            ->when($filters['company'] ?? null, fn ($query, $company) => $query->where('company_id', $company))
            ->filterBand($filters['band'] ?? null)
            ->filterAttention($filters['attention'] ?? null)
            ->filterDates($filters['date_from'] ?? null, $filters['date_to'] ?? null)
            ->search($request->string('search')->toString() ?: null)
            ->latest('submitted_at')
            ->paginate(min((int) $request->integer('per_page', 25), 100))
            ->appends($request->query());

        return SurveyResponseResource::collection($responses);
    }

    /**
     * One answer set with its questions in the order they were asked — the detail the attention
     * work (slice S4) opens.
     */
    public function show(SurveyResponse $response): SurveyResponseResource
    {
        $this->authorize('view', $response);

        $response->load([
            ...$this->bookRelations(),
            'contact:id,name,email',
            'invitation:id,reference,status,sent_at,submitted_at,recipient_name,recipient_email',
            'answers.question',
            'resolvedBy:id,name',
        ]);

        return new SurveyResponseResource($response);
    }

    /**
     * Close the loop on an unhappy answer with a note (slice S4, spec 13 §Business rules 8).
     *
     * The flag is not cleared: the answer was unhappy, and the resolution is what says it was
     * dealt with. Resolving twice is a 422, not a second note, because there is one closed-loop
     * record per response.
     */
    public function resolve(
        ResolveSurveyResponseRequest $request,
        SurveyResponse $response,
    ): SurveyResponseResource {
        $this->authorize('resolve', $response);

        if (! $response->needs_attention) {
            throw ValidationException::withMessages([
                'response' => ['This response did not need attention.'],
            ]);
        }

        if ($response->resolved_at !== null) {
            throw ValidationException::withMessages([
                'response' => ['This response has already been resolved.'],
            ]);
        }

        $response->forceFill([
            'resolved_at' => now(),
            'resolved_by' => $request->user()->id,
            'resolution_note' => $request->validated('note'),
        ])->save();

        SurveyResponseResolved::dispatch($response, $request->user());

        return new SurveyResponseResource($response->load([
            ...$this->bookRelations(),
            'contact:id,name,email',
            'invitation:id,reference,status,sent_at,submitted_at,recipient_name,recipient_email',
            'answers.question',
            'resolvedBy:id,name',
        ]));
    }

    /**
     * What a book row carries: the survey it answered, the account, the person and the send it
     * came from. The answers themselves are the detail's business.
     *
     * @return array<int, string>
     */
    private function bookRelations(): array
    {
        return [
            'survey:id,name',
            'company:id,name,owner_id',
            'company.owner:id,team_id',
            'contact:id,name',
            'invitation:id,reference',
            'resolvedBy:id,name',
        ];
    }
}
