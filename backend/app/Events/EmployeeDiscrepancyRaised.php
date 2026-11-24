<?php

namespace App\Events;

use App\Models\EmployeeDiscrepancy;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A rep escalated a disputed figure (module 09 slice T4, spec 21 §T4, ADR-0033).
 *
 * The listener tells the manager and the admins; a second listener keeps the CRM timeline honest
 * when the flag names an opportunity or contract. Nothing here touches Core 2 — the flag is the
 * CRM's record of what was disputed, not a correction of HR's.
 */
class EmployeeDiscrepancyRaised
{
    use Dispatchable, SerializesModels;

    public function __construct(public EmployeeDiscrepancy $discrepancy) {}
}
