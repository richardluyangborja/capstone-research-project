<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * The administration shell's capability probe (spec 19 S1).
 *
 * It does not replace the JWT role; it gives the shell one server-side boundary to prove and
 * gives non-admins Laravel's existing authenticated 403 response shape.
 */
class AdminAccessController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        Gate::authorize('viewAdminAccess');

        $user = $request->user();
        $platform = $user->role->canOperatePlatform();

        return response()->json([
            'data' => [
                'role' => $user->role->value,
                'application' => true,
                'platform' => $platform,
                'personal_settings' => true,
                'areas' => $platform ? ['application', 'platform'] : ['application'],
            ],
        ]);
    }
}
