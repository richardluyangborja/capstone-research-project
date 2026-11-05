<?php

namespace App\Policies;

use App\Models\Contract;
use App\Models\User;

class ContractPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Contract $contract): bool
    {
        return $user->can('view', $contract->company);
    }

    public function viewMargin(User $user, Contract $contract): bool
    {
        return $user->canSeeSensitiveData() && $user->can('view', $contract);
    }

    /**
     * Related CRM work is governed by the owning account, while the projection
     * itself remains immutable.
     */
    public function update(User $user, Contract $contract): bool
    {
        return $user->can('update', $contract->company);
    }
}
