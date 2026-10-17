<?php

namespace App\Http\Resources;

use App\Models\Company;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Company */
class CompanyResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'reference' => $this->reference,
            'name' => $this->name,
            'trade_name' => $this->trade_name,
            'industry' => $this->industry,
            'size' => $this->size,
            'website' => $this->website,
            'description' => $this->description,
            'status' => $this->status,
            'is_client' => $this->isClient(),
            'tier' => $this->tier,
            'client_since' => $this->client_since?->toDateString(),
            'tags' => $this->tags ?? [],
            'parent_company_id' => $this->parent_company_id,
            'parent' => $this->whenLoaded('parent', fn () => [
                'id' => $this->parent->id,
                'reference' => $this->parent->reference,
                'name' => $this->parent->name,
            ]),
            'source_lead_id' => $this->source_lead_id,
            'owner' => $this->whenLoaded('owner', fn () => [
                'id' => $this->owner->id,
                'name' => $this->owner->name,
            ]),
            'owner_id' => $this->owner_id,
            // Roll-up counts (spec 06 §API). Opportunity / contract counts join this list
            // as submodules 08–09 land.
            'counts' => [
                'sites' => (int) ($this->sites_count ?? 0),
                'branches' => (int) ($this->children_count ?? 0),
                'contacts' => (int) ($this->contacts_count ?? 0),
                'activities' => (int) ($this->activities_count ?? 0),
            ],
            // Affordances for the UI
            'can_update' => (bool) $request->user()?->can('update', $this->resource),
            'can_manage_sites' => (bool) $request->user()?->can('manageSites', $this->resource),
            'can_manage_parent' => (bool) $request->user()?->can('manageParent', $this->resource),
            'can_assign' => (bool) $request->user()?->can('assign', [$this->resource]),
            'can_delete' => (bool) $request->user()?->can('delete', $this->resource),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
