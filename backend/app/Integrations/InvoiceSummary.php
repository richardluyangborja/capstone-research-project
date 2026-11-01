<?php

namespace App\Integrations;

/**
 * One Finance invoice line (module 01 refresh S11, spec 16 §Read-only external feeds).
 * Finance owns the record; the CRM only displays it (ADR-0025).
 */
final readonly class InvoiceSummary
{
    public function __construct(
        public string $reference,
        public string $periodStart,
        public string $periodEnd,
        public string $issuedAt,
        public string $dueAt,
        public float $amount,
        public float $balance,
        public string $status,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'reference' => $this->reference,
            'period_start' => $this->periodStart,
            'period_end' => $this->periodEnd,
            'issued_at' => $this->issuedAt,
            'due_at' => $this->dueAt,
            'amount' => number_format($this->amount, 2, '.', ''),
            'balance' => number_format($this->balance, 2, '.', ''),
            'status' => $this->status,
        ];
    }
}
