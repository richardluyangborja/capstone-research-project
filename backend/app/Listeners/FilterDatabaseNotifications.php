<?php

namespace App\Listeners;

use Illuminate\Notifications\Events\NotificationSending;

/**
 * Module 08 slice S4 (spec 19, ADR-0030): the `notifications.database_enabled` switch.
 *
 * Rather than teaching every notification class about the setting, the send-time event
 * carries the veto: returning `false` from a `NotificationSending` listener cancels that
 * delivery. Mandatory security notifications are not a separate channel, so the setting
 * governs database delivery as a whole — an off switch silences the bell, not the audit
 * trail, which is append-only and unaffected.
 */
class FilterDatabaseNotifications
{
    public function handle(NotificationSending $event): bool
    {
        if ($event->channel !== 'database') {
            return true;
        }

        if (config('notifications.database_enabled', true) === false) {
            return false;
        }

        $mandatory = str_contains(strtolower(class_basename($event->notification)), 'security');
        if (! $mandatory && data_get($event->notifiable, 'preferences.notification_database') === false) {
            return false;
        }

        return true;
    }
}
