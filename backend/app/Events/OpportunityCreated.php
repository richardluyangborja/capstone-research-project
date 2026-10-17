<?php

namespace App\Events;

use App\Models\Opportunity;
use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A deal was opened on a company (spec 08 §1–3) — by conversion now, by the pipeline
 * module later.
 */
class OpportunityCreated
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public Opportunity $opportunity,
        public ?User $actor = null,
    ) {}
}
