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
        // The unassigned queue is a shared, claimable pool — visible to every role
        // (ADR-0004 clarification — see submodule 03).
        if ($lead->owner_id === null) {
            return true;
        }

        return $this->ownsOrOversees($user, $lead);
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, Lead $lead): bool
    {
        // Working an unassigned lead requires claiming it first (salesrep);
        // managers/admins may edit the queue directly.
        if ($lead->owner_id === null) {
            return $user->role !== Role::Salesrep;
        }

        return $this->ownsOrOversees($user, $lead);
    }

    public function delete(User $user, Lead $lead): bool
    {
        return $this->update($user, $lead);
    }

    public function restore(User $user, Lead $lead): bool
    {
        return $this->update($user, $lead);
    }

    public function forceDelete(User $user, Lead $lead): bool
    {
        return $user->role->isAdmin();
    }

    /**
     * (Re)assign a lead (spec 03 §2): salesrep → self only, manager → their team, admin → any.
     * A null target asks "may this user assign to others at all".
     */
    public function assign(User $user, Lead $lead, ?User $target = null): bool
    {
        if (! $this->view($user, $lead)) {
            return false;
        }

        if ($user->role->isAdmin()) {
            return $target === null || $target->role === Role::Salesrep;
        }

        if ($user->role === Role::Manager) {
            if ($target === null) {
                return true;
            }

            return $target->role === Role::Salesrep
                && $target->team_id !== null
                && $target->team_id === $user->team_id;
        }

        return $target !== null && $target->id === $user->id;
    }

    /**
     * Any role may claim an unassigned lead from the shared queue (spec 03).
     */
    public function claim(User $user, Lead $lead): bool
    {
        return $lead->owner_id === null;
    }

    /**
     * Only users who can edit the lead may convert it (spec 08 §Permissions). Whether the
     * conversion itself is legal (qualified, not already converted) is a business rule the
     * controller enforces with a 422/409.
     */
    public function convert(User $user, Lead $lead): bool
    {
        return $this->update($user, $lead);
    }

    /**
     * Role + ownership scoping (ADR-0004): salesrep own, manager team, admin all.
     */
    private function ownsOrOversees(User $user, Lead $lead): bool
    {
        if ($user->role->isAdmin()) {
            return true;
        }

        if ($user->role === Role::Manager) {
            return $lead->owner?->team_id === $user->team_id;
        }

        return $lead->owner_id === $user->id;
    }
}
