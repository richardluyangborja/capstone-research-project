<?php

namespace App\Policies;

use App\Models\User;

/**
 * The platform diagnostics boundary (module 08 slice S8, spec 19 §Role capability matrix).
 *
 * Diagnostics are superadmin-only. An application admin keeps the operational health surface
 * (S6) but never receives the platform diagnostics payload, even by calling the route directly.
 */
class PlatformDiagnosticPolicy
{
    public function view(User $user): bool
    {
        return $user->role->canOperatePlatform();
    }
}
