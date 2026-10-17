<?php

namespace App\Listeners;

use App\Events\ContractSigned;

/**
 * A signed contract activates the client and asks the (mocked) Service department to take
 * the engagement over (spec 09 §1–2). The orchestration lives on the company so the
 * handoff retry endpoint can run the identical path.
 */
class HandoffContractToService
{
    public function handle(ContractSigned $event): void
    {
        $event->company->handoffToService($event->contract, $event->actor);
    }
}
