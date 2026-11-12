<?php

namespace App\Integrations;

interface Core2EmployeeGateway
{
    public function profile(int $userId): ?Core2EmployeeProfile;

    public function attendance(int $userId, string $from, string $to): ?Core2AttendanceSummary;

    public function compensation(int $userId): ?Core2CompensationProfile;

    /** @return array<int, Core2AppraisalSummary>|null */
    public function appraisals(int $userId): ?array;
}
