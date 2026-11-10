<?php

namespace App\Http\Resources;

use App\Http\Audit\AuditPresenter;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One audit row (module 08 slice S5, spec 19 §Audit event vocabulary).
 *
 * The row is already redacted at write time; this resource masks contact PII again for the
 * response, resolves the actor and subject to their ULID public ids, and never exposes the
 * internal bigint keys or a secret value.
 *
 * @mixin AuditLog
 */
class AuditLogResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'action' => $this->action,
            'severity' => $this->severity->value,
            'actor' => $this->whenLoaded('actor', fn (): ?array => $this->actor === null ? null : [
                'id' => $this->actor->public_id,
                'name' => $this->actor->name,
                'email' => AuditPresenter::maskEmail($this->actor->email),
            ]),
            'subject' => $this->whenLoaded('subject', fn (): ?array => $this->subject === null ? null : [
                'type' => AuditPresenter::subjectLabel($this->subject_type),
                'id' => $this->subject instanceof User ? $this->subject->public_id : null,
                'name' => $this->subject instanceof User ? $this->subject->name : null,
            ]),
            'before' => AuditPresenter::maskSnapshot($this->before),
            'after' => AuditPresenter::maskSnapshot($this->after),
            'request' => [
                'method' => $this->request_method,
                'path' => $this->request_path,
                'ip' => $this->request_ip,
                'user_agent' => $this->request_user_agent,
                'id' => $this->request_id,
            ],
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
