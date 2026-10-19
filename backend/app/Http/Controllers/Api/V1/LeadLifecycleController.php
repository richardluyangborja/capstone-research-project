<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ActivityStatus;
use App\Enums\ActivityType;
use App\Enums\DisqualificationReason;
use App\Enums\LeadStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\LogActivityRequest;
use App\Http\Requests\UpdateLeadStatusRequest;
use App\Http\Resources\ActivityResource;
use App\Http\Resources\LeadResource;
use App\Models\Activity;
use App\Models\Lead;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class LeadLifecycleController extends Controller
{
    /**
     * Transition a lead to another legal state (spec 04 §1–2).
     */
    public function updateStatus(UpdateLeadStatusRequest $request, Lead $lead): LeadResource
    {
        $this->authorize('update', $lead);

        $to = LeadStatus::from($request->validated('status'));

        if ($to === LeadStatus::Disqualified && $request->filled('reason')) {
            $lead->disqualification_reason = DisqualificationReason::from($request->validated('reason'));
        }

        $lead->transitionTo($to, $request->user(), $request->validated('note'));

        return new LeadResource($lead->load('owner'));
    }

    /**
     * Convenience shortcut to the `archived` state (spec 04 §4).
     */
    public function archive(Request $request, Lead $lead): LeadResource
    {
        $this->authorize('update', $lead);

        $lead->transitionTo(LeadStatus::Archived, $request->user());

        return new LeadResource($lead->load('owner'));
    }

    /**
     * Log an activity against a lead (spec 04 §2). The first outbound contact on a
     * `new` lead advances it to `contacted`.
     */
    public function logActivity(LogActivityRequest $request, Lead $lead): JsonResponse
    {
        $this->authorize('update', $lead);

        $data = $request->validated();
        $type = ActivityType::from($data['type']);
        $occurredAt = isset($data['occurred_at']) ? Carbon::parse($data['occurred_at']) : now();

        $activity = DB::transaction(function () use ($lead, $request, $data, $type, $occurredAt): Activity {
            /** @var Activity $activity */
            $activity = $lead->activities()->create([
                'type' => $type,
                'subject' => $data['subject'],
                'body' => $data['body'] ?? null,
                'status' => $data['status'] ?? ActivityStatus::Done,
                'occurred_at' => $occurredAt,
                'owner_id' => $request->user()->id,
            ]);

            $lead->last_activity_at = $occurredAt;
            $lead->save();

            if ($lead->status === LeadStatus::New && $type->isOutboundContact()) {
                $lead->transitionTo(LeadStatus::Contacted, $request->user(), 'Auto-advanced on first outbound activity.');
            }

            return $activity;
        });

        return (new LeadResource($lead->load('owner')))
            ->additional([
                'activity' => (new ActivityResource($activity->load('owner:id,name')))->resolve(),
            ])
            ->response();
    }

    /**
     * Chronological status history + activities for the lead timeline (spec 04 §5).
     */
    public function timeline(Request $request, Lead $lead): AnonymousResourceCollection
    {
        $this->authorize('view', $lead);

        $activities = $lead->activities()
            ->with('owner:id,name')
            // What is still owed first, then what happened — one order for every feed
            // (module 04, spec 12 §UI), so a task reads the same here and in the worklist.
            ->timelineOrder()
            ->limit(100)
            ->get();

        return ActivityResource::collection($activities)->additional([
            'meta' => [
                'status' => $lead->status,
                'status_changed_at' => $lead->status_changed_at,
                'last_activity_at' => $lead->last_activity_at,
                'aging' => $lead->agingLevel()?->value,
                'days_since_activity' => $lead->daysSinceActivity(),
            ],
        ]);
    }
}
