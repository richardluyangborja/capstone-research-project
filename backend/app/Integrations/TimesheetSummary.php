<?php

namespace App\Integrations;

/**
 * Read-only Timekeeping figures for one deployment period (spec 16 §Delivery payload
 * sketch, ADR-0025). Status is `current`, `pending` or `missing`.
 */
final readonly class TimesheetSummary
{
    public function __construct(
        public string $periodStart,
        public string $periodEnd,
        public float $approvedHours,
        public float $pendingHours,
        public ?string $lastSubmittedAt,
        public string $status,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'period_start' => $this->periodStart,
            'period_end' => $this->periodEnd,
            'approved_hours' => round($this->approvedHours, 2),
            'pending_hours' => round($this->pendingHours, 2),
            'last_submitted_at' => $this->lastSubmittedAt,
            'status' => $this->status,
        ];
    }
}
