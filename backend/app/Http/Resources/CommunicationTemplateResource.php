<?php

namespace App\Http\Resources;

use App\Models\CommunicationTemplate;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A reusable template (module 03 slice S5, spec 11 §Payloads).
 *
 * The abilities travel with the row so the book offers only the actions the caller actually has:
 * every role reads and renders a template, only manager+ changes one.
 *
 * @mixin CommunicationTemplate
 */
class CommunicationTemplateResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'channel' => $this->channel,
            'subject' => $this->subject,
            'body' => $this->body,
            'is_active' => (bool) $this->is_active,
            'created_by' => $this->created_by,
            'author' => $this->whenLoaded('author', fn (): ?array => $this->author === null ? null : [
                'id' => $this->author->id,
                'name' => $this->author->name,
            ]),
            'can_update' => (bool) $request->user()?->can('update', $this->resource),
            'can_delete' => (bool) $request->user()?->can('delete', $this->resource),
            'can_restore' => (bool) $request->user()?->can('restore', $this->resource),
            'deleted_at' => $this->deleted_at?->toIso8601String(),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
