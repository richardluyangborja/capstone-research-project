<?php

namespace App\Listeners;

use App\Enums\ActivityStatus;
use App\Enums\ActivityType;
use App\Events\CompanyStatusChanged;

class RecordCompanyStatusChange
{
    /**
     * Record every lifecycle change as a `system` activity so it shows up in the company
     * timeline with actor + note (spec 09 §1, spec 06 §API).
     */
    public function handle(CompanyStatusChanged $event): void
    {
        $ownerId = $event->actor?->id ?? $event->company->owner_id;

        if ($ownerId === null) {
            return;
        }

        $event->company->activities()->create([
            'type' => ActivityType::System,
            'subject' => sprintf(
                'Client status changed %s → %s',
                $event->from->value,
                $event->to->value,
            ),
            'body' => $event->note,
            'status' => ActivityStatus::Done,
            'owner_id' => $ownerId,
            'occurred_at' => now(),
        ]);
    }
}
