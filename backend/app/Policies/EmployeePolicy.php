<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\User;

class EmployeePolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, User $employee): bool
    {
        return $user->is($employee)
            || $user->role->isAdmin()
            || ($user->role === Role::Manager
                && $user->team_id !== null
                && $employee->team_id === $user->team_id)
            || ($user->role === Role::Salesrep
                && $user->team_id !== null
                && $employee->team_id === $user->team_id);
    }

    public function viewAppraisals(User $user, User $employee): bool
    {
        return $user->is($employee)
            || $user->role->isAdmin()
            || ($user->role === Role::Manager
                && $user->team_id !== null
                && $employee->team_id === $user->team_id);
    }

    public function viewAttendance(User $user, User $employee): bool
    {
        return $user->is($employee)
            || $user->role->isAdmin()
            || ($user->role === Role::Manager
                && $user->team_id !== null
                && $employee->team_id === $user->team_id);
    }

    public function updateQuota(User $user, User $employee): bool
    {
        return $user->role->isAdmin()
            || ($user->role === Role::Manager
                && $employee->role === Role::Salesrep
                && $user->team_id !== null
                && $employee->team_id === $user->team_id);
    }

    public function viewCompensation(User $user, User $employee): bool
    {
        return $user->is($employee)
            || $user->role->isAdmin()
            || ($user->role === Role::Manager
                && $user->team_id !== null
                && $employee->team_id === $user->team_id);
    }
}
