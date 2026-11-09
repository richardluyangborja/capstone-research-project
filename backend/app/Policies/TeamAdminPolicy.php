<?php

namespace App\Policies;

use App\Models\Team;
use App\Models\User;

/**
 * Team administration (module 08 slice S3, spec 19, ADR-0030).
 *
 * Teams are an application-administration resource: admins and superadmins manage them, while
 * every other role is refused at the same boundary as the user directory. Membership and
 * manager eligibility are payload rules (see `EligibleTeamManager` and `AddTeamMemberRequest`),
 * because they depend on the record being changed as well as the actor.
 */
class TeamAdminPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->role->isAdmin();
    }

    public function create(User $user): bool
    {
        return $user->role->isAdmin();
    }

    public function view(User $user, Team $team): bool
    {
        return $user->role->isAdmin();
    }

    public function update(User $user, Team $team): bool
    {
        return $user->role->isAdmin();
    }

    public function assignManager(User $user, Team $team): bool
    {
        return $user->role->isAdmin();
    }

    public function addMember(User $user, Team $team): bool
    {
        return $user->role->isAdmin();
    }

    public function removeMember(User $user, Team $team): bool
    {
        return $user->role->isAdmin();
    }
}
