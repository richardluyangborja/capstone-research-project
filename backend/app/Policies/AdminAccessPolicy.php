<?php

namespace App\Policies;

use App\Models\User;

/**
 * The administration shell's capability boundary (spec 19 S1, ADR-0030).
 *
 * The application plane is admin+; platform operations are decided by the capability payload
 * rather than a second policy because both areas share this one read-only probe.
 */
class AdminAccessPolicy
{
    public function view(User $user): bool
    {
        return $user->role->isAdmin();
    }
}
