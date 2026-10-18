<?php

namespace App\Events;

use App\Models\Communication;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * An email left through the gateway (module 03 slice S2, spec 11 §Events). The listener
 * writes the timeline mirror and restarts the record's aging clock — the same follow-up a
 * logged communication gets, because a sent message is a touch that really happened.
 */
class CommunicationSent
{
    use Dispatchable, SerializesModels;

    public function __construct(public Communication $communication) {}
}
