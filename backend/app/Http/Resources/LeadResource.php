<?php

namespace App\Http\Resources;

use App\Models\Lead;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Lead */
class LeadResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'reference' => $this->reference,
            'company_name' => $this->company_name,
            'contact_name' => $this->contact_name,
            'contact_email' => $this->contact_email,
            'contact_phone' => $this->contact_phone,
            'contact_position' => $this->contact_position,
            'source' => $this->source,
            'source_detail' => $this->source_detail,
            'industry' => $this->industry,
            'location' => $this->location,
            'service_interest' => $this->service_interest,
            'estimated_headcount' => $this->estimated_headcount,
            'needed_roles' => $this->needed_roles,
            'target_start_date' => $this->target_start_date?->toDateString(),
            'authority_confirmed' => (bool) $this->authority_confirmed,
            'work_site' => $this->work_site,
            'urgency' => $this->urgency,
            'rating' => $this->rating,
            'score' => $this->score,
            'qualification_notes' => $this->qualification_notes,
            'disqualification_reason' => $this->disqualification_reason,
            'status' => $this->status,
            'status_changed_at' => $this->status_changed_at,
            'notes' => $this->notes,
            // Qualification affordances for the UI (spec 02)
            'missing_criteria' => $this->missingQualificationCriteria(),
            'meets_criteria' => $this->meetsQualificationCriteria(),
            'can_qualify' => $this->isQualifiable(),
            'owner' => $this->whenLoaded('owner', fn () => [
                'id' => $this->owner->id,
                'name' => $this->owner->name,
            ]),
            'owner_id' => $this->owner_id,
            // Sensitive: hidden from salesrep (ADR-0004)
            'budget_rate' => $this->when(
                $request->user()?->canSeeSensitiveData() ?? false,
                $this->budget_rate,
            ),
            'last_activity_at' => $this->last_activity_at,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
