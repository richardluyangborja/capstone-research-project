<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\DashboardRange;
use App\Enums\ManagementReportVariant;
use App\Http\Controllers\Controller;
use App\Http\Dashboard\DashboardPeriod;
use App\Http\Dashboard\DashboardScope;
use App\Http\Reports\ManagementReport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The management report (module 07, spec 15, S1).
 *
 * The standard report is always available and is built from the caller's scoped dashboard data.
 * This endpoint never calls the Python service or a provider; S2 adds a separate generate route.
 */
class ManagementReportController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'variant' => ['nullable', Rule::enum(ManagementReportVariant::class)],
            'range' => ['nullable', Rule::enum(DashboardRange::class)],
        ]);

        $variant = ManagementReportVariant::tryFrom((string) ($validated['variant'] ?? ''))
            ?? ManagementReportVariant::Executive;
        $range = DashboardRange::tryFrom((string) ($validated['range'] ?? ''))
            ?? DashboardRange::Last30Days;

        $user = $request->user();
        $period = DashboardPeriod::for($range);
        $scope = DashboardScope::for($user);

        return response()->json([
            'data' => (new ManagementReport($user, $period, $scope))->build($variant),
            'meta' => [
                'range' => $range->value,
                'scope' => $scope->toArray($user),
                'ai_available' => false,
                'variants' => array_map(
                    fn (ManagementReportVariant $case): string => $case->value,
                    ManagementReportVariant::cases(),
                ),
            ],
        ]);
    }
}
