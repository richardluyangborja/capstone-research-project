<?php

namespace App\Integrations;

final readonly class Core2CompensationProfile
{
    /**
     * @param  array<int, array{period: string, gross_pay: ?float, net_pay: ?float, status: string}>  $recentPayslips
     */
    public function __construct(
        public int $userId,
        public ?float $baseSalary,
        public ?float $fixedAllowances,
        public bool $isMasked,
        public array $recentPayslips,
    ) {}
}
