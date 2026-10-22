<?php

namespace App\Http\Resources;

use App\Models\SurveyResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One submitted answer set (module 05 slices S2–S3, spec 13 §Payloads).
 *
 * The receipt, the book row and the detail all use this shape; the detail adds the answers in
 * the order the survey asked them, with each question's type so it renders by kind.
 *
 * @mixin SurveyResponse
 */
class SurveyResponseResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'submitted_at' => $this->submitted_at?->toIso8601String(),
            'csat' => $this->csat,
            'nps' => $this->nps,
            'band' => $this->band?->value,
            'band_label' => $this->band?->label(),
            'needs_attention' => (bool) $this->needs_attention,
            'resolved' => $this->resolved_at !== null,
            'resolved_at' => $this->resolved_at?->toIso8601String(),
            'resolved_by' => $this->whenLoaded('resolvedBy', fn (): ?array => $this->resolvedBy === null ? null : [
                'id' => $this->resolvedBy->id,
                'name' => $this->resolvedBy->name,
            ]),
            'resolution_note' => $this->resolution_note,
            'can_resolve' => (bool) $this->needs_attention
                && $this->resolved_at === null
                && ($request->user()?->can('resolve', $this->resource) ?? false),
            'survey' => $this->whenLoaded('survey', fn (): ?array => $this->survey === null ? null : [
                'id' => $this->survey->id,
                'name' => $this->survey->name,
            ]),
            'company' => $this->whenLoaded('company', fn (): ?array => $this->company === null ? null : [
                'id' => $this->company->id,
                'name' => $this->company->name,
            ]),
            'contact' => $this->whenLoaded('contact', fn (): ?array => $this->contact === null ? null : [
                'id' => $this->contact->id,
                'name' => $this->contact->name,
            ]),
            'invitation' => $this->whenLoaded('invitation', fn (): ?array => $this->invitation === null ? null : [
                'id' => $this->invitation->id,
                'reference' => $this->invitation->reference,
            ]),
            'answers' => $this->whenLoaded(
                'answers',
                fn () => SurveyAnswerResource::collection($this->answersInOrder()),
            ),
            'created_at' => $this->created_at,
        ];
    }
}
