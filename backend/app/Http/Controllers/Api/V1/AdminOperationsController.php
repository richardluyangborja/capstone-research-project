<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Operations\OperationsHealthProbe;
use App\Http\Operations\OperationsSummary;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Integration and operational health (module 08 slice S6, spec 19 §S6).
 *
 * Two read-only endpoints behind `OperationsHealthPolicy`. The summary is role-shaped on the
 * server — an application admin's payload has no `platform` block at all, so a direct request
 * cannot retrieve platform-only fields. Nothing here probes with side effects: no test email,
 * no notification, no migration, no report generation and no maintenance action.
 */
class AdminOperationsController extends Controller
{
    public function __construct(
        private readonly OperationsHealthProbe $probe,
        private readonly OperationsSummary $summary,
    ) {}

    public function summary(Request $request): JsonResponse
    {
        Gate::authorize('viewSummary', User::class);

        return response()->json([
            'data' => $this->summary->build($request->user()),
        ]);
    }

    public function health(Request $request): JsonResponse
    {
        Gate::authorize('viewHealth', User::class);

        $user = $request->user();
        $checks = $this->probe->checks($user, $user->role->canOperatePlatform());

        return response()->json([
            'data' => [
                'generated_at' => now()->toIso8601String(),
                'checks' => $checks,
            ],
            'meta' => [
                'timezone' => 'Asia/Manila',
                'read_only' => true,
                'platform' => $user->role->canOperatePlatform(),
            ],
        ]);
    }
}
