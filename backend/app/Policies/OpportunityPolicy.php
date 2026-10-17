<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\Opportunity;
use App\Models\User;

/**
 * Deals follow their owner's visibility (ADR-0004): a salesrep works their own deals, a
 * manager the team's, an admin all. Editing, stage moves and soft deletes are routine;
 * value (`amount`) and restore are manager+ (ADR-0012 §4, ADR-0015 §5).
 */
class OpportunityPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Opportunity $opportunity): bool
    {
        return $this->ownsOrOversees($user, $opportunity);
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, Opportunity $opportunity): bool
    {
        return $this->ownsOrOversees($user, $opportunity);
    }

    /**
     * Moving a deal through the pipeline is part of working it (module 02).
     */
    public function changeStage(User $user, Opportunity $opportunity): bool
    {
        return $this->update($user, $opportunity);
    }

    /**
     * Deal value is commercial context the owner works with but only manager+ may set
     * (ADR-0012 §4) — a salesrep sees the amount but never changes it.
     */
    public function setAmount(User $user, Opportunity $opportunity): bool
    {
        return $user->role !== Role::Salesrep && $this->ownsOrOversees($user, $opportunity);
    }

    public function delete(User $user, Opportunity $opportunity): bool
    {
        return $this->update($user, $opportunity);
    }

    /**
     * Restoring a retired deal is structural — manager+ (module 02 permissions).
     */
    public function restore(User $user, Opportunity $opportunity): bool
    {
        return $this->mayRestructure($user, $opportunity);
    }

    public function forceDelete(User $user, Opportunity $opportunity): bool
    {
        return $user->role->isAdmin();
    }

    /**
     * (Re)assign a deal (module 02 §3): salesrep → self only, manager → their team,
     * admin → any. A null target asks "may this user assign to others at all".
     */
    public function assign(User $user, Opportunity $opportunity, ?User $target = null): bool
    {
        if (! $this->view($user, $opportunity)) {
            return false;
        }

        if ($user->role->isAdmin()) {
            return true;
        }

        if ($target === null) {
            return true;
        }

        if ($user->role === Role::Manager) {
            return $target->role === Role::Salesrep
                && $target->team_id !== null
                && $target->team_id === $user->team_id;
        }

        return $target->id === $user->id;
    }

    /**
     * Role + ownership scoping (ADR-0004): salesrep own, manager team, admin all.
     */
    private function ownsOrOversees(User $user, Opportunity $opportunity): bool
    {
        if ($user->role->isAdmin()) {
            return true;
        }

        if ($opportunity->owner_id === $user->id) {
            return true;
        }

        if ($user->role === Role::Manager && $user->team_id !== null) {
            return $opportunity->owner?->team_id === $user->team_id;
        }

        return false;
    }

    /**
     * Structural/administrative operations: a salesrep may never perform them; everyone
     * else is still scoped by ownership/team.
     */
    private function mayRestructure(User $user, Opportunity $opportunity): bool
    {
        if ($user->role === Role::Salesrep) {
            return false;
        }

        return $this->ownsOrOversees($user, $opportunity);
    }
}
