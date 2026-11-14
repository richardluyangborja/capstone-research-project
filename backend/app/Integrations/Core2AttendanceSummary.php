<?php

namespace App\Integrations;

final readonly class Core2AttendanceSummary
{
    /**
     * @param  array{vacation_leave_remaining: float, sick_leave_remaining: float, emergency_leave_remaining: float}  $leaveBalances
     */
    public function __construct(
        public int $userId,
        public float $attendanceRate,
        public int $daysPresent,
        public int $lateArrivalsCount,
        public int $lateArrivalsMinutes,
        public float $undertimeHours,
        public int $absencesCount,
        public float $approvedLeaveDays,
        public array $leaveBalances,
    ) {}
}
