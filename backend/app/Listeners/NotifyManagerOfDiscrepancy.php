<?php

namespace App\Listeners;

use App\Enums\Role;
use App\Enums\UserStatus;
use App\Events\EmployeeDiscrepancyRaised;
use App\Models\User;
use App\Notifications\EmployeeDiscrepancyNotification;
use Illuminate\Support\Facades\Notification;

/**
 * Whoever can settle the flag is told about it (module 09 slice T4, spec 21 §T4, ADR-0033 §4):
 * the employee's team manager and every active administrator.
 *
 * The reporter is skipped, so a manager who disputes their own record does not ring their own bell.
 * Suspended accounts are skipped too — they cannot sign in to act on it. Nothing is invented when
 * the employee has no manager: the admins still hear about it.
 */
class NotifyManagerOfDiscrepancy
{
    public function handle(EmployeeDiscrepancyRaised $event): void
    {
        $discrepancy = $event->discrepancy;
        $discrepancy->loadMissing('employee.team.manager');

        $employee = $discrepancy->employee;

        if ($employee === null) {
            return;
        }

        $recipients = User::query()
            ->where('status', UserStatus::Active->value)
            ->where(function ($query) use ($employee): void {
                $query->whereIn('role', [Role::Admin->value, Role::Superadmin->value]);

                if ($employee->team?->manager_id !== null) {
                    $query->orWhereKey($employee->team->manager_id);
                }
            })
            ->whereNotIn('id', array_values(array_filter([$employee->id, $discrepancy->raised_by])))
            ->get();

        if ($recipients->isEmpty()) {
            return;
        }

        Notification::send($recipients, new EmployeeDiscrepancyNotification($discrepancy));
    }
}
