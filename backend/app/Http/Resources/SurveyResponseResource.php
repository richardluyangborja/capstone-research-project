<?php

namespace App\Http\Resources;

use App\Models\SurveyResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One submitted answer set (module 05 slice S2, spec 13 §Payloads).
 *
 * Slice S2 only needs the receipt the client's page shows and the account tab's row; slice S3
 * builds the response book and detail on the same shape.
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
            'needs_attention' => (bool) $this->needs_attention,
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
            'answers' => SurveyAnswerResource::collection($this->whenLoaded('answers')),
            'created_at' => $this->created_at,
        ];
    }
}
