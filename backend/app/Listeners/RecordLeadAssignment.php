<?php

namespace App\Listeners;

use App\Enums\ActivityStatus;
use App\Enums\ActivityType;
use App\Events\LeadAssigned;
use App\Notifications\LeadAssignedNotification;

class RecordLeadAssignment
{
    /**
     * Record the ownership change as an activity and notify the new owner (spec 03 §4).
     */
    public function handle(LeadAssigned $event): void
    {
        $previous = $event->previousOwner?->name ?? 'Unassigned';

        $event->lead->activities()->create([
            'type' => ActivityType::System,
            'subject' => sprintf('Owner changed %s → %s', $previous, $event->newOwner->name),
            'status' => ActivityStatus::Done,
            'owner_id' => $event->actor?->id ?? $event->newOwner->id,
            'occurred_at' => now(),
        ]);

        // No self-notification when someone claims/assigns a lead to themselves.
        if ($event->actor?->id === $event->newOwner->id) {
            return;
        }

        $event->newOwner->notify(new LeadAssignedNotification($event->lead, $event->actor));
    }
}
