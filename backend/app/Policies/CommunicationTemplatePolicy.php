<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\CommunicationTemplate;
use App\Models\User;

/**
 * Templates are the **house's wording** (module 03 slice S5, spec 11 §Permissions): every role
 * reads them and renders them into the composer, but only manager+ authors, edits, retires or
 * restores one. There is no per-record scoping — a template is not about a record; who may
 * *render* it against a record is decided by that record's own `view` right in the controller.
 */
class CommunicationTemplatePolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, CommunicationTemplate $template): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return $this->manages($user);
    }

    public function update(User $user, CommunicationTemplate $template): bool
    {
        return $this->manages($user);
    }

    public function delete(User $user, CommunicationTemplate $template): bool
    {
        return $this->manages($user);
    }

    public function restore(User $user, CommunicationTemplate $template): bool
    {
        return $this->manages($user);
    }

    public function forceDelete(User $user, CommunicationTemplate $template): bool
    {
        return $user->role->isAdmin();
    }

    private function manages(User $user): bool
    {
        return $user->role !== Role::Salesrep;
    }
}
