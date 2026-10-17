<?php

namespace App\Integrations;

use App\Models\Company;
use App\Models\Contract;

/**
 * Mocked Service desk (ADR-0005). Deterministic: it acknowledges every handoff unless
 * `CRM_HANDOFF_SIMULATE_FAILURE=true`, which is how the retry path is demoed and tested
 * without a real integration.
 */
final class MockServiceHandoff implements ServiceHandoff
{
    public function handoff(Company $company, Contract $contract): HandoffResult
    {
        if ((bool) config('crm.integrations.service_handoff.simulate_failure')) {
            return HandoffResult::failed(
                'The Service desk did not acknowledge the handoff (mocked failure).',
            );
        }

        return HandoffResult::sent(sprintf('SVC-%s', $contract->reference));
    }
}
