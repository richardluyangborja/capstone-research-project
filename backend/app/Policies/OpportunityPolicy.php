<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\Opportunity;
use App\Models\User;

/**
 * Deals inherit the visibility of their owner (ADR-0004). Editing a deal — stage moves,
 * amount, close dates — is the pipeline module's job and is not exposed yet, so only
 * viewing is policed here.
 */
class OpportunityPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Opportunity $opportunity): bool
    {
        if ($user->role->isAdmin()) {
            return true;
        }

        if ($user->role === Role::Manager) {
            return $opportunity->owner?->team_id === $user->team_id;
        }

        return $opportunity->owner_id === $user->id;
    }
}
