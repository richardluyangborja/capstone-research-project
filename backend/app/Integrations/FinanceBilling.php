<?php

namespace App\Integrations;

use App\Models\Company;

/**
 * Finance department billing/AR lookup (spec 09 §7). Read-only by contract: the CRM can
 * ask, never post (ADR-0005).
 */
interface FinanceBilling
{
    public function summary(Company $company): BillingSummary;
}
