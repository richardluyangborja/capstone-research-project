<?php

namespace App\Listeners;

use App\Enums\ActivityStatus;
use App\Enums\ActivityType;
use App\Events\LeadStatusChanged;

class RecordLeadStatusChange
{
    /**
     * Record every status change as a `system` activity so it shows up in the
     * lead timeline with actor + optional note (spec 04 §2, §5).
     */
    public function handle(LeadStatusChanged $event): void
    {
        $ownerId = $event->actor?->id ?? $event->lead->owner_id;

        if ($ownerId === null) {
            return;
        }

        $event->lead->activities()->create([
            'type' => ActivityType::System,
            'subject' => sprintf('Status changed %s → %s', $event->from->value, $event->to->value),
            'body' => $event->note,
            'status' => ActivityStatus::Done,
            'owner_id' => $ownerId,
            'occurred_at' => now(),
        ]);
    }
}
