<?php

namespace App\Events;

use App\Enums\LostReason;
use App\Models\Opportunity;
use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class OpportunityLost
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public Opportunity $opportunity,
        public ?LostReason $reason = null,
        public ?User $actor = null,
    ) {}
}
