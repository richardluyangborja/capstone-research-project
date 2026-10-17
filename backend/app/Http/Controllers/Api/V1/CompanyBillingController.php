<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Integrations\FinanceBilling;
use App\Models\Company;
use Illuminate\Http\JsonResponse;

/**
 * Read-only billing/AR snapshot, consumed from the (mocked) Finance department (spec 09 §7,
 * ADR-0005). Hidden from salesreps and never writable: the CRM displays, Finance owns.
 */
class CompanyBillingController extends Controller
{
    public function __construct(private readonly FinanceBilling $billing) {}

    public function show(Company $company): JsonResponse
    {
        $this->authorize('viewBilling', $company);

        return response()->json([
            'data' => $this->billing->summary($company)->toArray(),
            'meta' => [
                'source' => 'Finance (mocked)',
                'read_only' => true,
                'as_of' => now()->toIso8601String(),
            ],
        ]);
    }
}
