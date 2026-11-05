<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ContractStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\ContractResource;
use App\Models\Company;
use App\Models\Contract;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Read-only contracts projected onto one company.
 */
class CompanyContractController extends Controller
{
    public function index(Request $request, Company $company): AnonymousResourceCollection
    {
        $this->authorize('view', $company);

        /** @var array<string, mixed> $filters */
        $filters = (array) $request->input('filter', []);

        $contracts = $company->contracts()
            ->with(['owner:id,name', 'renewalOf:id,reference,title,status,start_date,end_date', 'rateCards.site'])
            ->visibleTo($request->user())
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->whereIn('status', explode(',', (string) $status)))
            ->when($filters['expiring'] ?? null, fn ($query) => $query
                ->where('status', ContractStatus::Signed->value)
                ->whereNotNull('end_date')
                ->whereDate('end_date', '<=', now()->addDays((int) config('crm.contracts.expiring_soon_days'))))
            ->orderByDesc('created_at')
            ->get();

        return ContractResource::collection($contracts)->additional([
            'meta' => [
                'source' => 'Contract Management (mocked, read-only)',
                'read_only' => true,
                'as_of' => now()->toIso8601String(),
                'total' => $contracts->count(),
                'active' => $contracts->filter(fn (Contract $contract): bool => $contract->isActive())->count(),
            ],
        ]);
    }
}
