<?php

namespace App\Events;

use App\Enums\CompanyStatus;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A company moved through the client lifecycle (spec 09 §1). Recorded on the company
 * timeline by `RecordCompanyStatusChange`.
 */
class CompanyStatusChanged
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public Company $company,
        public CompanyStatus $from,
        public CompanyStatus $to,
        public ?User $actor = null,
        public ?string $note = null,
    ) {}
}
