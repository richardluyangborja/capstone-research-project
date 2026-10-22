<?php

namespace App\Listeners;

use App\Enums\ActivityStatus;
use App\Enums\ActivityType;
use App\Events\SurveyResponseFlagged;
use App\Models\Activity;
use App\Notifications\SurveyAttentionNotification;
use Illuminate\Support\Carbon;

/**
 * Turn an unhappy answer into work (module 05 slice S4, spec 13 §Business rules 8).
 *
 * One notification for the account owner and exactly one follow-up task on the company, owned by
 * that owner. The task's `source_key` (`satisfaction:{response}`) makes a repeated event a no-op,
 * the same idempotence module 04's generator uses. The task is deliberately not completed when the
 * response is resolved: the call may still happen afterwards, and the worklist is where it is
 * closed (spec 13 §Business rules 8).
 */
class RaiseSurveyFollowUp
{
    public function handle(SurveyResponseFlagged $event): void
    {
        $response = $event->response;
        $response->loadMissing(['company.owner', 'survey', 'contact']);

        $company = $response->company;
        $owner = $company?->owner;

        // Never invent an owner: an account nobody owns has nobody to tell or to owe the call.
        if ($company === null || $owner === null) {
            return;
        }

        $key = 'satisfaction:'.$response->id;

        if (Activity::query()->where('source_key', $key)->exists()) {
            return;
        }

        $scoreLine = $response->scoreLine();
        $contact = $response->contact?->name ?? 'the client';

        $task = $company->activities()->create([
            'type' => ActivityType::Task,
            'subject' => sprintf('Call back %s — %s', $company->name, $scoreLine),
            'body' => sprintf(
                'Survey "%s" was flagged for attention (%s). Reach %s and close the loop.',
                $response->survey?->name ?? 'survey',
                $scoreLine,
                $contact,
            ),
            'status' => ActivityStatus::Open,
            // Next business morning in the CRM's timezone, like a generated reminder.
            'due_at' => Carbon::now()
                ->setTimezone(config('crm.timezone'))
                ->addDay()
                ->setTime(9, 0)
                ->utc(),
            'owner_id' => $owner->id,
            'source_key' => $key,
        ]);

        $task->syncSubjectFollowUp();

        $owner->notify(new SurveyAttentionNotification($response));
    }
}
