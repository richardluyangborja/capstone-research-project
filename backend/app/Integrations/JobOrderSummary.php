<?php

namespace App\Integrations;

/**
 * One read-only Recruitment job order (spec 16 §Delivery payload sketch, ADR-0025).
 */
final readonly class JobOrderSummary
{
    public function __construct(
        public string $reference,
        public string $role,
        public int $requested,
        public int $filled,
        public string $status,
        public string $priority,
        public ?string $targetStartDate,
        public ?string $recruiter,
        public ?string $lastUpdatedAt,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'reference' => $this->reference,
            'role' => $this->role,
            'requested' => $this->requested,
            'filled' => $this->filled,
            'status' => $this->status,
            'priority' => $this->priority,
            'target_start_date' => $this->targetStartDate,
            'recruiter' => $this->recruiter,
            'last_updated_at' => $this->lastUpdatedAt,
        ];
    }
}
