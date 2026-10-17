<?php

namespace App\Events;

use App\Models\Contact;
use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A contact was added to a company (spec 07 §Events) — manually now, and by
 * lead→client conversion in submodule 08.
 */
class ContactCreated
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public Contact $contact,
        public ?User $actor = null,
    ) {}
}
