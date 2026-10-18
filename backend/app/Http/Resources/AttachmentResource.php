<?php

namespace App\Http\Resources;

use App\Models\Attachment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A file on a record (module 03 slice S3, spec 11 §Payloads). The stored path and disk are
 * deliberately absent: the bytes are streamed from `GET /attachments/{id}/file`, so the only
 * handle the client gets is the attachment's own id.
 *
 * @mixin Attachment
 */
class AttachmentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->original_name,
            'mime_type' => $this->mime_type,
            'size' => $this->size,
            'uploaded_by' => $this->uploaded_by,
            'uploader' => $this->whenLoaded('uploader', fn (): array => [
                'id' => $this->uploader->id,
                'name' => $this->uploader->name,
            ]),
            'can_delete' => (bool) $request->user()?->can('delete', $this->resource),
            'created_at' => $this->created_at,
        ];
    }
}
