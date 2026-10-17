<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ActivityStatus;
use App\Enums\ActivityType;
use App\Http\Controllers\Controller;
use App\Http\Requests\LogActivityRequest;
use App\Http\Resources\ActivityResource;
use App\Http\Resources\CompanyResource;
use App\Models\Activity;
use App\Models\Company;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Carbon;

class CompanyActivityController extends Controller
{
    /**
     * Log an activity against a company (spec 06 §API — activity tab).
     */
    public function logActivity(LogActivityRequest $request, Company $company): JsonResponse
    {
        $data = $request->validated();
        $occurredAt = isset($data['occurred_at']) ? Carbon::parse($data['occurred_at']) : now();

        /** @var Activity $activity */
        $activity = $company->activities()->create([
            'type' => ActivityType::from($data['type']),
            'subject' => $data['subject'],
            'body' => $data['body'] ?? null,
            'status' => $data['status'] ?? ActivityStatus::Done,
            'occurred_at' => $occurredAt,
            'owner_id' => $request->user()->id,
        ]);

        $company = $company->load(['owner', 'parent'])->loadCount(['sites', 'children', 'activities']);

        return (new CompanyResource($company))
            ->additional([
                'activity' => (new ActivityResource($activity->load('owner:id,name')))->resolve(),
            ])
            ->response();
    }

    /**
     * Chronological activity/communication history for the company tab (spec 06 §API).
     */
    public function timeline(Request $request, Company $company): AnonymousResourceCollection
    {
        $this->authorize('view', $company);

        $activities = $company->activities()
            ->with('owner:id,name')
            ->orderByDesc('occurred_at')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(100)
            ->get();

        return ActivityResource::collection($activities)->additional([
            'meta' => ['total' => $activities->count()],
        ]);
    }
}
