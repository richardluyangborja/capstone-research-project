<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Delivery\DeliverySnapshot;
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
        private readonly DeliverySnapshot $snapshot,
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

        $snapshot = $this->snapshot->forCompanies($companies);
        $rows = $snapshot['rows'];
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
            'summary' => $snapshot['totals'] + [
                'deployments_pending' => $snapshot['totals']['pending_timesheets'],
            ],
        ]);
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
        $timesheet = $filters['timesheet'] ?? null;
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
            ->when($timesheet, fn (Collection $rows, $timesheet): Collection => $rows->filter(
                fn (array $row): bool => match ((string) $timesheet) {
                    'current' => ($row['timesheet']['status'] ?? null) === 'current',
                    'missing' => ($row['timesheet']['status'] ?? null) === null
                        || ($row['timesheet']['status'] ?? null) === 'missing',
                    'pending' => ($row['timesheet']['status'] ?? null) !== 'current',
                    default => true,
                },
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
}
