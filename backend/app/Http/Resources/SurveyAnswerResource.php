<?php

namespace App\Http\Resources;

use App\Models\SurveyAnswer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One answer, with the question it belongs to (module 05 slice S2, spec 13 §Payloads).
 *
 * The question block is what lets a reader (and the response detail in slice S3) render the
 * value by type without a second lookup: a number against its scale, options against what was
 * offered, text as written.
 *
 * @mixin SurveyAnswer
 */
class SurveyAnswerResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'question_id' => $this->question_id,
            'question' => $this->whenLoaded('question', fn (): ?array => $this->question === null ? null : [
                'id' => $this->question->id,
                'type' => $this->question->type?->value,
                'prompt' => $this->question->prompt,
                'options' => $this->question->options,
            ]),
            'value_number' => $this->value_number,
            'value_text' => $this->value_text,
            'value_options' => $this->value_options,
        ];
    }
}
