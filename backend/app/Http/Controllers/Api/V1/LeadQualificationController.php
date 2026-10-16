<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\LeadStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\DisqualifyLeadRequest;
use App\Http\Requests\RateLeadRequest;
use App\Http\Requests\UpdateQualificationRequest;
use App\Http\Resources\LeadResource;
use App\Models\Lead;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class LeadQualificationController extends Controller
{
    public function updateQualification(UpdateQualificationRequest $request, Lead $lead): LeadResource
    {
        $this->authorize('update', $lead);
        $this->guardEditable($lead);

        $lead->fill($request->validated());
        $lead->score = $lead->qualificationScore();
        $lead->rating ??= $lead->deriveRating();
        $lead->last_activity_at = now();
        $lead->save();

        return new LeadResource($lead->load('owner'));
    }

    public function rate(RateLeadRequest $request, Lead $lead): LeadResource
    {
        $this->authorize('update', $lead);
        $this->guardEditable($lead);

        $data = $request->validated();
        $lead->score = $data['score'] ?? $lead->qualificationScore();
        $lead->rating = $data['rating'] ?? $lead->deriveRating();
        $lead->last_activity_at = now();
        $lead->save();

        return new LeadResource($lead->load('owner'));
    }

    public function qualify(Request $request, Lead $lead): LeadResource
    {
        $this->authorize('update', $lead);
        $this->guardEditable($lead);

        $lead->score ??= $lead->qualificationScore();
        $lead->rating ??= $lead->deriveRating();
        $lead->transitionTo(LeadStatus::Qualified, $request->user());

        return new LeadResource($lead->load('owner'));
    }

    public function disqualify(DisqualifyLeadRequest $request, Lead $lead): LeadResource
    {
        $this->authorize('update', $lead);
        $this->guardEditable($lead);

        $data = $request->validated();

        $lead->disqualification_reason = $data['reason'];

        if (! empty($data['notes'])) {
            $lead->qualification_notes = $data['notes'];
        }

        $lead->transitionTo(LeadStatus::Disqualified, $request->user(), $data['notes'] ?? null);

        return new LeadResource($lead->load('owner'));
    }

    public function nurture(Request $request, Lead $lead): LeadResource
    {
        $this->authorize('update', $lead);
        $this->guardEditable($lead);

        $lead->transitionTo(LeadStatus::Nurture, $request->user());

        return new LeadResource($lead->load('owner'));
    }

    /**
     * Qualification edits are read-only once disqualified/converted/archived (spec 02 §6).
     */
    private function guardEditable(Lead $lead): void
    {
        if (! $lead->isQualifiable()) {
            throw ValidationException::withMessages([
                'status' => ['This lead is read-only in its current status.'],
            ]);
        }
    }
}
