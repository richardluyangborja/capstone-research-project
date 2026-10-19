<?php

namespace App\Http\Resources;

use App\Models\Attachment;
use App\Models\Communication;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A single history entry (module 03, spec 11 §Payloads). The `record` block is what the UI
 * links back to; it carries no route of its own — the app builds that from the type.
 *
 * @mixin Communication
 */
class CommunicationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'reference' => $this->reference,
            'subject_type' => $this->recordType(),
            'subject_id' => $this->subject_id,
            'record' => $this->whenLoaded('subjectable', fn (): ?array => $this->recordBlock()),
            'contact_id' => $this->contact_id,
            'contact' => $this->whenLoaded('contact', fn (): ?array => $this->contact === null ? null : [
                'id' => $this->contact->id,
                'name' => $this->contact->name,
                'email' => $this->contact->email,
                'role_type' => $this->contact->role_type,
            ]),
            'channel' => $this->channel,
            'direction' => $this->direction,
            'status' => $this->status,
            'subject' => $this->subject,
            'body' => $this->body,
            'from_name' => $this->from_name,
            'from_address' => $this->from_address,
            'to_name' => $this->to_name,
            'to_address' => $this->to_address,
            'occurred_at' => $this->occurred_at?->toIso8601String(),
            'duration_minutes' => $this->duration_minutes,
            'outcome' => $this->outcome,
            'external_id' => $this->external_id,
            'failed_reason' => $this->failed_reason,
            'thread_id' => $this->thread_id,
            // Threading (slice S4): what this message answers, and whether it may be answered
            // in turn. A draft, a scheduled follow-up or a rejected send has not happened, so
            // nothing can reply to it — that is a state rule, not a permission.
            'in_reply_to_id' => $this->in_reply_to_id,
            'in_reply_to' => $this->whenLoaded('inReplyTo', fn (): ?array => $this->inReplyTo === null ? null : [
                'id' => $this->inReplyTo->id,
                'reference' => $this->inReplyTo->reference,
                'subject' => $this->inReplyTo->subject,
            ]),
            // Files ride with the message when the caller asked for them (the detail screen
            // does; the book only needs the count) — slice S3.
            'attachments' => AttachmentResource::collection($this->whenLoaded('attachments')),
            'attachments_count' => $this->whenCounted('attachments'),
            'owner_id' => $this->owner_id,
            'owner' => $this->whenLoaded('owner', fn (): array => [
                'id' => $this->owner->id,
                'name' => $this->owner->name,
            ]),
            'is_editable' => $this->isEditable(),
            'can_send' => $this->isSendable() && (bool) $request->user()?->can('send', $this->resource),
            'can_attach' => $this->allowsAttachmentChanges()
                && (bool) $request->user()?->can('create', [Attachment::class, $this->resource]),
            'can_reply' => $this->replyLockReason() === null
                && (bool) $request->user()?->can('reply', $this->resource),
            'can_update' => (bool) $request->user()?->can('update', $this->resource),
            'can_delete' => (bool) $request->user()?->can('delete', $this->resource),
            'can_restore' => (bool) $request->user()?->can('restore', $this->resource),
            'deleted_at' => $this->deleted_at?->toIso8601String(),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
