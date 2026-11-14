<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin User */
class EmployeeResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'name' => $this->name,
            'role' => $this->role->value,
            'team' => $this->team === null ? null : ['id' => $this->team->id, 'name' => $this->team->name],
            'profile' => $this->resource->getAttribute('core2_profile'),
            'performance' => $this->resource->getAttribute('performance'),
            'attendance' => $this->resource->getAttribute('attendance'),
            'quota' => $this->resource->getAttribute('quota'),
            'adjusted_quota' => $this->resource->getAttribute('adjusted_quota'),
            'quota_attainment' => $this->resource->getAttribute('quota_attainment'),
            'can_update_quota' => $request->user()?->can('updateEmployeeQuota', $this->resource) ?? false,
        ];
    }
}
