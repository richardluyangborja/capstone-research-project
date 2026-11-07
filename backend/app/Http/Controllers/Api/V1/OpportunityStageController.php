<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\LostReason;
use App\Enums\OpportunityStage;
use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateOpportunityStageRequest;
use App\Http\Resources\OpportunityResource;
use App\Models\Opportunity;
use Illuminate\Validation\ValidationException;

/**
 * Move a deal through the pipeline (module 02 §3). Any-to-any with rules — the single
 * definition is `OpportunityStage::allowedTransitions()` + `Opportunity::transitionTo()`
 * (ADR-0015 §1). Entering `won` may carry an explicit close date, a win note and — for a
 * manager+ caller with `setAmount` — the final negotiated amount (spec 18 S3); a `won`
 * deal never runs the Service handoff, which is owned by the external contract system
 * (ADR-0027).
 */
class OpportunityStageController extends Controller
{
    public function update(UpdateOpportunityStageRequest $request, Opportunity $opportunity): OpportunityResource
    {
        $stage = OpportunityStage::from($request->validated('stage'));
        $lostReason = $request->filled('lost_reason')
            ? LostReason::from($request->validated('lost_reason'))
            : null;

        // Only a manager+ caller may confirm/adjust the final won amount (spec 18 S3).
        $amount = $stage === OpportunityStage::Won && $request->filled('amount')
            ? (float) $request->validated('amount')
            : null;
        if ($amount !== null && ! $request->user()->can('setAmount', $opportunity)) {
            throw ValidationException::withMessages([
                'amount' => ['Only a manager or admin may set the deal amount.'],
            ]);
        }

        $opportunity->transitionTo(
            $stage,
            $request->user(),
            $lostReason,
            $request->validated('probability'),
            $request->validated('note'),
            $request->validated('actual_close_date'),
            $request->validated('win_note'),
            $amount,
        );

        return new OpportunityResource($opportunity->fresh()->load([
            'company', 'lead', 'owner:id,name', 'primaryContact:id,name,role_type', 'contracts',
        ]));
    }
}
