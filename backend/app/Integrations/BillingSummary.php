<?php

namespace App\Integrations;

/**
 * Read-only billing/AR snapshot the CRM consumes from Finance (spec 09 §7). The CRM never
 * writes billing data — it displays this as "from Finance" (ADR-0005).
 */
final readonly class BillingSummary
{
    /**
     * @param  array<string, float>  $aging  Buckets keyed `current`, `1_30`, `31_60`, `60_plus`.
     */
    public function __construct(
        public string $accountStatus,
        public float $outstanding,
        public float $overdue,
        public int $overdueInvoices,
        public ?string $lastInvoiceDate,
        public ?string $nextDueDate,
        public string $creditTerms,
        public string $currency,
        public array $aging,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'account_status' => $this->accountStatus,
            'outstanding' => number_format($this->outstanding, 2, '.', ''),
            'overdue' => number_format($this->overdue, 2, '.', ''),
            'overdue_invoices' => $this->overdueInvoices,
            'last_invoice_date' => $this->lastInvoiceDate,
            'next_due_date' => $this->nextDueDate,
            'credit_terms' => $this->creditTerms,
            'currency' => $this->currency,
            'aging' => array_map(
                static fn (float $amount): string => number_format($amount, 2, '.', ''),
                $this->aging,
            ),
        ];
    }
}
