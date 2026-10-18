<?php

namespace App\Http\Resources;

use App\Enums\ActivityType;
use App\Models\Activity;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A single activity / timeline entry. Shared by the lead and company timelines
 * (submodules 04 and 06) so the payload shape stays identical.
 *
 * @mixin Activity
 */
class ActivityResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'category' => $this->type === ActivityType::System ? 'system' : 'activity',
            'type' => $this->type,
            'subject' => $this->subject,
            'body' => $this->body,
            'status' => $this->status,
            'occurred_at' => $this->occurred_at,
            // Set when this entry mirrors a detailed communication (module 03), so the
            // timeline can link through to the record instead of duplicating it.
            'communication_id' => $this->communication_id,
            'owner' => $this->owner
                ? ['id' => $this->owner->id, 'name' => $this->owner->name]
                : null,
            'created_at' => $this->created_at,
        ];
    }
}
