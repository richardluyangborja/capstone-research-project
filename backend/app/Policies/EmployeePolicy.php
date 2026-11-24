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

    /**
     * A rep disputes their own record only (module 09 slice T4, spec 21 §T4, ADR-0033): nobody
     * raises a flag on somebody else's attendance or pay.
     */
    public function raiseDiscrepancy(User $user, User $employee): bool
    {
        return $user->is($employee);
    }

    /**
     * Flags carry pay and attendance detail, so a salesrep reads only their own; a manager reads
     * their team's, an admin any. A teammate's disputes are not a salesrep's business.
     */
    public function viewDiscrepancies(User $user, User $employee): bool
    {
        return $user->is($employee)
            || $user->role->isAdmin()
            || ($user->role === Role::Manager
                && $user->team_id !== null
                && $employee->team_id === $user->team_id);
    }

    /**
     * Settling a flag is the manager's (or an admin's) job — never the reporter's, even when the
     * reporter is otherwise allowed to edit their own record.
     */
    public function resolveDiscrepancy(User $user, User $employee): bool
    {
        return $user->role->isAdmin()
            || ($user->role === Role::Manager
                && $user->team_id !== null
                && $employee->team_id === $user->team_id);
    }
}
