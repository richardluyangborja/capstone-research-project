<?php

namespace App\Events;

use App\Models\Contact;
use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A company's primary contact changed (spec 07 §Events) — the flag moved, whether by an
 * explicit request or because the previous primary was deactivated or removed.
 */
class ContactPrimaryChanged
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public Contact $contact,
        public ?Contact $previous = null,
        public ?User $actor = null,
    ) {}
}
