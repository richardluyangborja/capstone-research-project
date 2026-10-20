<?php

namespace App\Http\Resources;

use App\Enums\FollowUpDueState;
use App\Models\Activity;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A follow-up task on its own (module 04, spec 12 §Payloads): `GET /tasks/{id}` and the body of
 * every write. The worklist row adds a `kind` to this same shape, so a task reads the same
 * wherever it is shown.
 *
 * @mixin Activity
 */
class TaskResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $user = $request->user();
        $source = $this->source();

        return [
            'id' => $this->id,
            'subject' => $this->subject,
            'body' => $this->body,
            'record' => $this->whenLoaded('subjectable', fn (): ?array => $this->recordBlock()),
            'owner' => $this->whenLoaded('owner', fn (): ?array => $this->owner === null ? null : [
                'id' => $this->owner->id,
                'name' => $this->owner->name,
            ]),
            'owner_id' => $this->owner_id,
            'due_at' => $this->due_at,
            'due' => FollowUpDueState::block($this->due_at),
            'status' => [
                'value' => $this->status->value,
                'label' => $this->status->label(),
            ],
            // Where a generated reminder came from, and the key it stands on — null on a task
            // somebody wrote by hand (spec 12 §11). A scheduled message is never generated.
            'source' => $source === null ? null : ['value' => $source->value, 'label' => $source->label()],
            'source_key' => $this->source_key,
            // A task is not a message: it has no channel to send on and nothing to delete —
            // it is cancelled instead (spec 12 §9). The worklist row carries the same keys for
            // both shapes so the UI never has to guess.
            'channel' => null,
            'recipient' => null,
            'can_update' => $user?->can('update', $this->resource) ?? false,
            'can_transition' => $user?->can('transition', $this->resource) ?? false,
            'can_send' => false,
            'can_delete' => false,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
