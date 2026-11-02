<?php

namespace App\Http\Dashboard;

use App\Enums\ActivityStatus;
use App\Enums\ContractStatus;
use App\Enums\LeadStatus;
use App\Enums\Role;
use App\Http\Companies\AccountHealth;
use App\Integrations\DeploymentFeed;
use App\Integrations\FinanceBilling;
use App\Integrations\TimekeepingFeed;
use App\Models\Activity;
use App\Models\Communication;
use App\Models\Company;
use App\Models\Contract;
use App\Models\Lead;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * The manager+ dashboard strip (module 01 refresh S14, spec 16 §Manager dashboard).
 *
 * Three read-only blocks over the same scope as the rest of the dashboard: commercial and
 * delivery totals, the highest-severity account-health rows and per-rep workload. Nothing is
 * stored; every value is derived on read from the owning module or the read-only feeds.
 */
final class DashboardManager
{
    private const SEVERITY_RANK = ['critical' => 0, 'warning' => 1, 'info' => 2];

    public function __construct(
        private readonly User $user,
        private readonly AccountHealth $health,
        private readonly FinanceBilling $billing,
        private readonly DeploymentFeed $deployments,
        private readonly TimekeepingFeed $timekeeping,
    ) {}

    /**
     * @return array{commercial: array<string, mixed>, account_risks: array<string, mixed>, team_workload: array<string, mixed>}
     */
    public function build(): array
    {
        return [
            'commercial' => $this->commercial(),
            'account_risks' => $this->accountRisks(),
            'team_workload' => $this->teamWorkload(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function commercial(): array
    {
        $companies = Company::query()
            ->visibleTo($this->user)
            ->get(['id', 'name', 'reference']);

        $outstanding = 0.0;
        $overdue = 0.0;
        $overdueAccounts = 0;

        foreach ($companies as $company) {
            $summary = $this->billing->summary($company);
            $outstanding += $summary->outstanding;
            $overdue += $summary->overdue;

            if ($summary->overdue > 0) {
                $overdueAccounts++;
            }
        }

        $active = 0;
        $requested = 0;
        $activeHeads = 0;
        $pendingTimesheets = 0;

        foreach ($companies as $company) {
            foreach ($this->deployments->deployments($company) as $deployment) {
                $deployment = $deployment->withTimesheet(
                    $this->timekeeping->summary($company, $deployment),
                );

                if ($deployment->status === 'active') {
                    $active++;
                }

                if (in_array($deployment->status, ['active', 'pending'], true)) {
                    $requested += $deployment->requestedHeadcount;
                    $activeHeads += $deployment->activeHeadcount;
                }

                if ($deployment->timesheet !== null && $deployment->timesheet->status !== 'current') {
                    $pendingTimesheets++;
                }
            }
        }

        return [
            'outstanding' => round($outstanding, 2),
            'overdue' => round($overdue, 2),
            'overdue_accounts' => $overdueAccounts,
            'renewals' => $this->renewals(),
            'active_deployments' => $active,
            'fill_rate' => $requested === 0 ? 0 : (int) round(($activeHeads / $requested) * 100),
            'pending_timesheets' => $pendingTimesheets,
            'source' => 'Finance / Deployment / Timekeeping (mocked)',
            'as_of' => now()->toIso8601String(),
        ];
    }

    /**
     * Signed contracts ending inside 30/60/90 days. A contract with a signed renewal is
     * already handled and does not count again.
     *
     * @return array{days_30: int, days_60: int, days_90: int}
     */
    private function renewals(): array
    {
        $today = CarbonImmutable::now((string) config('crm.timezone', 'Asia/Manila'))->startOfDay();
        $counts = ['days_30' => 0, 'days_60' => 0, 'days_90' => 0];

        $endDates = Contract::query()
            ->visibleTo($this->user)
            ->where('status', ContractStatus::Signed)
            ->whereNotNull('end_date')
            ->whereDate('end_date', '>=', $today->toDateString())
            ->whereDate('end_date', '<=', $today->copy()->addDays(90)->toDateString())
            ->whereDoesntHave('renewals', fn ($query) => $query->where('status', ContractStatus::Signed))
            ->pluck('end_date');

        foreach ($endDates as $endDate) {
            $days = max(0, (int) $today->diffInDays(
                CarbonImmutable::parse($endDate)->setTimezone($today->getTimezone())->startOfDay(),
            ));

            if ($days <= 30) {
                $counts['days_30']++;
            }

            if ($days <= 60) {
                $counts['days_60']++;
            }

            if ($days <= 90) {
                $counts['days_90']++;
            }
        }

        return $counts;
    }

    /**
     * The critical and warning health flags in the caller's scope, highest first. The block is
     * bounded so a large org still renders quickly; `total` keeps the omitted count honest.
     *
     * @return array{total: int, rows: array<int, array<string, mixed>>}
     */
    private function accountRisks(): array
    {
        $rows = [];

        $companies = Company::query()
            ->visibleTo($this->user)
            ->with('owner:id,name,team_id')
            ->get(['id', 'reference', 'name', 'owner_id']);

        foreach ($companies as $company) {
            $health = $this->health->forCompany($company, $this->user);

            foreach ($health['flags'] as $flag) {
                if ($flag['severity'] === 'info') {
                    continue;
                }

                $rows[] = [
                    'id' => $company->id.':'.$flag['code'],
                    'severity' => $flag['severity'],
                    'state' => $health['state'],
                    'account' => [
                        'id' => $company->id,
                        'reference' => $company->reference,
                        'name' => $company->name,
                    ],
                    'flag' => [
                        'code' => $flag['code'],
                        'title' => $flag['title'],
                        'reason' => $flag['reason'],
                        'source' => $flag['source'],
                    ],
                    'owner' => $company->owner === null ? null : [
                        'id' => $company->owner->id,
                        'name' => $company->owner->name,
                    ],
                    'action' => [
                        'label' => $flag['action']['label'],
                        'href' => $flag['action']['url'],
                    ],
                ];
            }
        }

        usort($rows, function (array $left, array $right): int {
            $rank = self::SEVERITY_RANK[$left['severity']] <=> self::SEVERITY_RANK[$right['severity']];

            if ($rank !== 0) {
                return $rank;
            }

            return strcmp(mb_strtolower($left['account']['name']), mb_strtolower($right['account']['name']));
        });

        return [
            'total' => count($rows),
            'rows' => array_slice($rows, 0, 8),
        ];
    }

    /**
     * @return array{unassigned: int, reps: array<int, array<string, mixed>>}
     */
    private function teamWorkload(): array
    {
        $reps = User::query()
            ->where('role', Role::Salesrep)
            ->when(
                $this->user->role === Role::Manager,
                fn ($query) => $query->where('team_id', $this->user->team_id),
            )
            ->orderBy('name')
            ->get(['id', 'name']);

        $ids = $reps->pluck('id')->all();

        $openLeads = $this->countsByOwner(
            Lead::query()
                ->whereIn('owner_id', $ids)
                ->whereIn('status', [
                    LeadStatus::New,
                    LeadStatus::Contacted,
                    LeadStatus::Qualified,
                    LeadStatus::Nurture,
                ]),
        );

        $overdueTasks = $this->countsByOwner(
            Activity::query()
                ->tasks()
                ->where('status', ActivityStatus::Open)
                ->whereNotNull('due_at')
                ->where('due_at', '<', now())
                ->whereIn('owner_id', $ids),
        );

        $overdueMessages = $this->countsByOwner(
            Communication::query()
                ->scheduled()
                ->whereNotNull('scheduled_for')
                ->where('scheduled_for', '<', now())
                ->whereIn('owner_id', $ids),
        );

        $accounts = $this->countsByOwner(
            Company::query()->whereIn('owner_id', $ids),
        );

        $unassigned = Lead::query()
            ->visibleTo($this->user)
            ->whereNull('owner_id')
            ->where('status', '!=', LeadStatus::Archived)
            ->where('created_at', '<=', now()->subDay())
            ->count();

        return [
            'unassigned' => $unassigned,
            'reps' => $reps->map(fn (User $rep): array => [
                'id' => $rep->id,
                'name' => $rep->name,
                'open_leads' => (int) ($openLeads[$rep->id] ?? 0),
                'overdue_follow_ups' => (int) ($overdueTasks[$rep->id] ?? 0)
                    + (int) ($overdueMessages[$rep->id] ?? 0),
                'accounts_owned' => (int) ($accounts[$rep->id] ?? 0),
            ])->values()->all(),
        ];
    }

    /**
     * @return array<int, int>
     */
    private function countsByOwner(Builder $query): array
    {
        return $query
            ->selectRaw('owner_id, count(*) as aggregate')
            ->groupBy('owner_id')
            ->pluck('aggregate', 'owner_id')
            ->map(fn ($count): int => (int) $count)
            ->all();
    }
}
