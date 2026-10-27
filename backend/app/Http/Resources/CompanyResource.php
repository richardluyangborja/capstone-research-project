<?php

namespace App\Http\Resources;

use App\Enums\CompanyStatus;
use App\Enums\FollowUpDueState;
use App\Enums\HandoffStatus;
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
            'churn_reason' => $this->churn_reason,
            'churned_at' => $this->churned_at?->toIso8601String(),
            // Mocked Service handoff (spec 09 §2): a failed request is retryable.
            'handoff' => [
                'status' => $this->handoff_status,
                'reference' => $this->handoff_reference,
                'error' => $this->handoff_error,
                'requested_at' => $this->handoff_requested_at?->toIso8601String(),
                'can_retry' => $this->handoff_status === HandoffStatus::Failed
                    && (bool) $request->user()?->can('retryHandoff', $this->resource),
            ],
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
            // Roll-up counts (spec 06 §API). Contracts join in submodule 09, the communication
            // history in module 03.
            'counts' => [
                'sites' => (int) ($this->sites_count ?? 0),
                'branches' => (int) ($this->children_count ?? 0),
                'contacts' => (int) ($this->contacts_count ?? 0),
                'opportunities' => (int) ($this->opportunities_count ?? 0),
                'open_opportunities' => (int) ($this->open_opportunities_count ?? 0),
                'contracts' => (int) ($this->contracts_count ?? 0),
                'activities' => (int) ($this->activities_count ?? 0),
                'communications' => (int) ($this->communications_count ?? 0),
            ],
            // The Account Book's two read-time columns (spec 16 §Account Book). They are
            // populated by AccountBookMetrics on the list; a detail read leaves them null.
            'next_follow_up_at' => $this->next_follow_up_at,
            'next_follow_up' => FollowUpDueState::block($this->next_follow_up_at),
            'last_activity_at' => $this->last_activity_at,
            // The lifecycle stepper offers only legal moves (spec 09 §UI).
            'allowed_status_transitions' => array_map(
                static fn (CompanyStatus $status): string => $status->value,
                $this->status->allowedTransitions(),
            ),
            // Affordances for the UI
            'can_update' => (bool) $request->user()?->can('update', $this->resource),
            'can_manage_sites' => (bool) $request->user()?->can('manageSites', $this->resource),
            'can_manage_parent' => (bool) $request->user()?->can('manageParent', $this->resource),
            'can_assign' => (bool) $request->user()?->can('assign', [$this->resource]),
            'can_delete' => (bool) $request->user()?->can('delete', $this->resource),
            'can_manage_lifecycle' => (bool) $request->user()?->can('manageLifecycle', $this->resource),
            'can_view_billing' => (bool) $request->user()?->can('viewBilling', $this->resource),
            'can_upload_contract' => (bool) $request->user()?->can('uploadContract', $this->resource),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
