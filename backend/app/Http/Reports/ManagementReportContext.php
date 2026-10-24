<?php

namespace App\Http\Reports;

use App\Enums\ActivityStatus;
use App\Enums\CompanyStatus;
use App\Enums\FollowUpDueState;
use App\Enums\ManagementReportVariant;
use App\Enums\OpportunityStage;
use App\Http\Dashboard\DashboardPeriod;
use App\Http\Dashboard\DashboardScope;
use App\Http\Dashboard\DashboardSignals;
use App\Http\Dashboard\DashboardSummary;
use App\Http\Dashboard\DashboardWorklist;
use App\Models\Activity;
use App\Models\Communication;
use App\Models\Company;
use App\Models\Opportunity;
use App\Models\SurveyResponse;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * The aggregated, PII-free context sent to the Python service (spec 15 §Data contract).
 *
 * The frontend never supplies context and the provider never receives a record row: this object
 * is built from the caller's already-scoped dashboard and contains counts, stage labels, dates and
 * KPI values only. The array is bounded — five pipeline stages and the last five line points per
 * series — because the prompt is one compact JSON message, not a document.
 */
final class ManagementReportContext
{
    public function __construct(
        private readonly User $user,
        private readonly DashboardPeriod $period,
        private readonly DashboardScope $scope,
        private readonly ManagementReportVariant $variant,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function build(): array
    {
        $summary = (new DashboardSummary($this->user, $this->period, $this->scope))->build();
        $kpis = collect([...$summary['primary'], ...$summary['secondary']])->keyBy('id');
        $signals = new DashboardSignals($this->user);
        $closed = $this->closedCounts();
        $metrics = SurveyResponse::metrics(
            SurveyResponse::query()->visibleTo($this->user),
        );
        $worklist = (new DashboardWorklist($this->user, $this->period->range))->build();

        $severity = ['danger' => 0, 'warning' => 0, 'info' => 0];

        foreach ($worklist['data'] as $row) {
            $key = (string) ($row['due']['severity'] ?? 'info');

            if (array_key_exists($key, $severity)) {
                $severity[$key]++;
            }
        }

        $stages = collect($summary['donut']['segments'] ?? [])
            ->sortByDesc('value')
            ->take(5)
            ->map(fn (array $stage): array => [
                'label' => (string) $stage['label'],
                'value' => round((float) $stage['value'], 2),
            ])
            ->values()
            ->all();

        return [
            'variant' => $this->variant->value,
            'period' => [
                'range' => $this->period->range->value,
                'start' => $this->period->startDate(),
                'end' => $this->period->endDate(),
            ],
            'scope' => [
                'tier' => $this->scope->tier,
                'label' => $this->scope->label,
            ],
            'kpis' => $this->kpis($kpis),
            'line' => $this->line($summary['line']),
            'pipeline' => [
                'open_value' => round((float) $this->value($kpis, 'pipeline'), 2),
                'stages' => $stages,
                'closed' => $closed,
                'win_rate' => round((float) $this->value($kpis, 'win-rate'), 1),
                'stalled' => $signals->stalledDeals(),
            ],
            'clients' => [
                'active' => (int) $this->value($kpis, 'clients'),
                'at_risk' => $signals->atRiskCompanies(),
                'dormant' => $this->companyCount(CompanyStatus::Dormant),
                'churned' => $this->companyCount(CompanyStatus::Churned),
            ],
            'satisfaction' => [
                'csat' => $metrics['csat'],
                'nps' => $metrics['nps'],
                'responses' => $metrics['responses'],
                'attention' => $metrics['attention'],
                'bands' => [
                    'promoter' => $metrics['promoters'],
                    'passive' => $metrics['passives'],
                    'detractor' => $metrics['detractors'],
                ],
            ],
            'follow_ups' => [
                ...$this->followUps($signals),
                'open' => (int) $this->value($kpis, 'follow-ups'),
            ],
            'worklist' => [
                'total' => (int) ($worklist['meta']['total'] ?? 0),
                'by_severity' => $severity,
            ],
        ];
    }

    /**
     * @param  Collection<string, array<string, mixed>>  $kpis
     * @return array<int, array<string, mixed>>
     */
    private function kpis(Collection $kpis): array
    {
        return $kpis->map(fn (array $kpi): array => [
            'id' => (string) $kpi['id'],
            'value' => $kpi['value'],
            'unit' => (string) $kpi['unit'],
            'trend' => $kpi['trend'] !== null ? [
                'direction' => (string) $kpi['trend']['direction'],
                'value' => $kpi['trend']['value'],
                'unit' => (string) $kpi['trend']['unit'],
            ] : null,
        ])->values()->all();
    }

    /**
     * The five latest chart points. The provider never needs the whole series, only the shape of
     * the period, so this keeps the prompt bounded for the long ranges.
     *
     * @param  array{labels: array<int, string>, series: array<int, array{name: string, values: array<int, int>}>}  $line
     * @return array{labels: array<int, string>, series: array<int, array{name: string, values: array<int, int>}>}
     */
    private function line(array $line): array
    {
        $labels = array_slice($line['labels'], -5);

        return [
            'labels' => $labels,
            'series' => array_map(fn (array $series): array => [
                'name' => (string) $series['name'],
                'values' => array_slice($series['values'], -5),
            ], $line['series']),
        ];
    }

    /**
     * @return array{overdue: int, due_today: int, by_kind: array{task: int, message: int}}
     */
    private function followUps(DashboardSignals $signals): array
    {
        $now = now();
        $endOfDay = FollowUpDueState::endOfBusinessDay($now);

        $tasks = Activity::query()
            ->visibleTo($this->user)
            ->tasks()
            ->where('status', ActivityStatus::Open);

        $messages = Communication::query()
            ->visibleTo($this->user)
            ->scheduled();

        return [
            'overdue' => $signals->overdueFollowUps(),
            'due_today' => (clone $tasks)
                ->where('due_at', '>=', $now)
                ->where('due_at', '<=', $endOfDay)
                ->count()
                + (clone $messages)
                    ->where('scheduled_for', '>=', $now)
                    ->where('scheduled_for', '<=', $endOfDay)
                    ->count(),
            'by_kind' => [
                'task' => (clone $tasks)->count(),
                'message' => (clone $messages)->count(),
            ],
        ];
    }

    /**
     * @return array{won: int, lost: int}
     */
    private function closedCounts(): array
    {
        $counts = Opportunity::query()
            ->visibleTo($this->user)
            ->whereIn('stage', [OpportunityStage::Won, OpportunityStage::Lost])
            ->whereBetween('actual_close_date', [$this->period->startDate(), $this->period->endDate()])
            ->selectRaw('stage, count(*) as aggregate')
            ->groupBy('stage')
            ->pluck('aggregate', 'stage');

        return [
            'won' => (int) ($counts[OpportunityStage::Won->value] ?? 0),
            'lost' => (int) ($counts[OpportunityStage::Lost->value] ?? 0),
        ];
    }

    private function companyCount(CompanyStatus $status): int
    {
        return Company::query()
            ->visibleTo($this->user)
            ->where('status', $status)
            ->count();
    }

    /**
     * @param  Collection<string, array<string, mixed>>  $kpis
     */
    private function value(Collection $kpis, string $id): int|float
    {
        /** @var array<string, mixed>|null $kpi */
        $kpi = $kpis->get($id);

        return $kpi['value'] ?? 0;
    }
}
