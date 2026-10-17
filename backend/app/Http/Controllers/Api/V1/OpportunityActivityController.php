<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ActivityStatus;
use App\Enums\ActivityType;
use App\Http\Controllers\Controller;
use App\Http\Requests\LogActivityRequest;
use App\Http\Resources\ActivityResource;
use App\Http\Resources\OpportunityResource;
use App\Models\Activity;
use App\Models\Opportunity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The deal timeline (module 02 §4). Mirrors the lead/company activity controllers so the
 * `ActivityResource` payload stays identical across subjects.
 */
class OpportunityActivityController extends Controller
{
    /**
     * Log a touch (call/email/meeting/note/task) against a deal. Refreshes
     * `last_activity_at` so the board's staleness flag reflects real work.
     */
    public function logActivity(LogActivityRequest $request, Opportunity $opportunity): JsonResponse
    {
        $data = $request->validated();
        $occurredAt = isset($data['occurred_at']) ? Carbon::parse($data['occurred_at']) : now();

        $activity = DB::transaction(function () use ($opportunity, $request, $data, $occurredAt): Activity {
            /** @var Activity $activity */
            $activity = $opportunity->activities()->create([
                'type' => ActivityType::from($data['type']),
                'subject' => $data['subject'],
                'body' => $data['body'] ?? null,
                'status' => $data['status'] ?? ActivityStatus::Done,
                'occurred_at' => $occurredAt,
                'owner_id' => $request->user()->id,
            ]);

            $opportunity->last_activity_at = $occurredAt;
            $opportunity->save();

            return $activity;
        });

        return (new OpportunityResource($opportunity->load(['company', 'owner:id,name'])))
            ->additional([
                'activity' => (new ActivityResource($activity->load('owner:id,name')))->resolve(),
            ])
            ->response();
    }

    /**
     * Chronological activity/communication history for the deal timeline.
     */
    public function timeline(Request $request, Opportunity $opportunity): AnonymousResourceCollection
    {
        $this->authorize('view', $opportunity);

        $activities = $opportunity->activities()
            ->with('owner:id,name')
            ->orderByDesc('occurred_at')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(100)
            ->get();

        return ActivityResource::collection($activities)->additional([
            'meta' => [
                'stage' => $opportunity->stage,
                'stage_changed_at' => $opportunity->stage_changed_at?->toIso8601String(),
                'last_activity_at' => $opportunity->last_activity_at?->toIso8601String(),
                'days_in_stage' => $opportunity->daysInStage(),
                'is_stale' => $opportunity->isStale(),
            ],
        ]);
    }
}
