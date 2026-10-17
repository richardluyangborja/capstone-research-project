<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\OpportunityStage;
use App\Http\Controllers\Controller;
use App\Http\Resources\OpportunityResource;
use App\Models\Opportunity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * The pipeline board (module 02 §1). One payload: the open stages as columns (each with
 * its cards and totals), the closed deals for the Closed toggle, and a forecast summary.
 * Read-only and role-scoped (ADR-0004); nothing is scheduled (ADR-0015 §6).
 */
class PipelineController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Opportunity::class);

        $user = $request->user();

        /** @var array<string, mixed> $filters */
        $filters = (array) $request->input('filter', []);
        $search = $request->string('search')->toString() ?: null;

        $base = fn () => Opportunity::query()
            ->with(['owner:id,name', 'company'])
            ->visibleTo($user)
            ->search($search)
            ->when($filters['source'] ?? null, fn ($query, $source) => $query->where('source', $source))
            ->when($filters['close_window'] ?? null, fn ($query, $window) => $query->closeWindow((string) $window))
            ->when($filters['company'] ?? null, fn ($query, $company) => $query->where('company_id', $company))
            ->when($filters['owner'] ?? null, function ($query, $owner) use ($user) {
                return match ($owner) {
                    'me' => $query->where('owner_id', $user->id),
                    'team' => $query->whereHas('owner', fn ($q) => $q->where('team_id', $user->team_id)),
                    default => $query->where('owner_id', $owner),
                };
            });

        $deals = $base()
            ->open()
            ->orderBy('expected_close_date')
            ->orderByDesc('created_at')
            ->get();

        $closed = $base()
            ->whereIn('stage', [OpportunityStage::Won->value, OpportunityStage::Lost->value])
            ->orderByDesc('actual_close_date')
            ->limit(50)
            ->get();

        $columns = collect(OpportunityStage::openStages())->map(function (OpportunityStage $stage) use ($deals): array {
            /** @var Collection<int, Opportunity> $stageDeals */
            $stageDeals = $deals->where('stage', $stage)->values();

            return [
                'stage' => $stage->value,
                'count' => $stageDeals->count(),
                'value' => $this->sumValue($stageDeals),
                'weighted_value' => $this->sumWeighted($stageDeals),
                'deals' => OpportunityResource::collection($stageDeals)->resolve(),
            ];
        })->all();

        /** @var Collection<int, Opportunity> $won */
        $won = $closed->where('stage', OpportunityStage::Won);
        /** @var Collection<int, Opportunity> $lost */
        $lost = $closed->where('stage', OpportunityStage::Lost);
        $closedCount = $won->count() + $lost->count();

        return response()->json([
            'data' => [
                'columns' => $columns,
                'closed' => OpportunityResource::collection($closed)->resolve(),
            ],
            'meta' => [
                'open_count' => $deals->count(),
                'open_value' => $this->sumValue($deals),
                'weighted_value' => $this->sumWeighted($deals),
                'won_count' => $won->count(),
                'won_value' => $this->sumValue($won),
                'lost_count' => $lost->count(),
                'lost_value' => $this->sumValue($lost),
                'win_rate' => $closedCount > 0 ? round($won->count() / $closedCount * 100, 1) : null,
                'average_deal' => $deals->count() > 0 ? round($this->sumValue($deals) / $deals->count(), 2) : null,
                'stale_count' => $deals->filter(fn (Opportunity $opportunity): bool => $opportunity->isStale())->count(),
                'by_stage' => collect($columns)->map(fn (array $column): array => [
                    'stage' => $column['stage'],
                    'count' => $column['count'],
                    'value' => $column['value'],
                    'weighted_value' => $column['weighted_value'],
                ])->all(),
            ],
        ]);
    }

    /**
     * @param  Collection<int, Opportunity>  $deals
     */
    private function sumValue(Collection $deals): float
    {
        return round((float) $deals->sum(fn (Opportunity $opportunity): float => (float) ($opportunity->amount ?? 0)), 2);
    }

    /**
     * @param  Collection<int, Opportunity>  $deals
     */
    private function sumWeighted(Collection $deals): float
    {
        return round((float) $deals->sum(fn (Opportunity $opportunity): float => $opportunity->weightedValue()), 2);
    }
}
