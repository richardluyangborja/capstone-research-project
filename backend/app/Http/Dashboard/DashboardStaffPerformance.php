<?php

namespace App\Http\Dashboard;

use App\Enums\ActivityStatus;
use App\Enums\LeadStatus;
use App\Enums\OpportunityStage;
use App\Enums\Role;
use App\Integrations\HrGateway;
use App\Models\Activity;
use App\Models\Lead;
use App\Models\Opportunity;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The dashboard's staff scorecard (spec 14 §Staff performance, D2 phase 3).
 *
 * CRM output is aggregated from modules 01, 02, 04 and 05 for the people the caller may see
 * (salesrep → self, manager → the team, admin → every salesperson/manager). Attendance, leave
 * and late arrivals are joined in through the mocked `HrGateway`; when core2 cannot answer the
 * block is `null` and `meta.hr_available` is false, so the UI shows `—` rather than `0`.
 *
 * Definitions (all read-time, no stored dashboard table):
 * - `leads_handled` — live leads the person owns, captured in the period.
 * - `qualified` — leads that currently sit at qualified whose last lifecycle move is in the period.
 * - `deals_won` / `won_value` — won deals the person owns whose actual close date is in the period.
 * - `conversion_rate` — won ÷ (won + lost) closed deals in the period, the same rule as the
 *   dashboard's win rate, so the two numbers agree.
 * - `follow_up_on_time` — tasks due in the period that were marked done before their due moment,
 *   over every task due in the period; no tasks reads as 100% (nothing was missed).
 * - `csat` — the mean of scored survey answers on the person's accounts, submitted in the period.
 */
final class DashboardStaffPerformance
{
    private DashboardScope $scope;

    public function __construct(
        private readonly User $user,
        private readonly DashboardPeriod $period,
        private readonly HrGateway $hr,
    ) {
        $this->scope = DashboardScope::for($user);
    }

    /**
     * @return array{data: array<int, array<string, mixed>>, meta: array{period: string, hr_available: bool}}
     */
    public function build(): array
    {
        $staff = $this->staff();
        $ids = $staff->pluck('id')->map(fn (mixed $id): int => (int) $id)->all();

        $leads = $this->leadsHandled($ids);
        $qualified = $this->qualifiedLeads($ids);
        $closed = $this->closedDeals($ids);
        $csat = $this->csatAverages($ids);
        $followUps = $this->followUpPunctuality($ids);

        $hr = $ids === []
            ? []
            : $this->hr->attendance($ids, $this->period->startDate(), $this->period->endDate());
        $hrAvailable = $hr !== null;

        $rows = $staff
            ->map(function (User $member) use ($leads, $qualified, $closed, $csat, $followUps, $hr): array {
                $won = $closed['won'][$member->id] ?? ['count' => 0, 'value' => 0.0];
                $lost = $closed['lost'][$member->id] ?? ['count' => 0, 'value' => 0.0];
                $punctuality = $followUps[$member->id] ?? ['total' => 0, 'on_time' => 0];
                $hrEntry = $hr !== null ? ($hr[$member->id] ?? null) : null;

                return [
                    'user' => [
                        'id' => $member->id,
                        'name' => $member->name,
                        'job_title' => $this->jobTitle($member),
                        'team' => $member->team?->name ?? 'Unassigned',
                    ],
                    'leads_handled' => (int) ($leads[$member->id] ?? 0),
                    'qualified' => (int) ($qualified[$member->id] ?? 0),
                    'deals_won' => $won['count'],
                    'won_value' => $won['value'],
                    'conversion_rate' => $this->conversionRate($won['count'], $lost['count']),
                    'follow_up_on_time' => $this->followUpOnTime($punctuality['on_time'], $punctuality['total']),
                    'csat' => isset($csat[$member->id]) ? round((float) $csat[$member->id], 2) : null,
                    'hr' => $hrEntry?->toArray(),
                    'minTier' => $this->minTierFor($member),
                ];
            })
            ->filter(fn (array $row): bool => $this->scope->allows((string) $row['minTier']))
            ->values()
            ->all();

        return [
            'data' => $rows,
            'meta' => [
                'period' => $this->period->range->value,
                'hr_available' => $hrAvailable,
            ],
        ];
    }

    /**
     * The scorecards the caller may see. Admins see every salesperson and manager; a manager
     * sees their own row plus their team's; a salesrep sees only their own.
     *
     * @return Collection<int, User>
     */
    private function staff(): Collection
    {
        $query = User::query()
            ->whereIn('role', [Role::Salesrep->value, Role::Manager->value])
            ->with('team:id,name');

        if (! $this->user->role->isAdmin()) {
            if ($this->user->role === Role::Manager && $this->user->team_id !== null) {
                $query->where(function ($query): void {
                    $query->whereKey($this->user->id)
                        ->orWhere('team_id', $this->user->team_id);
                });
            } else {
                $query->whereKey($this->user->id);
            }
        }

        return $query
            ->orderBy('role')
            ->orderBy('name')
            ->get();
    }

