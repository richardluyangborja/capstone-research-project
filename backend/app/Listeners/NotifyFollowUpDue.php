<?php

namespace App\Listeners;

use App\Events\FollowUpDue;
use App\Notifications\FollowUpDueNotification;

/**
 * Tell the owner their follow-up has come round (module 04 slice S3, spec 12 §10). The run that
 * raised the event stamps `reminded_at` right after, so this fires once per task per arming.
 */
class NotifyFollowUpDue
{
    public function handle(FollowUpDue $event): void
    {
        $owner = $event->task->owner;

        if ($owner === null) {
            return;
        }

        $owner->notify(new FollowUpDueNotification($event->task));
    }
}
