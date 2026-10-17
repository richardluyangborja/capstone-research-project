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

        $invoiced = (float) (100 + ($seed % 900)) * 1000;
        $overdueInvoices = $seed % 4 === 0 ? 1 : 0;
        $outstanding = $invoiced;
        $overdue = $overdueInvoices === 0 ? 0.0 : round($invoiced * 0.25, 2);

        $daysOutstanding = $seed % 45;

        return new BillingSummary(
            accountStatus: $this->accountStatus($company, $overdue > 0),
            outstanding: $outstanding,
            overdue: $overdue,
            overdueInvoices: $overdueInvoices,
            lastInvoiceDate: now()->subDays($daysOutstanding + 30)->toDateString(),
            nextDueDate: now()->addDays(30 - $daysOutstanding)->toDateString(),
            creditTerms: 'Net 30',
            currency: 'PHP',
            aging: [
                'current' => $outstanding - $overdue,
                '1_30' => $overdue,
                '31_60' => 0.0,
                '60_plus' => 0.0,
            ],
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
