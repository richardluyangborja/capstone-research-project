<?php

namespace App\Http\Controllers\Api\V1;

use App\Events\CompanyCreated;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreCompanyRequest;
use App\Http\Requests\UpdateCompanyRequest;
use App\Http\Resources\CompanyResource;
use App\Models\Company;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CompanyController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Company::class);

        /** @var array<string, mixed> $filters */
        $filters = (array) $request->input('filter', []);

        $companies = Company::query()
            ->with(['owner', 'parent'])
            ->withCount(['sites', 'children', 'contacts', 'opportunities', 'contracts', 'activities'])
            ->visibleTo($request->user())
            ->search($request->string('search')->toString() ?: null)
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->whereIn('status', explode(',', (string) $status)))
            ->when($filters['tier'] ?? null, fn ($query, $tier) => $query->whereIn('tier', explode(',', (string) $tier)))
            ->when($filters['industry'] ?? null, fn ($query, $industry) => $query->where('industry', $industry))
            ->when($filters['owner_id'] ?? null, fn ($query, $owner) => $query->where('owner_id', $owner))
            ->when($filters['parent_company_id'] ?? null, fn ($query, $parent) => $query->where('parent_company_id', $parent))
            ->orderByDesc('created_at')
            ->paginate(min((int) $request->integer('per_page', 25), 100))
            ->appends($request->query());

        return CompanyResource::collection($companies);
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

        return new CompanyResource($this->withRelations($company));
    }

    public function update(UpdateCompanyRequest $request, Company $company): CompanyResource
    {
        $company->update($request->validated());

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
        return $company->load(['owner', 'parent'])->loadCount(['sites', 'children', 'contacts', 'opportunities', 'contracts', 'activities']);
    }
}
