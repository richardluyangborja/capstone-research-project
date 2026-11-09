<?php

namespace App\Http\Resources;

use App\Enums\Role;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One directory account (module 08 slice S2, spec 19 §Payloads).
 *
 * The URL key is the ULID `public_id` (ADR-0007) — the internal bigint `users.id` never
 * leaves the API. The abilities travel with the row so the directory offers only the actions
 * the caller actually has, and a salesrep/manager actor never sees these endpoints anyway.
 *
 * @mixin User
 */
class AdminUserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $actor = $request->user();

        return [
            'id' => $this->public_id,
            'name' => $this->name,
            'email' => $this->email,
            'role' => $this->role->value,
            'status' => $this->status->value,
            'team' => $this->team === null ? null : ['id' => $this->team->id, 'name' => $this->team->name],
            'suspended_at' => $this->suspended_at?->toIso8601String(),
            'suspended_by' => $this->whenLoaded('suspender', fn (): ?array => $this->suspender === null ? null : [
                'id' => $this->suspender->public_id,
                'name' => $this->suspender->name,
            ]),
            'status_reason' => $this->status_reason,
            'created_at' => $this->created_at?->toIso8601String(),
            'counts' => $this->whenCounted('leads', fn (): array => [
                'leads' => $this->leads_count ?? 0,
                'companies' => $this->companies_count ?? 0,
                'opportunities' => $this->opportunities_count ?? 0,
            ]),
            'can' => [
                'view' => (bool) $actor?->can('view', $this->resource),
                'update' => (bool) $actor?->can('update', $this->resource),
                'change_role' => (bool) $actor?->can('changeRole', $this->resource),
                'change_team' => (bool) $actor?->can('changeTeam', $this->resource),
                'suspend' => (bool) $actor?->can('suspend', $this->resource),
                'restore' => (bool) $actor?->can('restore', $this->resource),
                'resend_invitation' => (bool) $actor?->can('resendInvitation', $this->resource),
                'view_ownership_impact' => (bool) $actor?->can('viewOwnershipImpact', $this->resource),
                // Only a superadmin sees superadmin accounts at all, so the directory can name
                // the "assign admin" option from the actor's role alone (spec 19 role matrix).
                'can_assign_admin' => $actor?->role === Role::Superadmin,
            ],
        ];
    }
}
