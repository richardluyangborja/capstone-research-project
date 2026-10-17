<?php

namespace App\Http\Resources;

use App\Models\Opportunity;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Opportunity */
class OpportunityResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'reference' => $this->reference,
            'company_id' => $this->company_id,
            'company' => $this->whenLoaded('company', fn () => [
                'id' => $this->company->id,
                'reference' => $this->company->reference,
                'name' => $this->company->name,
            ]),
            'lead_id' => $this->lead_id,
            'lead' => $this->whenLoaded('lead', fn () => $this->lead === null ? null : [
                'id' => $this->lead->id,
                'reference' => $this->lead->reference,
            ]),
            'name' => $this->name,
            'stage' => $this->stage,
            'probability' => $this->probability,
            // Deal value is commercial context the owner works with, so anyone who can see
            // the deal sees its amount (ADR-0012). Rate cards and margins stay hidden.
            'amount' => $this->amount,
            'currency' => $this->currency,
            'expected_close_date' => $this->expected_close_date?->toDateString(),
            'actual_close_date' => $this->actual_close_date?->toDateString(),
            'source' => $this->source,
            'primary_contact_id' => $this->primary_contact_id,
            'primary_contact' => $this->whenLoaded('primaryContact', fn () => [
                'id' => $this->primaryContact->id,
                'name' => $this->primaryContact->name,
                'role_type' => $this->primaryContact->role_type,
            ]),
            'owner' => $this->whenLoaded('owner', fn () => [
                'id' => $this->owner->id,
                'name' => $this->owner->name,
            ]),
            'owner_id' => $this->owner_id,
            'lost_reason' => $this->lost_reason,
            'is_open' => $this->isOpen(),
            'is_won' => $this->isWon(),
            'is_lost' => $this->isLost(),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
