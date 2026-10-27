<?php

namespace App\Listeners;

use App\Enums\ActivityStatus;
use App\Enums\ActivityType;
use App\Events\CompanyAssigned;
use App\Notifications\CompanyAssignedNotification;

class RecordCompanyAssignment
{
    /**
     * Record the ownership change as a `system` activity and tell the new owner (spec 16 S5).
     */
    public function handle(CompanyAssigned $event): void
    {
        $previous = $event->previousOwner?->name ?? 'Unassigned';

        $event->company->activities()->create([
            'type' => ActivityType::System,
            'subject' => sprintf('Account owner changed %s → %s', $previous, $event->newOwner->name),
            'status' => ActivityStatus::Done,
            'owner_id' => $event->actor?->id ?? $event->newOwner->id,
            'occurred_at' => now(),
        ]);

        // No self-notification when someone assigns an account to themselves.
        if ($event->actor?->id === $event->newOwner->id) {
            return;
        }

        $event->newOwner->notify(new CompanyAssignedNotification($event->company, $event->actor));
    }
}
