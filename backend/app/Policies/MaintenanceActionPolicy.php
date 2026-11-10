<?php

namespace App\Policies;

use App\Models\User;

/**
 * The safe maintenance boundary (module 08 slice S7, spec 19 §S7).
 *
 * Both allow-listed actions — rebuilding the effective-configuration snapshot and retrying one
 * failed email — are application-plane operations, so admin and superadmin may run them. The
 * policy is registered explicitly for `App\Http\Operations\MaintenanceAction`; there is no generic
 * ability and no route that accepts a command, SQL, migration or cache operation.
 */
class MaintenanceActionPolicy
{
    public function refreshConfiguration(User $user): bool
    {
        return $user->role->isAdmin();
    }

    public function retryEmail(User $user): bool
    {
        return $user->role->isAdmin();
    }
}
