<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Integrations\DeploymentFeed;
use App\Integrations\TimekeepingFeed;
use App\Models\Company;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * The scoped cross-account deployment book (module 01 refresh S10, spec 16 §Read-only
 * external feeds, ADR-0025). Deployment and Timekeeping own the records; this read only
 * flattens their answers over the accounts the caller may see. There is no write route.
 */
class DeploymentBookController extends Controller
{
    public function __construct(
        private readonly DeploymentFeed $deploymentFeed,
        private readonly TimekeepingFeed $timekeepingFeed,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Company::class);

        /** @var array<string, mixed> $filters */
        $filters = (array) $request->input('filter', []);
        $user = $request->user();

        $companies = Company::query()
            ->visibleTo($user)
            ->with('owner:id,name,team_id')
            ->orderBy('name')
            ->get();

        $rows = $this->rows($companies);
        $search = trim((string) $request->input('search', $filters['search'] ?? ''));

        $filtered = $this->filter($rows, $filters, $companies, $user->id, $user->team_id, $search);
        $sorted = $filtered
            ->sortBy(fn (array $row): string => sprintf(
                '%d|%s|%s',
                match ($row['status']) {
                    'active' => 0,
                    'pending' => 1,
                    default => 2,
                },
                mb_strtolower($row['company']['name']),
                mb_strtolower($row['role']),
            ))
            ->values();

        $perPage = max(1, min((int) $request->integer('per_page', 25), 100));
        $page = max(1, (int) $request->integer('page', 1));
        $total = $sorted->count();
        $lastPage = max(1, (int) ceil($total / $perPage));
        $items = $sorted->forPage($page, $perPage)->values()->all();

        return response()->json([
            'data' => $items,
            'meta' => [
                'current_page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'last_page' => $lastPage,
                'source' => 'Deployment / Timekeeping (mocked, read-only)',
                'read_only' => true,
                'as_of' => now()->toIso8601String(),
            ],
            'summary' => $this->summary($rows),
        ]);
    }

    /**
     * @param  Collection<int, Company>  $companies
     * @return array<int, array<string, mixed>>
     */
    private function rows(Collection $companies): array
    {
        $rows = [];

        foreach ($companies as $company) {
            foreach ($this->deploymentFeed->deployments($company) as $deployment) {
                $deployment = $deployment->withTimesheet(
                    $this->timekeepingFeed->summary($company, $deployment),
                );

                $rows[] = $deployment->toArray() + [
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
        }

        return $rows;
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @param  array<string, mixed>  $filters
     * @param  Collection<int, Company>  $companies
     * @return Collection<int, array<string, mixed>>
     */
    private function filter(
        array $rows,
        array $filters,
        Collection $companies,
        int $userId,
        ?string $teamId,
        string $search,
    ): Collection {
        $status = $filters['status'] ?? null;
        $company = $filters['company'] ?? $filters['company_id'] ?? null;
        $contract = $filters['contract'] ?? $filters['contract_id'] ?? null;
        $owner = $filters['owner'] ?? $filters['owner_id'] ?? null;
        $search = mb_strtolower($search);

        $ownerCompanyIds = $owner === null || $owner === ''
            ? null
            : $companies
                ->filter(function (Company $company) use ($owner, $userId, $teamId): bool {
                    return match ((string) $owner) {
                        'me' => $company->owner_id === $userId,
                        'team' => $teamId !== null && $company->owner?->team_id === $teamId,
                        default => $company->owner_id === (int) $owner,
                    };
                })
                ->pluck('id')
                ->all();

        return collect($rows)
            ->when($status, fn (Collection $rows, $status): Collection => $rows->whereIn(
                'status',
                array_values(array_filter(explode(',', (string) $status))),
            ))
            ->when($company, fn (Collection $rows, $company): Collection => $rows->where(
                'company.id',
                (string) $company,
            ))
            ->when($contract, fn (Collection $rows, $contract): Collection => $rows->filter(
                fn (array $row): bool => $row['contract_id'] === (string) $contract
                    || strcasecmp($row['contract_reference'], (string) $contract) === 0,
            ))
            ->when($ownerCompanyIds !== null, fn (Collection $rows): Collection => $rows->whereIn(
                'company.id',
                $ownerCompanyIds,
            ))
            ->when($search !== '', fn (Collection $rows): Collection => $rows->filter(
                fn (array $row): bool => str_contains(
                    mb_strtolower(implode(' ', [
                        $row['reference'],
                        $row['role'],
                        $row['site'],
                        $row['contract_reference'],
                        $row['company']['name'],
                        $row['company']['reference'],
                    ])),
                    $search,
                ),
            ))
            ->values();
    }

    /**
     * Scope-wide cards, like the contract book: filters narrow the table, not the summary.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    private function summary(array $rows): array
    {
        $open = collect($rows)->whereIn('status', ['active', 'pending']);
        $requested = (int) $open->sum('requested_headcount');
        $active = (int) $open->sum('active_headcount');
        $pendingTimesheets = collect($rows)->filter(
            fn (array $row): bool => ($row['timesheet']['status'] ?? null) !== null
                && $row['timesheet']['status'] !== 'current',
        )->count();

        return [
            'total' => count($rows),
            'active' => collect($rows)->where('status', 'active')->count(),
            'pending' => collect($rows)->where('status', 'pending')->count(),
            'ended' => collect($rows)->where('status', 'ended')->count(),
            'fill_rate' => $requested === 0 ? 0 : (int) round(($active / $requested) * 100),
            'deployments_pending' => $pendingTimesheets,
            'approved_hours' => round((float) collect($rows)->sum(
                fn (array $row): float => (float) ($row['timesheet']['approved_hours'] ?? 0),
            ), 2),
            'pending_hours' => round((float) collect($rows)->sum(
                fn (array $row): float => (float) ($row['timesheet']['pending_hours'] ?? 0),
            ), 2),
        ];
    }
}
