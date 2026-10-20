<?php

namespace App\Listeners;

use App\Enums\ActivityStatus;
use App\Enums\ActivityType;
use App\Events\SurveyResponseSubmitted;

class RecordSurveyResponse
{
    /**
     * A submitted answer is history on the account, so the client's timeline says it happened
     * (spec 13 §Events) without the survey tables teaching the timeline about surveys.
     */
    public function handle(SurveyResponseSubmitted $event): void
    {
        $response = $event->response;
        $company = $response->company;
        $ownerId = $company?->owner_id ?? $response->invitation?->created_by;

        if ($company === null || $ownerId === null) {
            return;
        }

        $scores = array_filter([
            $response->csat !== null ? sprintf('CSAT %s/5', number_format($response->csat, 2, '.', '')) : null,
            $response->nps !== null ? sprintf('NPS %d/10', $response->nps) : null,
        ]);

        $company->activities()->create([
            'type' => ActivityType::System,
            'subject' => sprintf(
                'Survey response received — %s%s',
                $response->survey?->name ?? 'survey',
                $scores === [] ? '' : ': '.implode(' · ', $scores),
            ),
            'status' => ActivityStatus::Done,
            'owner_id' => $ownerId,
            'occurred_at' => $response->submitted_at ?? now(),
        ]);
    }
}
