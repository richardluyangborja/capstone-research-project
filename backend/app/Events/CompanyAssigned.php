<?php

namespace App\Events;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * An account changed hands (spec 16 §Account delegation). The new owner is notified and the
 * move is recorded on the account timeline by `RecordCompanyAssignment`.
 */
class CompanyAssigned
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public Company $company,
        public ?User $previousOwner,
        public User $newOwner,
        public ?User $actor = null,
    ) {}
}
