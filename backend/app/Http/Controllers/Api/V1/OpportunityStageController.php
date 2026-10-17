<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\LostReason;
use App\Enums\OpportunityStage;
use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateOpportunityStageRequest;
use App\Http\Resources\OpportunityResource;
use App\Models\Opportunity;

/**
 * Move a deal through the pipeline (module 02 §3). Any-to-any with rules — the single
 * definition is `OpportunityStage::allowedTransitions()` + `Opportunity::transitionTo()`
 * (ADR-0015 §1). A `won` deal prompts contract creation; it never runs the Service
 * handoff, which stays on `mark-signed` (ADR-0015 §4).
 */
class OpportunityStageController extends Controller
{
    public function update(UpdateOpportunityStageRequest $request, Opportunity $opportunity): OpportunityResource
    {
        $stage = OpportunityStage::from($request->validated('stage'));
        $lostReason = $request->filled('lost_reason')
            ? LostReason::from($request->validated('lost_reason'))
            : null;

        $opportunity->transitionTo(
            $stage,
            $request->user(),
            $lostReason,
            $request->validated('probability'),
            $request->validated('note'),
        );

        return new OpportunityResource($opportunity->fresh()->load([
            'company', 'lead', 'owner:id,name', 'primaryContact:id,name,role_type',
        ]));
    }
}
