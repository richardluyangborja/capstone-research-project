<?php

namespace App\Events;

use App\Models\Communication;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * The gateway rejected an email (module 03 slice S2, spec 11 §Events). The message is
 * retryable; the attempt is still history, so the timeline mirror is written for it too.
 */
class CommunicationFailed
{
    use Dispatchable, SerializesModels;

    public function __construct(public Communication $communication) {}
}
