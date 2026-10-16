<?php

namespace App\Http\Controllers\Api\V1;

use App\Events\LeadAssigned;
use App\Http\Controllers\Controller;
use App\Http\Requests\AssignLeadRequest;
use App\Http\Requests\BulkAssignLeadsRequest;
use App\Http\Requests\BulkTransferLeadsRequest;
use App\Http\Resources\LeadResource;
use App\Models\Activity;
use App\Models\Lead;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class LeadAssignmentController extends Controller
{
    public function assign(AssignLeadRequest $request, Lead $lead): LeadResource
    {
        $target = User::findOrFail($request->integer('owner_id'));

        $this->authorize('assign', [$lead, $target]);

        $this->reassign($lead, $target, $request->user());

        return new LeadResource($lead->load('owner'));
    }

    public function claim(Request $request, Lead $lead): LeadResource
    {
        $this->authorize('claim', $lead);

        $this->reassign($lead, $request->user(), $request->user());

        return new LeadResource($lead->load('owner'));
    }

    public function bulkAssign(BulkAssignLeadsRequest $request): JsonResponse
    {
        $target = User::findOrFail($request->integer('owner_id'));

        /** @var Collection<int, Lead> $leads */
        $leads = Lead::whereIn('id', $request->input('lead_ids'))->get();

        $assigned = 0;

        foreach ($leads as $lead) {
            $this->authorize('assign', [$lead, $target]);
            $this->reassign($lead, $target, $request->user());
            $assigned++;
        }

        return response()->json([
            'message' => sprintf('%d lead(s) assigned to %s.', $assigned, $target->name),
            'data' => ['assigned' => $assigned],
        ]);
    }

    public function bulkTransfer(BulkTransferLeadsRequest $request): JsonResponse
    {
        $from = User::findOrFail($request->integer('from_user_id'));
        $to = User::findOrFail($request->integer('to_user_id'));

        /** @var Collection<int, Lead> $leads */
        $leads = Lead::where('owner_id', $from->id)->get();

        foreach ($leads as $lead) {
            $this->reassign($lead, $to, $request->user());
        }

        return response()->json([
            'message' => sprintf('%d lead(s) transferred from %s to %s.', $leads->count(), $from->name, $to->name),
            'data' => ['transferred' => $leads->count()],
        ]);
    }

    public function activities(Request $request, Lead $lead): JsonResponse
    {
        $this->authorize('view', $lead);

        $activities = $lead->activities()
            ->with('owner:id,name')
            ->orderByDesc('occurred_at')
            ->orderByDesc('created_at')
            ->limit(100)
            ->get();

        return response()->json([
            'data' => $activities->map(fn (Activity $activity): array => [
                'id' => $activity->id,
                'type' => $activity->type,
                'subject' => $activity->subject,
                'body' => $activity->body,
                'status' => $activity->status,
                'occurred_at' => $activity->occurred_at,
                'owner' => $activity->owner
                    ? ['id' => $activity->owner->id, 'name' => $activity->owner->name]
                    : null,
                'created_at' => $activity->created_at,
            ]),
        ]);
    }

    /**
     * Persist an ownership change and record the system activity + notification (spec 03 §4).
     */
    private function reassign(Lead $lead, User $target, User $actor): void
    {
        if ($lead->owner_id === $target->id) {
            return;
        }

        DB::transaction(function () use ($lead, $target, $actor): void {
            $previous = $lead->owner;

            $lead->owner_id = $target->id;
            $lead->last_activity_at = now();
            $lead->save();

            LeadAssigned::dispatch($lead, $previous, $target, $actor);
        });
    }
}
