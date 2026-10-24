<?php

namespace App\Integrations;

use Carbon\CarbonImmutable;

/**
 * Mocked core2 / performance management (ADR-0005, ADR-0020 §7).
 *
 * Values are derived from the person's id and the length of the requested window, never from
 * faker or randomness, so the same demo renders the same figures. Attendance and late arrivals
 * are point-in-time reads; leave days scale with the period.
 *
 * `CRM_HR_SIMULATE_FAILURE=true` makes the seam unavailable, which is how the dashboard's
 * `hr: null` / `hr_available: false` path is demoed and tested.
 */
final class MockHrGateway implements HrGateway
{
    public function attendance(array $userIds, string $from, string $to): ?array
    {
        if ((bool) config('crm.integrations.hr.simulate_failure')) {
            return null;
        }

        $days = max(1, (int) CarbonImmutable::parse($from)->diffInDays(CarbonImmutable::parse($to)) + 1);
        $periodFactor = $days / 30;

        $figures = [];

        foreach ($userIds as $userId) {
            $seed = crc32('core2|'.$userId);

            $figures[$userId] = new HrAttendance(
                userId: $userId,
                attendanceRate: (float) (92 + ($seed % 8)),
                leaveDays: round((1 + ($seed % 4)) * $periodFactor * 2) / 2,
                lateArrivals: $seed % 3,
            );
        }

        return $figures;
    }
}
