<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\Company;
use App\Models\User;

class CompanyPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Company $company): bool
    {
        return $this->ownsOrOversees($user, $company);
    }

    /**
     * Manual creation is open to every role (spec 06 open question — resolved: salesreps
     * create companies too, since importing an existing client is part of their job).
     * The controller makes a salesrep the owner of what they create.
     */
    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, Company $company): bool
    {
        return $this->ownsOrOversees($user, $company);
    }

    /**
     * Deletion/restoration and structural changes (sites, branches) are manager+ only
     * (spec 06 permissions — salesrep `—`).
     */
    public function delete(User $user, Company $company): bool
    {
        return $this->mayRestructure($user, $company);
    }

    public function restore(User $user, Company $company): bool
    {
        return $this->mayRestructure($user, $company);
    }

    public function forceDelete(User $user, Company $company): bool
    {
        return $user->role->isAdmin();
    }

    public function manageSites(User $user, Company $company): bool
    {
        return $this->mayRestructure($user, $company);
    }

    public function manageParent(User $user, Company $company): bool
    {
        return $this->mayRestructure($user, $company);
    }

    /**
     * Client lifecycle moves — churn, reactivate, and manual at-risk/dormant — are
     * manager+ (spec 09 permissions). The direct `status` field on `PATCH /companies/{id}`
     * stays admin-only as a system override (ADR-0013).
     */
    public function manageLifecycle(User $user, Company $company): bool
    {
        return $this->mayRestructure($user, $company);
    }

    /**
     * Billing/AR is consumed read-only from Finance and hidden from salesreps
     * (spec 09 §7, ADR-0004).
     */
    public function viewBilling(User $user, Company $company): bool
    {
        return $this->mayRestructure($user, $company);
    }

    /**
     * Contract upload and handoff retry are routine work on an account you maintain
     * (own/team/all — spec 09 permissions).
     */
    public function uploadContract(User $user, Company $company): bool
    {
        return $this->ownsOrOversees($user, $company);
    }

    public function retryHandoff(User $user, Company $company): bool
    {
        return $this->ownsOrOversees($user, $company);
    }

    /**
     * Retiring a contract is structural — manager+ (spec 06 §2 convention).
     */
    public function manageContracts(User $user, Company $company): bool
    {
        return $this->mayRestructure($user, $company);
    }

    /**
     * May this user (re)assign the company to `$target`? A null target asks "may this
     * user assign at all". There is no shared queue for companies, so the target must be
     * someone the user may own records with.
     */
    public function assign(User $user, Company $company, ?User $target = null): bool
    {
        if (! $this->view($user, $company)) {
            return false;
        }

        if ($user->role->isAdmin()) {
            return true;
        }

        if ($target === null) {
            return $user->role === Role::Manager || $user->role === Role::Salesrep;
        }

        if ($user->role === Role::Manager) {
            return $target->team_id !== null && $target->team_id === $user->team_id;
        }

        return $target->id === $user->id;
    }

    /**
     * Role + ownership scoping (ADR-0004): salesrep own, manager team, admin all.
     */
    private function ownsOrOversees(User $user, Company $company): bool
    {
        if ($user->role->isAdmin()) {
            return true;
        }

        if ($company->owner_id === $user->id) {
            return true;
        }

        if ($user->role === Role::Manager && $user->team_id !== null) {
            return $company->owner?->team_id === $user->team_id;
        }

        return false;
    }

    /**
     * Structural/administrative operations (spec 06 permissions): a salesrep may never
     * perform them, everyone else is still scoped by ownership/team.
     */
    private function mayRestructure(User $user, Company $company): bool
    {
        if ($user->role === Role::Salesrep) {
            return false;
        }

        return $this->ownsOrOversees($user, $company);
    }
}
