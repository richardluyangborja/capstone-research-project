<?php

namespace App\Listeners;

use App\Enums\ActivityStatus;
use App\Enums\ActivityType;
use App\Events\OpportunityStageChanged;

class RecordOpportunityStageChange
{
    /**
     * Record every stage move as a `system` activity so it shows up on the deal timeline
     * with actor + optional note (module 02, mirrors `RecordLeadStatusChange`).
     */
    public function handle(OpportunityStageChanged $event): void
    {
        $ownerId = $event->actor?->id ?? $event->opportunity->owner_id;

        if ($ownerId === null) {
            return;
        }

        $event->opportunity->activities()->create([
            'type' => ActivityType::System,
            'subject' => sprintf('Stage changed %s → %s', $event->from->value, $event->to->value),
            'body' => $event->note,
            'status' => ActivityStatus::Done,
            'owner_id' => $ownerId,
            'occurred_at' => now(),
        ]);
    }
}
