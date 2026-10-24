<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\DashboardRange;
use App\Http\Controllers\Controller;
use App\Http\Dashboard\DashboardPeriod;
use App\Http\Dashboard\DashboardScope;
use App\Http\Dashboard\DashboardSummary;
use App\Http\Dashboard\DashboardWorklist;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The dashboard home (module 06, spec 14, D2).
 *
 * One read over modules 01–05, scoped by the session role. The controller only resolves the
 * period and delegates the payload to `DashboardSummary`; nothing is written and no module
 * rule is re-implemented here.
 */
class DashboardController extends Controller
{
    public function summary(Request $request): JsonResponse
    {
        $range = $this->resolveRange($request);

        $user = $request->user();
        $period = DashboardPeriod::for($range);
        $scope = DashboardScope::for($user);

        return response()->json([
            'data' => (new DashboardSummary($user, $period, $scope))->build(),
        ]);
    }

    public function worklist(Request $request): JsonResponse
    {
        $range = $this->resolveRange($request);

        return response()->json(
            (new DashboardWorklist($request->user(), $range))->build(),
        );
    }

    private function resolveRange(Request $request): DashboardRange
    {
        $validated = $request->validate([
            'range' => ['nullable', Rule::enum(DashboardRange::class)],
        ]);

        return DashboardRange::tryFrom((string) ($validated['range'] ?? ''))
            ?? DashboardRange::Last30Days;
    }
}
