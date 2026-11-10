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
 * Superadmin platform diagnostics (module 08 slice S8, spec 19 §S8).
 *
 * Two read-only endpoints behind `OperationsHealthPolicy`. The controller role-shapes the
 * payload on the server: admins receive only application-plane checks; superadmins also
 * receive the platform-only queue and schema checks. Nothing here probes with side effects:
 * no test email, no notification, no migration, no report generation and no maintenance action.
 */
class AdminDiagnosticsController extends Controller
{
    public function __construct(
        private readonly OperationsHealthProbe $probe,
        private readonly OperationsSummary $summary,
    ) {}

    public function summary(Request $request): JsonResponse
    {
        Gate::authorize('viewSummary', User::class);

        $user = $request->user();
        $platform = $user->role->canOperatePlatform();

        return response()->json([
            'data' => $this->summary->build($request->user(), $platform),
            'meta' => [
                'timezone' => 'Asia/Manila',
                'read_only' => true,
                'platform' => $platform,
            ],
        ]);
    }

    public function health(Request $request): JsonResponse
    {
        Gate::authorize('viewHealth', User::class);

        $user = $request->user();
        $platform = $user->role->canOperatePlatform();

        $checks = $this->probe->checks($user, $platform);

        return response()->json([
            'data' => [
                'generated_at' => now()->toIso8601String(),
                'checks' => $checks,
            ],
            'meta' => [
                'timezone' => 'Asia/Manila',
                'read_only' => true,
                'platform' => $platform,
            ],
        ]);
    }
}
