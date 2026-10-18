<?php

namespace App\Listeners;

use App\Events\CommunicationLogged;
use App\Models\Lead;
use App\Models\Opportunity;

class RecordCommunicationActivity
{
    /**
     * A logged communication shows up on the record's existing timeline and restarts the
     * aging clock (module 03, spec 11 §Business rules 2, ADR-0017 §2) — the same follow-up
     * `RecordLeadStatusChange` and `RecordOpportunityStageChange` perform for their events.
     */
    public function handle(CommunicationLogged $event): void
    {
        $communication = $event->communication;

        $communication->syncActivityMirror();

        $subject = $communication->subjectable;

        // Only leads and deals track a last-touch stamp; a company's history is its counts.
        if ($subject instanceof Lead || $subject instanceof Opportunity) {
            $subject->last_activity_at = $communication->occurred_at;
            $subject->save();
        }
    }
}
