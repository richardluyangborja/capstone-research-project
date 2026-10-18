<?php

namespace App\Listeners;

use App\Events\CommunicationFailed;
use App\Events\CommunicationLogged;
use App\Events\CommunicationSent;
use App\Models\Lead;
use App\Models\Opportunity;

class RecordCommunicationActivity
{
    /**
     * A logged, sent or failed communication shows up on the record's existing timeline and
     * restarts the aging clock (module 03, spec 11 §Business rules 2, ADR-0017 §2) — the same
     * follow-up `RecordLeadStatusChange` and `RecordOpportunityStageChange` perform for their
     * events.
     *
     * A draft is absent from the list on purpose: nothing has happened yet, so it must not
     * move a record's clock (slice S2, `Communication::syncActivityMirror()`).
     */
    public function handle(CommunicationLogged|CommunicationSent|CommunicationFailed $event): void
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
