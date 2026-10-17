<?php

namespace App\Events;

use App\Enums\OpportunityStage;
use App\Models\Opportunity;
use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class OpportunityStageChanged
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public Opportunity $opportunity,
        public OpportunityStage $from,
        public OpportunityStage $to,
        public ?User $actor = null,
        public ?string $note = null,
    ) {}
}
