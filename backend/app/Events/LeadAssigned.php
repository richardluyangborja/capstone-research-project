<?php

namespace App\Events;

use App\Models\Lead;
use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class LeadAssigned
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public Lead $lead,
        public ?User $previousOwner,
        public User $newOwner,
        public ?User $actor = null,
    ) {}
}
