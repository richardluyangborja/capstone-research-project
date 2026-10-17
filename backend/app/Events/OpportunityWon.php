<?php

namespace App\Events;

use App\Models\Opportunity;
use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A deal reached `won` (module 02). Reporting/future-listener seam only: winning a deal
 * **prompts** contract creation and never runs the Service handoff (ADR-0015 §4).
 */
class OpportunityWon
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public Opportunity $opportunity,
        public ?User $actor = null,
    ) {}
}
