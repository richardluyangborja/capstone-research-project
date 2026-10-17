<?php

namespace App\Events;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A company record was created (spec 06 §Events). Fired for manual creation now and for
 * lead→client conversion in submodule 08.
 */
class CompanyCreated
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public Company $company,
        public ?User $actor = null,
    ) {}
}
