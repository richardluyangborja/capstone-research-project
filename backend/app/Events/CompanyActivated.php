<?php

namespace App\Events;

use App\Models\Company;
use App\Models\Contract;
use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A company became an active client — either by signing its first contract and having
 * Service accept the handoff, or by a manual/reactivation lifecycle move (spec 09 §1).
 */
class CompanyActivated
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public Company $company,
        public ?Contract $contract = null,
        public ?User $actor = null,
    ) {}
}
