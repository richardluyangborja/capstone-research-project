<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\SurveyInvitationStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\SurveyResponseResource;
use App\Models\Company;
use App\Models\SurveyInvitation;
use App\Models\SurveyResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The satisfaction rollup and an account's card (module 05 slice S3, spec 13, ADR-0019 §9).
 *
 * Nothing here is stored: CSAT, NPS, the band split, the response rate and attention are derived
 * from the responses on read, so a corrected seed or a new answer changes the numbers at once.
 * The scope is the caller's — a salesrep counts their own accounts, a manager the team's, an
 * admin everything (ADR-0004).
 */
class SatisfactionController extends Controller
{
    /**
     * The org summary: every answer in view, plus the five most recent so the page can list
     * them without a second call.
     */
    public function summary(Request $request): JsonResponse
    {
        $this->authorize('viewAny', SurveyResponse::class);

        $responses = SurveyResponse::query()->visibleTo($request->user());

        $invitations = SurveyInvitation::query()
            ->whereIn('company_id', Company::query()->visibleTo($request->user())->select('id'))
            ->whereIn('status', $this->answerableStatuses())
            ->count();

        $recent = (clone $responses)
            ->with(['survey:id,name', 'company:id,name', 'contact:id,name'])
            ->latest('submitted_at')
            ->limit(5)
            ->get();

        return response()->json([
            'data' => [
                ...$this->withResponseRate(SurveyResponse::metrics($responses), $invitations),
                'recent' => SurveyResponseResource::collection($recent)->resolve(),
            ],
        ]);
    }

    /**
     * One account's satisfaction card: its latest answer, the same derived numbers over its own
     * responses, and a short trend so a repeated client shows movement.
     */
    public function forCompany(Company $company): JsonResponse
    {
        $this->authorize('view', $company);

        $responses = SurveyResponse::query()->where('company_id', $company->id);

        $invitations = $company->surveyInvitations()
            ->whereIn('status', $this->answerableStatuses())
            ->count();

        $latest = (clone $responses)
            ->with('survey:id,name')
            ->latest('submitted_at')
            ->first();

        // The last eight answers, read newest-first so the database does the limiting, then
        // flipped for a chart that reads left to right.
        $trend = (clone $responses)
            ->reorder()
            ->orderByDesc('submitted_at')
            ->limit(8)
            ->get(['submitted_at', 'csat', 'nps'])
            ->reverse()
            ->values()
            ->map(fn (SurveyResponse $response): array => [
                'submitted_at' => $response->submitted_at?->toIso8601String(),
                'csat' => $response->csat,
                'nps' => $response->nps,
            ]);

        $recent = (clone $responses)
            ->with(['survey:id,name', 'contact:id,name'])
            ->latest('submitted_at')
            ->limit(5)
            ->get();

        return response()->json([
            'data' => [
                ...$this->withResponseRate(SurveyResponse::metrics($responses), $invitations),
                'latest' => $latest === null ? null : [
                    'id' => $latest->id,
                    'survey' => $latest->survey === null ? null : [
                        'id' => $latest->survey->id,
                        'name' => $latest->survey->name,
                    ],
                    'csat' => $latest->csat,
                    'nps' => $latest->nps,
                    'band' => $latest->band?->value,
                    'band_label' => $latest->band?->label(),
                    'needs_attention' => (bool) $latest->needs_attention,
                    'submitted_at' => $latest->submitted_at?->toIso8601String(),
                ],
                'trend' => $trend,
                'recent' => SurveyResponseResource::collection($recent)->resolve(),
            ],
        ]);
    }

    /**
     * A response rate only counts the invitations that could have been answered: a failed send
     * never reached the client and a withdrawn one was taken back, so neither belongs in the
     * denominator (spec 13 §Business rules 9).
     *
     * @param  array<string, mixed>  $metrics
     * @return array<string, mixed>
     */
    private function withResponseRate(array $metrics, int $invitations): array
    {
        $responses = (int) $metrics['responses'];

        return [
            ...$metrics,
            'invitations' => $invitations,
            'response_rate' => $invitations === 0
                ? null
                : round(($responses / $invitations) * 100, 1),
        ];
    }

    /**
     * The stored statuses that were or are answerable — an expired invitation is still a
     * `sent` one whose window closed.
     *
     * @return array<int, SurveyInvitationStatus>
     */
    private function answerableStatuses(): array
    {
        return [SurveyInvitationStatus::Sent, SurveyInvitationStatus::Submitted];
    }
}
