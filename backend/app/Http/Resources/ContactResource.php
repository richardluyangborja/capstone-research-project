<?php

namespace App\Http\Resources;

use App\Models\Contact;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Contact */
class ContactResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $user = $request->user();

        return [
            'id' => $this->id,
            'company_id' => $this->company_id,
            'company' => $this->whenLoaded('company', fn () => [
                'id' => $this->company->id,
                'reference' => $this->company->reference,
                'name' => $this->company->name,
                'status' => $this->company->status,
            ]),
            'name' => $this->name,
            'position' => $this->position,
            'role_type' => $this->role_type,
            'is_decision_maker' => $this->role_type->isDecisionMaker(),
            'email' => $this->email,
            'phone' => $this->phone,
            'mobile' => $this->mobile,
            'is_primary' => $this->is_primary,
            'status' => $this->status,
            'notes' => $this->notes,
            // Affordances for the UI
            'can_update' => (bool) $user?->can('update', $this->resource),
            'can_make_primary' => ! $this->is_primary
                && $this->isActive()
                && (bool) $user?->can('makePrimary', $this->resource),
            'can_delete' => (bool) $user?->can('delete', $this->resource),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
