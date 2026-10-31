<?php

namespace App\Integrations;

use App\Models\Company;

/**
 * Deployment / assignment feed (spec 16 §Read-only external feeds, ADR-0025).
 *
 * Assignments are owned by Deployment operations; the CRM only displays their status,
 * headcount and fill rate. This feed is reused by the account Delivery tab and the
 * cross-account Deployments book (S10).
 */
interface DeploymentFeed
{
    /**
     * The account's deployment assignments, including ended ones for a churned client.
     *
     * @return array<int, DeploymentSummary>
     */
    public function deployments(Company $company): array;
}
