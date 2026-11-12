<?php

namespace App\Integrations;

final readonly class Core2EmployeeProfile
{
    public function __construct(
        public string $employeeReference,
        public string $officialTitle,
        public string $department,
        public string $employmentStatus,
        public int $tenureMonths,
        public string $shiftSchedule,
        public ?string $directManager,
    ) {}
}
