<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ActivityStatus;
use App\Enums\ActivityType;
use App\Enums\DisqualificationReason;
use App\Enums\LeadStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\LogActivityRequest;
use App\Http\Requests\UpdateLeadStatusRequest;
use App\Http\Resources\LeadResource;
use App\Models\Activity;
use App\Models\Lead;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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
            ->additional(['activity' => $this->activityPayload($activity->load('owner:id,name'))])
            ->response();
    }

    /**
     * Chronological status history + activities for the lead timeline (spec 04 §5).
     */
    public function timeline(Request $request, Lead $lead): JsonResponse
    {
        $this->authorize('view', $lead);

        $activities = $lead->activities()
            ->with('owner:id,name')
            ->orderByDesc('occurred_at')
            ->orderByDesc('created_at')
            // ULIDs are time-ordered, so this keeps entries created in the same
            // second (e.g. a logged call + the status change it triggered) stable.
            ->orderByDesc('id')
            ->limit(100)
            ->get();

        return response()->json([
            'data' => $activities->map(fn (Activity $activity): array => $this->activityPayload($activity)),
            'meta' => [
                'status' => $lead->status,
                'status_changed_at' => $lead->status_changed_at,
                'last_activity_at' => $lead->last_activity_at,
                'aging' => $lead->agingLevel()?->value,
                'days_since_activity' => $lead->daysSinceActivity(),
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function activityPayload(Activity $activity): array
    {
        return [
            'id' => $activity->id,
            'category' => $activity->type === ActivityType::System ? 'system' : 'activity',
            'type' => $activity->type,
            'subject' => $activity->subject,
            'body' => $activity->body,
            'status' => $activity->status,
            'occurred_at' => $activity->occurred_at,
            'owner' => $activity->owner
                ? ['id' => $activity->owner->id, 'name' => $activity->owner->name]
                : null,
            'created_at' => $activity->created_at,
        ];
    }
}
