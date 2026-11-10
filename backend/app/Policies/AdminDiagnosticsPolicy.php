<?php

namespace App\Policies;

/**
 * The superadmin platform diagnostics boundary (module 08 slice S8, spec 19 §S8).
 *
 * Admin may read the application-plane summary and health checks. The platform-only fields
 * (`queue`, `schema`) are **not** a separate ability: the controller omits them for an admin,
 * so a direct API request by an admin can never retrieve them. The page never decides this —
 * the server does (spec 19 §S6 / §S8).
 */
class AdminDiagnosticsPolicy
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
