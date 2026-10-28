<?php

namespace App\Policies;

use App\Models\Company;
use App\Models\Contract;
use App\Models\User;

/**
 * A contract inherits its company's rights (ADR-0011 §2 pattern, extended in ADR-0013):
 * whoever maintains the account uploads and signs contracts on it, while retiring one is
 * a structural move reserved for manager+.
 */
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

    /**
     * Uploading a contract is routine sales work on an account you maintain (spec 09
     * permissions — "upload contract: own/team/all").
     */
    public function create(User $user, Company $company): bool
    {
        return $user->can('update', $company);
    }

    public function update(User $user, Contract $contract): bool
    {
        return $user->can('update', $contract->company);
    }

    /**
     * Signing advances the client and fires the handoff, but it is the closing sales
     * action, so it follows the account (own/team/all). Transition legality is validated
     * separately, so a second signature is a 422 rather than a 403.
     */
    public function markSigned(User $user, Contract $contract): bool
    {
        return $user->can('update', $contract->company);
    }

    /**
     * Pay rates, internal cost and margin are sensitive data — manager+ (ADR-0004,
     * ADR-0024 §5). Bill rates and client values stay visible to the account's salesrep.
     */
    public function viewMargin(User $user, Contract $contract): bool
    {
        return $user->canSeeSensitiveData() && $user->can('view', $contract);
    }

    /**
     * Writing rate cards is manager+; editing the header stays with the account's
     * existing update right (ADR-0024 §5).
     */
    public function manageRateCards(User $user, Contract $contract): bool
    {
        return $user->can('manageContracts', $contract->company);
    }

    /**
     * Deleting/restoring a contract is a structural operation — manager+ (spec 06 §2).
     */
    public function delete(User $user, Contract $contract): bool
    {
        return $user->can('manageContracts', $contract->company);
    }

    public function restore(User $user, Contract $contract): bool
    {
        return $user->can('manageContracts', $contract->company);
    }

    public function forceDelete(User $user, Contract $contract): bool
    {
        return $user->role->isAdmin();
    }
}