    /**
     * @param  array<int, int>  $ids
     * @return Collection<int|string, int>
     */
    private function leadsHandled(array $ids): Collection
    {
        return Lead::query()
            ->whereIn('owner_id', $ids)
            ->where('status', '!=', LeadStatus::Archived)
            ->whereBetween('created_at', [$this->period->startUtc(), $this->period->endUtc()])
            ->selectRaw('owner_id, count(*) as aggregate')
            ->groupBy('owner_id')
            ->pluck('aggregate', 'owner_id');
    }

    /**
     * @param  array<int, int>  $ids
     * @return Collection<int|string, int>
     */
    private function qualifiedLeads(array $ids): Collection
    {
        return Lead::query()
            ->whereIn('owner_id', $ids)
            ->where('status', LeadStatus::Qualified)
            ->whereBetween('status_changed_at', [$this->period->startUtc(), $this->period->endUtc()])
            ->selectRaw('owner_id, count(*) as aggregate')
            ->groupBy('owner_id')
            ->pluck('aggregate', 'owner_id');
    }

    /**
     * @param  array<int, int>  $ids
     * @return array{won: array<int, array{count: int, value: float}>, lost: array<int, array{count: int, value: float}>}
     */
    private function closedDeals(array $ids): array
    {
        $rows = Opportunity::query()
            ->whereIn('owner_id', $ids)
            ->whereIn('stage', [OpportunityStage::Won, OpportunityStage::Lost])
            ->whereBetween('actual_close_date', [$this->period->startDate(), $this->period->endDate()])
            ->selectRaw('owner_id, stage, count(*) as total, coalesce(sum(amount), 0) as value')
            ->groupBy('owner_id', 'stage')
            ->get();

        $won = [];
        $lost = [];

        foreach ($rows as $row) {
            $entry = ['count' => (int) $row->total, 'value' => (float) $row->value];

            if ($row->stage === OpportunityStage::Won) {
                $won[(int) $row->owner_id] = $entry;
            } else {
                $lost[(int) $row->owner_id] = $entry;
            }
        }

        return ['won' => $won, 'lost' => $lost];
    }

    /**
     * @param  array<int, int>  $ids
     * @return Collection<int|string, float>
     */
    private function csatAverages(array $ids): Collection
    {
        return DB::table('survey_responses')
            ->join('companies', 'companies.id', '=', 'survey_responses.company_id')
            ->whereIn('companies.owner_id', $ids)
            ->whereNull('companies.deleted_at')
            ->whereBetween('survey_responses.submitted_at', [$this->period->startUtc(), $this->period->endUtc()])
            ->whereNotNull('survey_responses.csat')
            ->selectRaw('companies.owner_id as owner_id, avg(survey_responses.csat) as aggregate')
            ->groupBy('companies.owner_id')
            ->pluck('aggregate', 'owner_id');
    }

    /**
     * @param  array<int, int>  $ids
     * @return array<int, array{total: int, on_time: int}>
     */
    private function followUpPunctuality(array $ids): array
    {
        // A task has no completed_at column: its last update is when it was settled, so a
        // done task whose update landed after its due moment counts as late.
        return Activity::query()
            ->tasks()
            ->whereIn('owner_id', $ids)
            ->whereBetween('due_at', [$this->period->startUtc(), $this->period->endUtc()])
            ->selectRaw(
                'owner_id, count(*) as total, sum(case when status = ? and updated_at <= due_at then 1 else 0 end) as on_time',
                [ActivityStatus::Done->value],
            )
            ->groupBy('owner_id')
            ->get()
            ->mapWithKeys(fn (Activity $row): array => [
                (int) $row->owner_id => [
                    'total' => (int) $row->total,
                    'on_time' => (int) $row->on_time,
                ],
            ])
            ->all();
    }

    private function conversionRate(int $won, int $lost): float
    {
        $closed = $won + $lost;

        return $closed === 0 ? 0.0 : round(($won / $closed) * 100, 1);
    }

    private function followUpOnTime(int $onTime, int $total): float
    {
        return $total === 0 ? 100.0 : round(($onTime / $total) * 100, 1);
    }

    private function minTierFor(User $member): string
    {
        if ($member->is($this->user)) {
            return 'personal';
        }

        return $this->user->role->isAdmin() ? 'org' : 'team';
    }

    private function jobTitle(User $member): string
    {
        return match ($member->role) {
            Role::Manager => 'Sales manager',
            default => 'Sales representative',
        };
    }
}
