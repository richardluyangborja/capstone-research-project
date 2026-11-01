<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Integrations\BillingSummary;
use App\Integrations\FinanceBilling;
use App\Models\Company;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * The scoped cross-account AR book (module 01 refresh S12, spec 16 §Read-only external
 * feeds, ADR-0025). Finance owns the invoices and balances; this read only flattens its
 * answer over the accounts the caller may see. There is no write route.
 */
class BillingBookController extends Controller
{
    public function __construct(private readonly FinanceBilling $billing) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewBillingBook', Company::class);

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
                '%d|%015d|%015d|%s',
                match ($row['account_status']) {
                    'overdue' => 0,
                    'current' => 1,
                    default => 2,
                },
                999999999999999 - (int) round((float) $row['overdue'] * 100),
                999999999999999 - (int) round((float) $row['outstanding'] * 100),
                mb_strtolower($row['company']['name']),
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
                'source' => 'Finance (mocked)',
                'read_only' => true,
                'as_of' => now()->toIso8601String(),
            ],
            'summary' => $this->summary($rows),
        ]);
    }

    /**
     * One row per account with an invoice history. A prospect with no live AR never enters
     * the book (spec 16 §Demo data).
     *
     * @param  Collection<int, Company>  $companies
     * @return array<int, array<string, mixed>>
     */
    private function rows(Collection $companies): array
    {
        $rows = [];

        foreach ($companies as $company) {
            $summary = $this->billing->summary($company);

            if ($summary->invoices === []) {
                continue;
            }

            $rows[] = $this->row($company, $summary);
        }

        return $rows;
    }

    /**
     * @return array<string, mixed>
     */
    private function row(Company $company, BillingSummary $summary): array
    {
        return [
            'company' => [
                'id' => $company->id,
                'reference' => $company->reference,
                'name' => $company->name,
            ],
            'owner' => $company->owner === null ? null : [
                'id' => $company->owner->id,
                'name' => $company->owner->name,
            ],
            'account_status' => $summary->accountStatus,
            'outstanding' => number_format($summary->outstanding, 2, '.', ''),
            'overdue' => number_format($summary->overdue, 2, '.', ''),
            'overdue_invoices' => $summary->overdueInvoices,
            'invoice_count' => count($summary->invoices),
            'open_invoices' => count(array_filter(
                $summary->invoices,
                static fn ($invoice): bool => $invoice->status !== 'paid',
            )),
            'last_invoice_date' => $summary->lastInvoiceDate,
            'next_due_date' => $summary->nextDueDate,
            'credit_terms' => $summary->creditTerms,
            'currency' => $summary->currency,
            'aging' => array_map(
                static fn (float $amount): string => number_format($amount, 2, '.', ''),
                $summary->aging,
            ),
        ];
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
        $owner = $filters['owner'] ?? $filters['owner_id'] ?? null;
        $aging = $filters['aging'] ?? null;
        $overdue = array_key_exists('overdue', $filters) && $filters['overdue'] !== null && $filters['overdue'] !== ''
            ? filter_var($filters['overdue'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE)
            : null;
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

        $buckets = $aging === null || $aging === ''
            ? []
            : array_values(array_filter(explode(',', (string) $aging)));

        return collect($rows)
            ->when($status, fn (Collection $rows, $status): Collection => $rows->whereIn(
                'account_status',
                array_values(array_filter(explode(',', (string) $status))),
            ))
            ->when($company, fn (Collection $rows, $company): Collection => $rows->where(
                'company.id',
                (string) $company,
            ))
            ->when($ownerCompanyIds !== null, fn (Collection $rows): Collection => $rows->whereIn(
                'company.id',
                $ownerCompanyIds,
            ))
            ->when($buckets !== [], fn (Collection $rows): Collection => $rows->filter(
                function (array $row) use ($buckets): bool {
                    foreach ($buckets as $bucket) {
                        if ((float) ($row['aging'][$bucket] ?? 0) > 0) {
                            return true;
                        }
                    }

                    return false;
                },
            ))
            ->when($overdue === true, fn (Collection $rows): Collection => $rows->filter(
                fn (array $row): bool => (float) $row['overdue'] > 0,
            ))
            ->when($overdue === false, fn (Collection $rows): Collection => $rows->filter(
                fn (array $row): bool => (float) $row['overdue'] <= 0,
            ))
            ->when($search !== '', fn (Collection $rows): Collection => $rows->filter(
                fn (array $row): bool => str_contains(
                    mb_strtolower(implode(' ', [
                        $row['company']['name'],
                        $row['company']['reference'],
                        $row['owner']['name'] ?? '',
                        $row['credit_terms'],
                    ])),
                    $search,
                ),
            ))
            ->values();
    }

    /**
     * Scope-wide cards, like the contract and deployment books: filters narrow the table,
     * not the summary.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    private function summary(array $rows): array
    {
        $overdue = collect($rows)->filter(fn (array $row): bool => (float) $row['overdue'] > 0);

        return [
            'accounts' => count($rows),
            'outstanding' => number_format((float) collect($rows)->sum('outstanding'), 2, '.', ''),
            'overdue' => number_format((float) $overdue->sum('overdue'), 2, '.', ''),
            'overdue_accounts' => $overdue->count(),
            'open_invoices' => (int) collect($rows)->sum('open_invoices'),
            'aging' => [
                'current' => number_format((float) collect($rows)->sum(
                    fn (array $row): float => (float) $row['aging']['current'],
                ), 2, '.', ''),
                '1_30' => number_format((float) collect($rows)->sum(
                    fn (array $row): float => (float) $row['aging']['1_30'],
                ), 2, '.', ''),
                '31_60' => number_format((float) collect($rows)->sum(
                    fn (array $row): float => (float) $row['aging']['31_60'],
                ), 2, '.', ''),
                '60_plus' => number_format((float) collect($rows)->sum(
                    fn (array $row): float => (float) $row['aging']['60_plus'],
                ), 2, '.', ''),
            ],
        ];
    }
}
