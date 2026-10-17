<?php

namespace App\Events;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A client churned (spec 09 §5). Feeds retention/win-back reporting — the reporting
 * itself is out of scope, but the reason + date are recorded on the company.
 */
class CompanyChurned
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public Company $company,
        public ?string $reason = null,
        public ?User $actor = null,
    ) {}
}
