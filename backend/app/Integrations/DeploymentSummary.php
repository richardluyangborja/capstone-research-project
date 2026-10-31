<?php

namespace App\Integrations;

/**
 * One read-only Deployment assignment (spec 16 §Delivery payload sketch, ADR-0025).
 *
 * `standardHoursPerHead` and `billingUnit` are internal hints for the Timekeeping feed;
 * they are not part of the public payload.
 */
final readonly class DeploymentSummary
{
    public function __construct(
        public string $reference,
        public string $contractReference,
        public string $role,
        public string $site,
        public string $status,
        public int $activeHeadcount,
        public int $requestedHeadcount,
        public ?string $startDate,
        public ?string $endDate,
        public int $fillRate,
        public ?string $operationsOwner,
        public ?string $lastUpdatedAt,
        public ?TimesheetSummary $timesheet = null,
        public float $standardHoursPerHead = 176.0,
        public string $billingUnit = 'hourly',
    ) {}

    /**
     * Attach the Timekeeping read after the Deployment feed has answered.
     */
    public function withTimesheet(TimesheetSummary $timesheet): self
    {
        return new self(
            reference: $this->reference,
            contractReference: $this->contractReference,
            role: $this->role,
            site: $this->site,
            status: $this->status,
            activeHeadcount: $this->activeHeadcount,
            requestedHeadcount: $this->requestedHeadcount,
            startDate: $this->startDate,
            endDate: $this->endDate,
            fillRate: $this->fillRate,
            operationsOwner: $this->operationsOwner,
            lastUpdatedAt: $this->lastUpdatedAt,
            timesheet: $timesheet,
            standardHoursPerHead: $this->standardHoursPerHead,
            billingUnit: $this->billingUnit,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'reference' => $this->reference,
            'contract_reference' => $this->contractReference,
            'role' => $this->role,
            'site' => $this->site,
            'status' => $this->status,
            'active_headcount' => $this->activeHeadcount,
            'requested_headcount' => $this->requestedHeadcount,
            'start_date' => $this->startDate,
            'end_date' => $this->endDate,
            'fill_rate' => $this->fillRate,
            'timesheet' => $this->timesheet?->toArray(),
            'operations_owner' => $this->operationsOwner,
            'last_updated_at' => $this->lastUpdatedAt,
        ];
    }
}
