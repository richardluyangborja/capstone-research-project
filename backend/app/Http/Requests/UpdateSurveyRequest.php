<?php

namespace App\Http\Requests;

use App\Enums\SurveyQuestionType;
use App\Models\Survey;
use Illuminate\Validation\Rule;

/**
 * Editing a survey (module 05 slice S1, spec 13). Every field is optional — a survey can be
 * renamed, re-described, activated or deactivated, and while it has never been sent its question
 * set can be replaced wholesale (the array order is the asking order).
 *
 * Slice S2 adds the freeze: once an invitation exists the question set is refused with a 422.
 */
class UpdateSurveyRequest extends StoreSurveyRequest
{
    public function authorize(): bool
    {
        $survey = $this->route('survey');

        return $survey instanceof Survey
            && ($this->user()?->can('update', $survey) ?? false);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:120', $this->liveNameUnique()],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'is_active' => ['sometimes', 'boolean'],
            'questions' => ['sometimes', 'array', 'max:30'],
            'questions.*.type' => ['required', Rule::enum(SurveyQuestionType::class)],
            'questions.*.prompt' => ['required', 'string', 'max:255'],
            'questions.*.help' => ['sometimes', 'nullable', 'string', 'max:500'],
            'questions.*.required' => ['sometimes', 'boolean'],
            'questions.*.options' => ['sometimes', 'array', 'min:2', 'max:10'],
            'questions.*.options.*' => ['required', 'string', 'max:120', 'distinct'],
        ];
    }
}
