<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Delivery\DeliverySnapshot;
use App\Integrations\JobOrderSummary;
use App\Integrations\RecruitmentFeed;
use App\Models\Company;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Read-only delivery feed for one account (module 01 refresh S9, spec 16 §Read-only
 * external feeds, ADR-0025). Recruitment, Deployment and Timekeeping own their records;
 * this endpoint only composes their answers. There is deliberately no write route.
 */
class CompanyDeliveryController extends Controller
{
    public function __construct(
        private readonly RecruitmentFeed $recruitment,
        private readonly DeliverySnapshot $snapshot,
    ) {}

    public function show(Request $request, Company $company): JsonResponse
    {
        $this->authorize('view', $company);

        $jobOrders = $this->recruitment->jobOrders($company);

        $delivery = $this->snapshot->forCompany($company);

        return response()->json([
            'data' => [
                'handoff' => [
                    'status' => $company->handoff_status,
                    'reference' => $company->handoff_reference,
                    'error' => $company->handoff_error,
                    'requested_at' => $company->handoff_requested_at?->toIso8601String(),
                ],
                'job_orders' => array_map(
                    static fn (JobOrderSummary $order): array => $order->toArray(),
                    $jobOrders,
                ),
                'deployments' => $delivery['rows'],
                'timesheets' => [
                    'approved_hours' => $delivery['totals']['approved_hours'],
                    'pending_hours' => $delivery['totals']['pending_hours'],
                    'deployments_pending' => $delivery['totals']['pending_timesheets'],
                    'pending_timesheets' => $delivery['totals']['pending_timesheets'],
                ],
            ],
            'meta' => [
                'source' => 'Recruitment / Deployment / Timekeeping (mocked, read-only)',
                'read_only' => true,
                'as_of' => now()->toIso8601String(),
            ],
        ]);
    }
}
