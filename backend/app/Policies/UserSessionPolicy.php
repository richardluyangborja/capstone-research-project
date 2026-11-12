<?php

namespace App\Policies;

use App\Models\User;
use App\Models\UserSession;

class UserSessionPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isActive();
    }

    public function revoke(User $user, UserSession $session): bool
    {
        return $user->isActive() && $session->user_id === $user->id;
    }

    public function revokeOthers(User $user): bool
    {
        return $user->isActive();
    }
}
