<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One team member row (module 08 slice S3, spec 19). `is_manager` is set by the controller from
 * the team's `manager_id`, so the UI can mark the leader without a second lookup.
 *
 * @mixin User
 */
class AdminTeamMemberResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'name' => $this->name,
            'email' => $this->email,
            'role' => $this->role->value,
            'status' => $this->status->value,
            'is_manager' => (bool) ($this->is_manager ?? false),
        ];
    }
}
