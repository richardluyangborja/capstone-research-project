<?php

namespace App\Listeners;

use App\Events\FollowUpAssigned;
use App\Notifications\FollowUpAssignedNotification;

/**
 * Tell the person who now owes the follow-up (module 04 slice S3, spec 12 §10).
 */
class NotifyFollowUpOwner
{
    public function handle(FollowUpAssigned $event): void
    {
        // Work you put on yourself needs no telling.
        if ($event->actor?->id === $event->newOwner->id) {
            return;
        }

        $event->newOwner->notify(new FollowUpAssignedNotification($event->task, $event->actor));
    }
}
