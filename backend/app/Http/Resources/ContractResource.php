<?php

namespace App\Http\Resources;

use App\Models\Contract;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Contract */
class ContractResource extends JsonResource
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
                'status' => $this->company->status,
            ]),
            'opportunity_id' => $this->opportunity_id,
            'opportunity' => $this->whenLoaded('opportunity', fn () => $this->opportunity === null ? null : [
                'id' => $this->opportunity->id,
                'reference' => $this->opportunity->reference,
                'name' => $this->opportunity->name,
            ]),
            'renewal_of_contract_id' => $this->renewal_of_contract_id,
            'renewal_of' => $this->whenLoaded('renewalOf', fn () => $this->renewalOf === null ? null : [
                'id' => $this->renewalOf->id,
                'reference' => $this->renewalOf->reference,
                'end_date' => $this->renewalOf->end_date?->toDateString(),
            ]),
            'title' => $this->title,
            'status' => $this->status,
            'start_date' => $this->start_date?->toDateString(),
            'end_date' => $this->end_date?->toDateString(),
            'signed_at' => $this->signed_at?->toIso8601String(),
            'value' => $this->value,
            'currency' => $this->currency,
            // The commercial header (spec 16 §Contract commercial model, ADR-0024).
            'payment_terms' => $this->payment_terms,
            'po_reference' => $this->po_reference,
            'auto_renew' => (bool) $this->auto_renew,
            'notice_days' => $this->notice_days,
            'signatory_name' => $this->signatory_name,
            'signatory_position' => $this->signatory_position,
            'terms' => $this->terms,
            'notes' => $this->notes,
            // The document itself is streamed from an authorized endpoint; the storage path
            // is never exposed (spec 09 §API).
            'file' => [
                'present' => $this->hasFile(),
                'name' => $this->file_name,
                'size' => $this->file_size,
                'mime' => $this->file_mime,
            ],
            'days_until_expiry' => $this->daysUntilExpiry(),
            'is_expiring_soon' => $this->isExpiringSoon(),
            'is_signed' => $this->isSigned(),
            'is_active' => $this->isActive(),
            'owner' => $this->whenLoaded('owner', fn () => [
                'id' => $this->owner->id,
                'name' => $this->owner->name,
            ]),
            'owner_id' => $this->owner_id,
            // Signing is deliberately absent: it has its own action (spec 09 §1).
            'allowed_transitions' => array_map(
                static fn ($status): string => $status->value,
                $this->status->accessibleTransitions(),
            ),
            'can_update' => (bool) $request->user()?->can('update', $this->resource),
            'can_mark_signed' => (bool) $request->user()?->can('markSigned', $this->resource),
            'can_delete' => (bool) $request->user()?->can('delete', $this->resource),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
