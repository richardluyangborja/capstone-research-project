<?php

namespace App\Policies;

use App\Enums\Role;
use App\Enums\UserStatus;
use App\Models\User;

/**
 * The admin user directory (module 08 slice S2, spec 19 §Endpoint and policy inventory).
 *
 * The role matrix is the contract: admins manage manager/salesrep accounts below superadmin;
 * only a superadmin manages admins (create/promote/demote, team, suspend, restore, resend);
 * no route creates or edits a superadmin; nobody suspends themselves; the final active
 * superadmin guard lives in the controller because it needs the database, not just the actor.
 */
class UserAdminPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->role->isAdmin();
    }

    public function create(User $user): bool
    {
        return $user->role->isAdmin();
    }

    /**
     * An admin may read manager/salesrep profiles only — a superadmin's security details are
     * forbidden to them, so the directory simply never shows one.
     */
    public function view(User $user, User $target): bool
    {
        return $this->withinActorScope($user, $target);
    }

    /**
     * Safe profile edits only; the payload is policed by the request, the target by here.
     */
    public function update(User $user, User $target): bool
    {
        return $this->withinActorScope($user, $target);
    }

    /**
     * Admins change salesrep/manager roles; only a superadmin assigns or removes `admin`.
     */
    public function changeRole(User $user, User $target): bool
    {
        return $this->withinActorScope($user, $target);
    }

    public function changeTeam(User $user, User $target): bool
    {
        return $this->withinActorScope($user, $target);
    }

    public function suspend(User $user, User $target): bool
    {
        // Already-suspended is a 422 state rule raised by the controller, not a permission.
        return $target->id !== $user->id
            && $this->withinActorScope($user, $target);
    }

    public function restore(User $user, User $target): bool
    {
        return $target->status === UserStatus::Suspended
            && $this->withinActorScope($user, $target);
    }

    public function resendInvitation(User $user, User $target): bool
    {
        return $this->withinActorScope($user, $target);
    }

    public function viewOwnershipImpact(User $user, User $target): bool
    {
        return $this->withinActorScope($user, $target);
    }

    /**
     * A superadmin acts on anyone; an admin acts on everyone below superadmin. A superadmin
     * target is never manageable through this policy by anybody — accounts are seeded or
     * platform-managed (spec 19: "Create superadmins: no, seeded/platform only").
     */
    private function withinActorScope(User $user, User $target): bool
    {
        // A superadmin account is managed only by a superadmin; the final-active guard lives
        // in the controller because it needs the database. An admin actor never gets past
        // this line for a superadmin target.
        if ($target->role === Role::Superadmin) {
            return $user->role === Role::Superadmin;
        }

        return $user->role->isAdmin();
    }
}
