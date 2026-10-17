<?php

namespace App\Policies;

use App\Models\Company;
use App\Models\Contact;
use App\Models\User;

/**
 * Contacts inherit every permission from their company (spec 07 §Permissions, ADR-0004):
 * a salesrep may maintain the people at their own accounts — contacts are operational data,
 * not the structural changes that submodule 06 reserves for manager+.
 */
class ContactPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Contact $contact): bool
    {
        return $this->managesCompany($user, $contact);
    }

    /**
     * Adding a contact edits the company's people list, so it follows the company's
     * update rights.
     */
    public function create(User $user, Company $company): bool
    {
        return $user->can('update', $company);
    }

    public function update(User $user, Contact $contact): bool
    {
        return $this->managesCompany($user, $contact);
    }

    /**
     * Exactly one primary per company (spec 07 §3) — whoever maintains the contacts picks it.
     */
    public function makePrimary(User $user, Contact $contact): bool
    {
        return $this->managesCompany($user, $contact);
    }

    /**
     * Contacts are soft-deleted at most (spec 07 §6): the row and its history survive.
     */
    public function delete(User $user, Contact $contact): bool
    {
        return $this->managesCompany($user, $contact);
    }

    public function restore(User $user, Contact $contact): bool
    {
        return $this->managesCompany($user, $contact);
    }

    public function forceDelete(User $user, Contact $contact): bool
    {
        return $user->role->isAdmin();
    }

    private function managesCompany(User $user, Contact $contact): bool
    {
        $company = $contact->company;

        return $company !== null && $user->can('update', $company);
    }
}
