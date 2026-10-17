<?php

namespace App\Events;

use App\Models\Company;
use App\Models\Contract;
use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A contract was signed (spec 09 §1). Fired after the contract is committed, because the
 * activation + service handoff it triggers must never roll the contract back.
 */
class ContractSigned
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public Contract $contract,
        public Company $company,
        public ?User $actor = null,
    ) {}
}
