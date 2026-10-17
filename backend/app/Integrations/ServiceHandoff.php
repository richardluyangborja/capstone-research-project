<?php

namespace App\Integrations;

use App\Models\Company;
use App\Models\Contract;

/**
 * Service/operations department handoff (spec 09 §1–2, business-flow.md): the CRM asks
 * Service to take over a signed engagement. Other departments are mocked (ADR-0005).
 */
interface ServiceHandoff
{
    public function handoff(Company $company, Contract $contract): HandoffResult;
}
