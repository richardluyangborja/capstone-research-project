<?php

namespace App\Http\Resources;

use App\Enums\SurveyInvitationState;
use App\Models\SurveyInvitation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One send of a survey (module 05 slice S2, spec 13 §Payloads).
 *
 * `state` is the derived reading (`sent`, `expired`, `submitted`, `cancelled`, `failed`), while
 * `status` is what the row stores. The public link travels only while the client can still use
 * it — an open invitation is the only one worth copying. The abilities let the book offer
 * Cancel/Resend only where they are legal and permitted.
 *
 * @mixin SurveyInvitation
 */
class SurveyInvitationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $state = $this->state();
        $answerable = $this->isAnswerable();
        $withdrawable = in_array($state, [
            SurveyInvitationState::Sent,
            SurveyInvitationState::Expired,
            SurveyInvitationState::Failed,
        ], true);

        return [
            'id' => $this->id,
            'reference' => $this->reference,
            'survey' => $this->whenLoaded('survey', fn (): ?array => $this->survey === null ? null : [
                'id' => $this->survey->id,
                'name' => $this->survey->name,
                'description' => $this->survey->description,
            ]),
            'company' => $this->whenLoaded('company', fn (): ?array => $this->company === null ? null : [
                'id' => $this->company->id,
                'name' => $this->company->name,
            ]),
            'contact' => $this->whenLoaded('contact', fn (): ?array => $this->contact === null ? null : [
                'id' => $this->contact->id,
                'name' => $this->contact->name,
                'email' => $this->contact->email,
            ]),
            'contract' => $this->whenLoaded('contract', fn (): ?array => $this->contract === null ? null : [
                'id' => $this->contract->id,
                'title' => $this->contract->title,
                'reference' => $this->contract->reference,
            ]),
            'recipient_name' => $this->recipient_name,
            'recipient_email' => $this->recipient_email,
            'message' => $this->message,
            'status' => $this->status?->value,
            'state' => $state->value,
            'state_label' => $state->label(),
            'failed_reason' => $this->failed_reason,
            'sent_at' => $this->sent_at?->toIso8601String(),
            'expires_at' => $this->expires_at?->toIso8601String(),
            'submitted_at' => $this->submitted_at?->toIso8601String(),
            'public_url' => $this->when($answerable, fn (): string => $this->publicUrl()),
            'communication' => $this->whenLoaded('communication', fn (): ?array => $this->communication === null ? null : [
                'id' => $this->communication->id,
                'reference' => $this->communication->reference,
                'subject' => $this->communication->subject,
                'status' => $this->communication->status?->value,
                'external_id' => $this->communication->external_id,
            ]),
            'response' => SurveyResponseResource::make($this->whenLoaded('response')),
            'can_cancel' => $withdrawable && (bool) $request->user()?->can('cancel', $this->resource),
            'can_resend' => $withdrawable && (bool) $request->user()?->can('resend', $this->resource),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
