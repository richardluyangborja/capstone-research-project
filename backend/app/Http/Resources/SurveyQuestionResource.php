<?php

namespace App\Http\Resources;

use App\Models\SurveyQuestion;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One question, as the builder and the client's page both read it (module 05 slice S1, spec 13).
 *
 * The type travels as `{value,label}` like every other enum on the wire, and `options` is always
 * an array — a `rating` question answers `[]` rather than `null`, so the builder never branches on
 * a null.
 *
 * @mixin SurveyQuestion
 */
class SurveyQuestionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => [
                'value' => $this->type->value,
                'label' => $this->type->label(),
            ],
            'prompt' => $this->prompt,
            'help' => $this->help,
            'required' => (bool) $this->required,
            'position' => $this->position,
            'options' => array_values($this->options ?? []),
        ];
    }
}
