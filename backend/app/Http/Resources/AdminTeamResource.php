<?php

namespace App\Http\Resources;

use App\Models\Team;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One administrative team (module 08 slice S3, spec 19).
 *
 * The list carries counts and the manager; the detail additionally carries the member rows.
 * The `can` block is the server's policy answer so the page never offers a refused control.
 *
 * @mixin Team
 */
class AdminTeamResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $actor = $request->user();

        return [
            'id' => $this->id,
            'name' => $this->name,
            'manager' => $this->manager === null ? null : [
                'id' => $this->manager->public_id,
                'name' => $this->manager->name,
                'email' => $this->manager->email,
                'role' => $this->manager->role->value,
                'status' => $this->manager->status->value,
            ],
            'members_count' => $this->whenCounted('users', fn (): int => (int) $this->users_count),
            'members' => AdminTeamMemberResource::collection($this->whenLoaded('users')),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'can' => [
                'update' => (bool) $actor?->can('update', $this->resource),
                'assign_manager' => (bool) $actor?->can('assignManager', $this->resource),
                'add_member' => (bool) $actor?->can('addMember', $this->resource),
                'remove_member' => (bool) $actor?->can('removeMember', $this->resource),
            ],
        ];
    }
}
