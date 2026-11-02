<?php

namespace App\Http\Controllers\Api\V1;

use App\Events\CompanyCreated;
use App\Http\Companies\AccountBookMetrics;
use App\Http\Companies\AccountHealth;
use App\Http\Companies\CompanyPayload;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreCompanyRequest;
use App\Http\Requests\UpdateCompanyRequest;
use App\Http\Resources\CompanyResource;
use App\Integrations\FinanceBilling;
use App\Models\Company;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CompanyController extends Controller
{
    public function __construct(
        private readonly FinanceBilling $billing,
        private readonly AccountHealth $health,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Company::class);

        /** @var array<string, mixed> $filters */
        $filters = (array) $request->input('filter', []);
        $perPage = max(1, min((int) $request->integer('per_page', 25), 100));

        $query = Company::query()
            ->with(['owner', 'parent'])
            ->withCount([
                'sites',
                'children',
                'contacts',
                'opportunities',
                'contracts',
                'activities',
                'communications',
                // The Account Book's "open deals" column (spec 16 §Account Book).
                'openOpportunities as open_opportunities_count',
            ])
            ->visibleTo($request->user())
            ->search($request->string('search')->toString() ?: null)
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->whereIn('status', explode(',', (string) $status)))
            ->when($filters['tier'] ?? null, fn ($query, $tier) => $query->whereIn('tier', explode(',', (string) $tier)))
            ->when($filters['industry'] ?? null, fn ($query, $industry) => $query->whereRaw(
                'LOWER(industry) LIKE ?',
                ['%'.mb_strtolower((string) $industry).'%'],
            ))
            ->when($filters['owner_id'] ?? null, fn ($query, $owner) => $query->where('owner_id', $owner))
            ->when($filters['parent_company_id'] ?? null, fn ($query, $parent) => $query->where('parent_company_id', $parent))
            ->orderByDesc('created_at');

        // Health is computed, not stored (spec 16 S13). A health filter therefore has to
        // narrow the visible set before the page is cut, while the unfiltered book keeps the
        // normal SQL pagination and computes health for that page only.
        $health = trim((string) ($filters['health'] ?? ''));

        if ($health === '') {
            $companies = $query
                ->paginate($perPage)
                ->appends($request->query());

            AccountBookMetrics::attach($companies->getCollection());
            $this->health->attach($companies->getCollection(), $request->user());

            return CompanyResource::collection($companies);
        }

        $all = $query->get();
        AccountBookMetrics::attach($all);
        $this->health->attach($all, $request->user());

        $states = array_values(array_filter(explode(',', $health)));
        $filtered = $all
            ->filter(fn (Company $company): bool => in_array(
                $company->getAttribute('health')['state'] ?? '',
                $states,
                true,
            ))
            ->values();

        $page = max(1, (int) $request->integer('page', 1));
        $paginator = new LengthAwarePaginator(
            $filtered->forPage($page, $perPage)->values(),
            $filtered->count(),
            $perPage,
            $page,
            ['path' => $request->url(), 'query' => $request->query()],
        );

        return CompanyResource::collection($paginator);
    }

    public function store(StoreCompanyRequest $request): JsonResponse
    {
        $data = $request->validated();
        $duplicates = $this->findDuplicates($data['name']);

        $company = DB::transaction(function () use ($data, $request): Company {
            // A company always has an owner (spec 06 §7); default to whoever created it.
            $data['owner_id'] ??= $request->user()->id;

            $company = Company::create($data);

            CompanyCreated::dispatch($company, $request->user());

            return $company;
        });

        return (new CompanyResource($this->withRelations($company)))
            ->additional(['duplicates' => $duplicates])
            ->response()
            ->setStatusCode(201);
    }

    public function show(Company $company): CompanyResource
    {
        $this->authorize('view', $company);

        $company = $this->withRelations($company);
        $this->attachBillingRisk($company, request()->user());

        return new CompanyResource($company);
    }

    public function update(UpdateCompanyRequest $request, Company $company): CompanyResource
    {
        $data = $request->validated();
        $ownerId = $data['owner_id'] ?? null;
        unset($data['owner_id']);

        $company->update($data);

        // Ownership changes run through the same delegation path as the assign endpoints, so a
        // PATCH cannot move an account without its activity + notification (spec 16 S5).
        if ($ownerId !== null) {
            $company->assignTo(User::findOrFail($ownerId), $request->user());
        }

        return new CompanyResource($this->withRelations($company->refresh()));
    }

    public function destroy(Company $company): Response
    {
        $this->authorize('delete', $company);

        $blockers = $company->deletionBlockers();

        if ($blockers !== []) {
            throw ValidationException::withMessages(['company' => $blockers]);
        }

        $company->delete();

        return response()->noContent();
    }

    public function restore(string $id): CompanyResource
    {
        $company = Company::withTrashed()->findOrFail($id);

        $this->authorize('restore', $company);

        $company->restore();

        return new CompanyResource($this->withRelations($company));
    }

    /**
     * Advisory duplicate check on the company name (spec 06 §2): warn, never block.
     *
     * @return array<int, array<string, mixed>>
     */
    private function findDuplicates(string $name): array
    {
        $needle = mb_strtolower(trim($name));

        return Company::query()
            ->where(function ($query) use ($needle): void {
                $query->whereRaw('LOWER(name) = ?', [$needle])
                    ->orWhereRaw('LOWER(trade_name) = ?', [$needle]);
            })
            ->limit(10)
            ->get(['id', 'reference', 'name', 'status'])
            ->map(fn (Company $company): array => [
                'id' => $company->id,
                'reference' => $company->reference,
                'name' => $company->name,
                'status' => $company->status,
            ])
            ->all();
    }

    private function withRelations(Company $company): Company
    {
        return CompanyPayload::load($company);
    }

    /**
     * The owner-visible half of the Finance feed (spec 16 S11): a redacted overdue flag on
     * the account detail, never an amount. Managers get the full snapshot from the billing
     * endpoint instead.
     */
    private function attachBillingRisk(Company $company, ?User $user): void
    {
        if ($user === null || $user->can('viewBilling', $company)) {
            return;
        }

        $summary = $this->billing->summary($company);

        $company->setAttribute('billing_risk', [
            'has_overdue' => $summary->overdueInvoices > 0,
            'message' => $summary->overdueInvoices > 0
                ? 'Finance reports an overdue balance on this account. Follow up with Finance.'
                : null,
        ]);
    }
}
