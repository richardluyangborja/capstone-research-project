<?php

namespace App\Policies;

use App\Models\User;

class PersonalSettingsPolicy
{
    public function view(User $user): bool
    {
        return $user->isActive();
    }

    public function updateProfile(User $user): bool
    {
        return $user->isActive();
    }

    public function updatePreferences(User $user): bool
    {
        return $user->isActive();
    }

    public function changePassword(User $user): bool
    {
        return $user->isActive();
    }
}
