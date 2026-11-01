<?php

namespace App\Integrations;

use App\Enums\CompanyStatus;
use App\Models\Company;

/**
 * Mocked Finance department (ADR-0005). Values are derived from the company's reference
 * rather than faker, so a demo shows the same snapshot on every run.
 */
final class MockFinanceBilling implements FinanceBilling
{
    public function summary(Company $company): BillingSummary
    {
        $seed = crc32($company->reference);
        $closed = $company->status === CompanyStatus::Churned;
        $prospect = $company->status === CompanyStatus::Prospect;

        // A prospect has no live AR yet; a churned account keeps its settled history.
        if ($prospect) {
            return new BillingSummary(
                accountStatus: 'current',
                outstanding: 0.0,
                overdue: 0.0,
                overdueInvoices: 0,
                lastInvoiceDate: null,
                nextDueDate: null,
                creditTerms: 'Net 30',
                currency: 'PHP',
                aging: ['current' => 0.0, '1_30' => 0.0, '31_60' => 0.0, '60_plus' => 0.0],
                invoices: [],
            );
        }

        $monthly = (float) (100 + ($seed % 900)) * 1000;
        $overdueInvoices = ! $closed && $seed % 4 === 3 ? 1 : 0;
        $invoices = [];
        $outstanding = 0.0;
        $overdue = 0.0;
        $lastInvoiceDate = null;
        $nextDueDate = null;

        // Three monthly invoices: two settled, the current one open or overdue. The mock is
        // deterministic from the account reference, never faker (ADR-0005).
        for ($index = 2; $index >= 0; $index--) {
            $period = now()->startOfMonth()->subMonths($index);
            $issued = $period->copy()->addDays(2);
            $due = $issued->copy()->addDays(30);
            $status = match (true) {
                $closed => 'paid',
                $index === 0 && $overdueInvoices === 1 => 'overdue',
                $index === 0 => 'open',
                default => 'paid',
            };
            $balance = in_array($status, ['open', 'overdue'], true) ? $monthly : 0.0;

            $invoices[] = new InvoiceSummary(
                reference: sprintf('INV-%d-%04d', $issued->year, ($seed % 8000) + 100 + $index),
                periodStart: $period->toDateString(),
                periodEnd: $period->copy()->endOfMonth()->toDateString(),
                issuedAt: $issued->toDateString(),
                dueAt: $due->toDateString(),
                amount: $monthly,
                balance: $balance,
                status: $status,
            );

            $outstanding += $balance;
            $overdue += $status === 'overdue' ? $balance : 0.0;
            $lastInvoiceDate = $issued->toDateString();
            $nextDueDate = $due->toDateString();
        }

        return new BillingSummary(
            accountStatus: $this->accountStatus($company, $overdue > 0),
            outstanding: $outstanding,
            overdue: $overdue,
            overdueInvoices: $overdueInvoices,
            lastInvoiceDate: $lastInvoiceDate,
            nextDueDate: $nextDueDate,
            creditTerms: 'Net 30',
            currency: 'PHP',
            aging: [
                'current' => $outstanding - $overdue,
                '1_30' => $overdue,
                '31_60' => 0.0,
                '60_plus' => 0.0,
            ],
            invoices: array_reverse($invoices),
        );
    }

    /**
     * Finance closes the account when the client churned (spec 09 §5); otherwise the
     * snapshot is current or overdue.
     */
    private function accountStatus(Company $company, bool $overdue): string
    {
        if ($company->status === CompanyStatus::Churned) {
            return 'closed';
        }

        return $overdue ? 'overdue' : 'current';
    }
}
