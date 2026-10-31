<?php

namespace App\Integrations;

use App\Models\Company;
use Illuminate\Support\Carbon;

/**
 * Mocked Timekeeping department (spec 16 §Read-only external feeds, ADR-0025).
 *
 * Hours are derived from the deployment's headcount and standard monthly hours. An active
 * crew has an approved current-period timesheet, a pending crew is missing one, and an
 * ended crew keeps a final read. The CRM never submits or approves these hours.
 */
final class MockTimekeepingFeed implements TimekeepingFeed
{
    public function summary(Company $company, DeploymentSummary $deployment): TimesheetSummary
    {
        $timezone = (string) config('crm.timezone', 'Asia/Manila');
        $now = Carbon::now($timezone);
        $start = $now->copy()->startOfMonth();
        $end = $now->copy()->endOfMonth();
        $hoursPerHead = $deployment->standardHoursPerHead > 0
            ? $deployment->standardHoursPerHead
            : 176.0;

        if ($deployment->status === 'pending') {
            return new TimesheetSummary(
                periodStart: $start->toDateString(),
                periodEnd: $end->toDateString(),
                approvedHours: 0.0,
                pendingHours: round($deployment->requestedHeadcount * $hoursPerHead, 2),
                lastSubmittedAt: null,
                status: 'missing',
            );
        }

        $headcount = $deployment->status === 'ended'
            ? $deployment->requestedHeadcount
            : $deployment->activeHeadcount;

        return new TimesheetSummary(
            periodStart: $start->toDateString(),
            periodEnd: $end->toDateString(),
            approvedHours: round($headcount * $hoursPerHead, 2),
            pendingHours: 0.0,
            lastSubmittedAt: $deployment->status === 'ended'
                ? ($deployment->endDate === null
                    ? null
                    : Carbon::parse($deployment->endDate, $timezone)->toIso8601String())
                : $now->copy()->subDays(2)->toIso8601String(),
            status: 'current',
        );
    }
}
