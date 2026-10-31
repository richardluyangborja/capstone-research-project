<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\HandoffStatus;
use App\Http\Controllers\Controller;
use App\Integrations\DeploymentFeed;
use App\Integrations\DeploymentSummary;
use App\Integrations\JobOrderSummary;
use App\Integrations\RecruitmentFeed;
use App\Integrations\TimekeepingFeed;
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
        private readonly DeploymentFeed $deployment,
        private readonly TimekeepingFeed $timekeeping,
    ) {}

    public function show(Request $request, Company $company): JsonResponse
    {
        $this->authorize('view', $company);

        $jobOrders = $this->recruitment->jobOrders($company);

        $deployments = array_map(
            fn (DeploymentSummary $deployment): DeploymentSummary => $deployment->withTimesheet(
                $this->timekeeping->summary($company, $deployment),
            ),
            $this->deployment->deployments($company),
        );

        $approvedHours = array_sum(array_map(
            static fn (DeploymentSummary $deployment): float => $deployment->timesheet?->approvedHours ?? 0.0,
            $deployments,
        ));
        $pendingHours = array_sum(array_map(
            static fn (DeploymentSummary $deployment): float => $deployment->timesheet?->pendingHours ?? 0.0,
            $deployments,
        ));
        $deploymentsPending = count(array_filter(
            $deployments,
            static fn (DeploymentSummary $deployment): bool => $deployment->timesheet !== null
                && $deployment->timesheet->status !== 'current',
        ));

        return response()->json([
            'data' => [
                'handoff' => [
                    'status' => $company->handoff_status,
                    'reference' => $company->handoff_reference,
                    'error' => $company->handoff_error,
                    'requested_at' => $company->handoff_requested_at?->toIso8601String(),
                    'can_retry' => $company->handoff_status === HandoffStatus::Failed
                        && (bool) $request->user()?->can('retryHandoff', $company),
                ],
                'job_orders' => array_map(
                    static fn (JobOrderSummary $order): array => $order->toArray(),
                    $jobOrders,
                ),
                'deployments' => array_map(
                    static fn (DeploymentSummary $deployment): array => $deployment->toArray(),
                    $deployments,
                ),
                'timesheets' => [
                    'approved_hours' => round($approvedHours, 2),
                    'pending_hours' => round($pendingHours, 2),
                    'deployments_pending' => $deploymentsPending,
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
