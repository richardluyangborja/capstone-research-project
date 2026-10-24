<?php

namespace App\Integrations;

/**
 * One person's HR figures for a dashboard period (spec 14 §Staff performance).
 *
 * `attendanceRate` is a percentage, `leaveDays` may be fractional (a half day), and
 * `lateArrivals` is a count. The payload always names core2 as its source so the UI can
 * badge the seam rather than imply the CRM measured it.
 */
final readonly class HrAttendance
{
    public function __construct(
        public int $userId,
        public float $attendanceRate,
        public float $leaveDays,
        public int $lateArrivals,
    ) {}

    /**
     * @return array{attendance_rate: float, leave_days: float, late_arrivals: int, source: string}
     */
    public function toArray(): array
    {
        return [
            'attendance_rate' => $this->attendanceRate,
            'leave_days' => $this->leaveDays,
            'late_arrivals' => $this->lateArrivals,
            'source' => 'core2',
        ];
    }
}
