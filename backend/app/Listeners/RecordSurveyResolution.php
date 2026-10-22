<?php

namespace App\Listeners;

use App\Enums\ActivityStatus;
use App\Enums\ActivityType;
use App\Events\SurveyResponseResolved;

/**
 * The resolution is history on the account (module 05 slice S4, spec 13 §Events): a system
 * activity carries the note, so the account timeline says the unhappy answer was dealt with
 * without the survey tables teaching the timeline about surveys.
 */
class RecordSurveyResolution
{
    public function handle(SurveyResponseResolved $event): void
    {
        $response = $event->response;
        $response->loadMissing('company');

        if ($response->company === null) {
            return;
        }

        $response->company->activities()->create([
            'type' => ActivityType::System,
            'subject' => sprintf('Survey response resolved — %s', $response->scoreLine()),
            'body' => $response->resolution_note,
            'status' => ActivityStatus::Done,
            'owner_id' => $event->resolvedBy->id,
            'occurred_at' => $response->resolved_at ?? now(),
        ]);
    }
}
