<?php

namespace App\Http\Resources;

use App\Enums\LeadStatus;
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
            // Assignment affordances (spec 03)
            'can_claim' => $this->owner_id === null,
            'can_reassign' => (bool) $request->user()?->can('assign', [$this->resource]),
            'can_update' => (bool) $request->user()?->can('update', $this->resource),
            // Lifecycle affordances (spec 04)
            'allowed_transitions' => array_map(
                fn ($status): string => $status->value,
                $this->status->actionableTransitions(),
            ),
            'is_archived' => $this->status === LeadStatus::Archived,
            'aging' => $this->agingLevel()?->value,
            'days_since_activity' => $this->daysSinceActivity(),
            // Conversion affordances (spec 08) — only a qualified lead the user may edit
            // can become a client, and only through the conversion flow (ADR-0009).
            'can_convert' => $this->status === LeadStatus::Qualified
                && (bool) $request->user()?->can('convert', $this->resource),
            'is_converted' => $this->status === LeadStatus::Converted,
            'converted_company_id' => $this->converted_company_id,
            'converted_opportunity_id' => $this->converted_opportunity_id,
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
