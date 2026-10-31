<?php

namespace App\Integrations;

use App\Models\Company;

/**
 * Timekeeping department hours for one deployment (spec 16 §Read-only external feeds,
 * ADR-0025). The CRM never submits or approves a timesheet.
 */
interface TimekeepingFeed
{
    /**
     * The current-period hours for a deployment. A pending or missing timesheet is a
     * normal read outcome, not an error.
     */
    public function summary(Company $company, DeploymentSummary $deployment): TimesheetSummary;
}
