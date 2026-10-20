<?php

namespace App\Http\Resources;

use App\Models\Survey;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A survey in the library (module 05 slice S1, spec 13 §Payloads).
 *
 * The abilities travel with the row so the book offers only the actions the caller actually has:
 * every role reads a survey, only manager+ changes one. The questions are included when the
 * caller loaded them (the detail and the form) and counted on the book rows.
 *
 * @mixin Survey
 */
class SurveyResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $sent = $this->invitations_count !== null
            ? $this->invitations_count > 0
            : $this->hasBeenSent();

        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'is_active' => (bool) $this->is_active,
            'questions' => SurveyQuestionResource::collection($this->whenLoaded('questions')),
            'questions_count' => $this->whenCounted('questions'),
            'invitations_count' => $this->whenCounted('invitations'),
            // The variant S1 left open: once the survey has gone out, the questions are what
            // clients answered and only `duplicate` may change them (spec 13 §Business rules 1).
            'questions_editable' => ! $sent,
            'created_by' => $this->created_by,
            'author' => $this->whenLoaded('author', fn (): ?array => $this->author === null ? null : [
                'id' => $this->author->id,
                'name' => $this->author->name,
            ]),
            'can_update' => (bool) $request->user()?->can('update', $this->resource),
            'can_delete' => (bool) $request->user()?->can('delete', $this->resource),
            'can_restore' => (bool) $request->user()?->can('restore', $this->resource),
            'can_duplicate' => (bool) $request->user()?->can('create', Survey::class),
            'deleted_at' => $this->deleted_at?->toIso8601String(),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
