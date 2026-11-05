<?php

namespace App\Http\Delivery;

use App\Integrations\DeploymentFeed;
use App\Integrations\DeploymentSummary;
use App\Integrations\TimekeepingFeed;
use App\Integrations\TimesheetSummary;
use App\Models\Company;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

/**
 * Composes the scoped Deployment and Timekeeping reads once so every CRM
 * delivery surface uses the same pending-timesheet definition.
 */
final class DeliverySnapshot
{
    public function __construct(
        private readonly DeploymentFeed $deployments,
        private readonly TimekeepingFeed $timekeeping,
    ) {}

    /**
     * @param  Collection<int, Company>|EloquentCollection<int, Company>  $companies
     * @return array{rows: array<int, array<string, mixed>>, totals: array<string, mixed>}
     */
    public function forCompanies(Collection|EloquentCollection $companies): array
    {
        $rows = [];

        foreach ($companies as $company) {
            foreach ($this->deployments->deployments($company) as $deployment) {
                $rows[] = $this->row($company, $deployment);
            }
        }

        return [
            'rows' => $rows,
            'totals' => $this->totals($rows),
        ];
    }

    /**
     * @return array{rows: array<int, array<string, mixed>>, totals: array<string, mixed>}
     */
    public function forCompany(Company $company): array
    {
        return $this->forCompanies(new Collection([$company]));
    }

    public static function needsCurrentTimesheet(?TimesheetSummary $timesheet): bool
    {
        return $timesheet === null || $timesheet->status !== 'current';
    }

    public static function hasMissingTimesheet(?TimesheetSummary $timesheet): bool
    {
        return $timesheet === null || $timesheet->status === 'missing';
    }

    /**
     * @return array<string, mixed>
     */
    private function row(Company $company, DeploymentSummary $deployment): array
    {
        return $deployment
            ->withTimesheet($this->timekeeping->summary($company, $deployment))
            ->toArray() + [
                'company' => [
                    'id' => $company->id,
                    'reference' => $company->reference,
                    'name' => $company->name,
                ],
                'owner' => $company->owner === null ? null : [
                    'id' => $company->owner->id,
                    'name' => $company->owner->name,
                ],
            ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    private function totals(array $rows): array
    {
        $collection = collect($rows);
        $open = $collection->whereIn('status', ['active', 'pending']);
        $requested = (int) $open->sum('requested_headcount');
        $active = (int) $open->sum('active_headcount');

        return [
            'total' => count($rows),
            'active' => $collection->where('status', 'active')->count(),
            'pending' => $collection->where('status', 'pending')->count(),
            'ended' => $collection->where('status', 'ended')->count(),
            'fill_rate' => $requested === 0 ? 0 : (int) round(($active / $requested) * 100),
            'pending_timesheets' => $collection->filter(
                fn (array $row): bool => ($row['timesheet']['status'] ?? null) !== 'current',
            )->count(),
            'approved_hours' => round((float) $collection->sum(
                fn (array $row): float => (float) ($row['timesheet']['approved_hours'] ?? 0),
            ), 2),
            'pending_hours' => round((float) $collection->sum(
                fn (array $row): float => (float) ($row['timesheet']['pending_hours'] ?? 0),
            ), 2),
        ];
    }
}
