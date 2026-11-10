<?php

namespace App\Policies;

use App\Models\User;

/**
 * The operational health boundary (module 08 slice S6, spec 19 §Endpoint and policy inventory).
 *
 * Admin and superadmin may read the application-plane summary and health checks. The
 * platform-only fields of the summary (`platform`) are **not** a separate ability: the
 * controller omits them for an admin, so a direct API request by an admin can never retrieve
 * them. The page never decides this — the server does (spec 19 §S6).
 */
class OperationsHealthPolicy
{
    public function viewSummary(User $user): bool
    {
        return $user->role->isAdmin();
    }

    public function viewHealth(User $user): bool
    {
        return $user->role->isAdmin();
    }
}
