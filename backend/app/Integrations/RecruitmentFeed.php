<?php

namespace App\Integrations;

use App\Models\Company;

/**
 * Recruitment department job orders (spec 16 §Read-only external feeds, ADR-0025).
 *
 * The CRM may read the hiring demand behind an account; it never creates, edits or closes
 * a job order. A real Recruitment driver replaces the mock binding without a caller change.
 */
interface RecruitmentFeed
{
    /**
     * The account's current job orders, newest/priority first.
     *
     * @return array<int, JobOrderSummary>
     */
    public function jobOrders(Company $company): array;
}
