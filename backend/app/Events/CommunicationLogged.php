<?php

namespace App\Events;

use App\Models\Communication;
use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A communication was logged against a record (module 03, spec 11 §Events). The listener
 * mirrors it onto the subject's timeline and refreshes the aging clock.
 */
class CommunicationLogged
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public Communication $communication,
        public ?User $actor = null,
    ) {}
}
