<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\Lead;
use App\Models\User;

class LeadPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Lead $lead): bool
    {
        return $this->canAccess($user, $lead);
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, Lead $lead): bool
    {
        return $this->canAccess($user, $lead);
    }

    public function delete(User $user, Lead $lead): bool
    {
        return $this->canAccess($user, $lead);
    }

    public function restore(User $user, Lead $lead): bool
    {
        return $this->canAccess($user, $lead);
    }

    public function forceDelete(User $user, Lead $lead): bool
    {
        return $user->role->isAdmin();
    }

    /**
     * Role + ownership scoping (ADR-0004): salesrep own, manager team + queue, admin all.
     */
    private function canAccess(User $user, Lead $lead): bool
    {
        if ($user->role->isAdmin()) {
            return true;
        }

        if ($lead->owner_id === null) {
            return $user->role === Role::Manager;
        }

        if ($user->role === Role::Manager) {
            return $lead->owner?->team_id === $user->team_id;
        }

        return $lead->owner_id === $user->id;
    }
}
