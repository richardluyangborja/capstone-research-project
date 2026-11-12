<?php

namespace App\Integrations;

use Carbon\CarbonImmutable;

final class MockCore2EmployeeGateway implements Core2EmployeeGateway
{
    public function profile(int $userId): ?Core2EmployeeProfile
    {
        if ($this->isUnavailable()) {
            return null;
        }

        $seed = $this->seed($userId);
        $rep = $seed % 2 === 0;

        return new Core2EmployeeProfile(
            employeeReference: sprintf('EMP-2026-%04d', ($seed % 9000) + 1000),
            officialTitle: $rep ? 'Senior Account Executive' : 'Sales Manager',
            department: 'Business Development & Sales',
            employmentStatus: $seed % 5 === 0 ? 'probationary' : 'regular',
            tenureMonths: 6 + ($seed % 91),
            shiftSchedule: '08:00 - 17:00 PHT, Mon-Fri',
            directManager: $rep ? 'Maria Santos' : null,
        );
    }

    public function attendance(int $userId, string $from, string $to): ?Core2AttendanceSummary
    {
        if ($this->isUnavailable()) {
            return null;
        }

        $start = CarbonImmutable::parse($from)->startOfDay();
        $end = CarbonImmutable::parse($to)->startOfDay();
        $days = max(1, $start->diffInDays($end) + 1);
        $seed = $this->seed($userId);
        $workingDays = max(1, (int) round($days * 5 / 7));
        $absences = $seed % 3;
        $present = max(0, $workingDays - $absences);

        return new Core2AttendanceSummary(
            userId: $userId,
            attendanceRate: round(($present / $workingDays) * (92 + ($seed % 8)), 2),
            daysPresent: $present,
            lateArrivalsCount: $seed % 4,
            lateArrivalsMinutes: ($seed % 4) * (8 + ($seed % 7)),
            undertimeHours: round(($seed % 6) / 2, 1),
            absencesCount: $absences,
            leaveBalances: [
                'vacation_leave_remaining' => (float) (5 + ($seed % 11)),
                'sick_leave_remaining' => (float) (3 + ($seed % 8)),
                'emergency_leave_remaining' => (float) ($seed % 4),
            ],
        );
    }

    public function compensation(int $userId): ?Core2CompensationProfile
    {
        if ($this->isUnavailable()) {
            return null;
        }

        $seed = $this->seed($userId);
        $monthlySalary = (float) (25_000 + ($seed % 35_001));

        return new Core2CompensationProfile(
            userId: $userId,
            baseSalary: $monthlySalary,
            fixedAllowances: (float) (2_000 + ($seed % 6_001)),
            isMasked: false,
            recentPayslips: [
                [
                    'period' => '2026-10',
                    'gross_pay' => $monthlySalary + 3_000.0,
                    'net_pay' => $monthlySalary + 1_500.0,
                    'status' => 'released',
                ],
                [
                    'period' => '2026-09',
                    'gross_pay' => $monthlySalary + 2_500.0,
                    'net_pay' => $monthlySalary + 1_250.0,
                    'status' => 'released',
                ],
            ],
        );
    }

    public function appraisals(int $userId): ?array
    {
        if ($this->isUnavailable()) {
            return null;
        }

        $seed = $this->seed($userId);
        $rating = (float) (3 + ($seed % 21) / 10);

        return [
            new Core2AppraisalSummary(
                appraisalId: sprintf('APR-2026-Q3-%04d', ($seed % 9000) + 1000),
                periodLabel: '2026 Q3 Performance Appraisal',
                overallRating: $rating,
                competencies: [
                    'client_negotiation' => min(5.0, round($rating + 0.2, 1)),
                    'attendance_punctuality' => min(5.0, round($rating - 0.1, 1)),
                    'sales_execution' => min(5.0, round($rating + 0.1, 1)),
                ],
                evaluatorNotes: 'Consistently contributes to team objectives and client service.',
                conductedAt: CarbonImmutable::parse('2026-10-15T09:00:00+08:00')->toIso8601String(),
            ),
        ];
    }

    private function isUnavailable(): bool
    {
        return (bool) config('crm.integrations.core2_employees.simulate_failure', false);
    }

    private function seed(int $userId): int
    {
        return crc32('core2-employee|'.$userId);
    }
}
