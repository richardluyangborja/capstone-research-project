<?php

namespace App\Events;

use App\Models\Communication;
use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * The gateway rejected an email (module 03 slice S2, spec 11 §Events). The message is
 * retryable; the attempt is still history, so the timeline mirror is written for it too.
 *
 * `actor` is whoever pressed send, or null when the attempt was unattended — a due dispatch
 * (module 04 slice S3, spec 12 §10). The author is told about the unattended ones.
 */
class CommunicationFailed
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public Communication $communication,
        public ?User $actor = null,
    ) {}
}
