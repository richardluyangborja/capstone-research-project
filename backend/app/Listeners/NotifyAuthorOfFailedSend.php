<?php

namespace App\Listeners;

use App\Events\CommunicationFailed;
use App\Notifications\ScheduledSendFailedNotification;

/**
 * Tell the author when a send failed with nobody watching (module 04 slice S3, spec 12 §10): an
 * unattended dispatch, or a retry someone else pressed. A rep looking at their own rejected send
 * already has the reason on screen, so that attempt raises nothing.
 */
class NotifyAuthorOfFailedSend
{
    public function handle(CommunicationFailed $event): void
    {
        $communication = $event->communication;

        if ($event->actor !== null && $event->actor->id === $communication->owner_id) {
            return;
        }

        $communication->owner?->notify(new ScheduledSendFailedNotification($communication));
    }
}
