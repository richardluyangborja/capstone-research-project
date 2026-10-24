<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\DashboardRange;
use App\Enums\ManagementReportVariant;
use App\Http\Controllers\Controller;
use App\Http\Dashboard\DashboardPeriod;
use App\Http\Dashboard\DashboardScope;
use App\Http\Reports\ManagementReportGenerator;
use App\Http\Reports\ReportCooldownException;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The management report (module 07, spec 15).
 *
 * GET is the page-load read: cached model prose when it exists, otherwise the standard rules
 * report. POST is the only path that spends provider quota. Both endpoints derive the scope from
 * the JWT and return the same report shape; a provider failure is a complete rules report with a
 * fallback reason, not an HTTP error.
 */
class ManagementReportController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        [$variant, $range] = $this->resolve($request, false);

        $user = $request->user();
        $period = DashboardPeriod::for($range);
        $scope = DashboardScope::for($user);

        return $this->payload(
            (new ManagementReportGenerator($user, $period, $scope, $variant))->current(),
            $range,
            $scope,
            $user,
        );
    }

    public function generate(Request $request): JsonResponse
    {
        [$variant, $range] = $this->resolve($request, true);

        $user = $request->user();
        $period = DashboardPeriod::for($range);
        $scope = DashboardScope::for($user);

        try {
            $report = (new ManagementReportGenerator($user, $period, $scope, $variant))
                ->generate($request->boolean('refresh'));
        } catch (ReportCooldownException $exception) {
            return response()->json([
                'message' => 'Try again in a few minutes.',
                'retry_after_seconds' => $exception->retryAfterSeconds,
            ], 429);
        }

        return $this->payload($report, $range, $scope, $user);
    }

    /**
     * @return array{0: ManagementReportVariant, 1: DashboardRange}
     */
    private function resolve(Request $request, bool $requireVariant): array
    {
        $validated = $request->validate([
            'variant' => [$requireVariant ? 'required' : 'nullable', Rule::enum(ManagementReportVariant::class)],
            'range' => ['nullable', Rule::enum(DashboardRange::class)],
        ]);

        return [
            ManagementReportVariant::tryFrom((string) ($validated['variant'] ?? ''))
                ?? ManagementReportVariant::Executive,
            DashboardRange::tryFrom((string) ($validated['range'] ?? ''))
                ?? DashboardRange::Last30Days,
        ];
    }

    /**
     * @param  array<string, mixed>  $report
     */
    private function payload(
        array $report,
        DashboardRange $range,
        DashboardScope $scope,
        User $user,
    ): JsonResponse {
        return response()->json([
            'data' => $report,
            'meta' => [
                'range' => $range->value,
                'scope' => $scope->toArray($user),
                'ai_available' => (bool) config('ai.report.enabled', true),
                'variants' => array_map(
                    fn (ManagementReportVariant $case): string => $case->value,
                    ManagementReportVariant::cases(),
                ),
            ],
        ]);
    }
}
