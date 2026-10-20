<?php

namespace App\Http\Requests;

use App\Enums\SurveyQuestionType;
use App\Models\Survey;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Authoring a survey (module 05 slice S1, spec 13). Manager+ (spec 13 §Permissions).
 *
 * The question set arrives **with** the survey — one aggregate, one payload — and is validated as
 * a set: a choice question needs its options, a scored question must not carry them, the promoter
 * question may appear once, and a survey cannot be activated empty. The order is the array order;
 * `position` is the server's, never the client's.
 */
class StoreSurveyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Survey::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120', $this->liveNameUnique()],
            'description' => ['nullable', 'string', 'max:2000'],
            'is_active' => ['sometimes', 'boolean'],
            'questions' => ['sometimes', 'array', 'max:30'],
            'questions.*.type' => ['required', Rule::enum(SurveyQuestionType::class)],
            'questions.*.prompt' => ['required', 'string', 'max:255'],
            'questions.*.help' => ['nullable', 'string', 'max:500'],
            'questions.*.required' => ['sometimes', 'boolean'],
            'questions.*.options' => ['sometimes', 'array', 'min:2', 'max:10'],
            'questions.*.options.*' => ['required', 'string', 'max:120', 'distinct'],
        ];
    }

    protected function prepareForValidation(): void
    {
        // The name is a picker label, not free text — leading/trailing space never helps.
        if ($this->has('name')) {
            $this->merge(['name' => trim((string) $this->input('name'))]);
        }

        if (is_array($this->input('questions'))) {
            $this->merge([
                'questions' => array_map($this->cleanQuestion(...), $this->input('questions')),
            ]);
        }
    }

    /**
     * @return array<int, Closure(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $questions = $this->input('questions', []);

                if (is_array($questions)) {
                    $this->checkQuestions($validator, $questions);
                }

                $this->checkActivation($validator, is_array($questions) ? count($questions) : 0);
            },
        ];
    }

    /**
     * The name is unique among **live** surveys, case-insensitively — a validation rule rather
     * than a database constraint because a soft delete frees the name (spec 13 §Business rules 11).
     * `restore` re-checks the same thing.
     */
    protected function liveNameUnique(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            $current = $this->route('survey');
            $exceptId = $current instanceof Survey ? (string) $current->getKey() : null;

            if (Survey::nameTaken((string) $value, $exceptId)) {
                $fail('Another survey already uses that name.');
            }
        };
    }

    /**
     * Whether the survey ends up active — the request's value on PATCH, the stored one otherwise.
     */
    protected function resultingActiveState(): bool
    {
        if ($this->has('is_active')) {
            return filter_var($this->input('is_active'), FILTER_VALIDATE_BOOL);
        }

        $survey = $this->route('survey');

        return $survey instanceof Survey && (bool) $survey->is_active;
    }

    /**
     * How many questions the survey will have once this request lands.
     */
    protected function resultingQuestionCount(int $submitted): int
    {
        if (array_key_exists('questions', $this->all())) {
            return $submitted;
        }

        $survey = $this->route('survey');

        return $survey instanceof Survey ? $survey->questions()->count() : 0;
    }

    /**
     * A survey with no question cannot be answered, so it cannot be active (spec 13 §Business
     * rules 3) — that is what keeps a draft and a sendable survey distinct.
     */
    private function checkActivation(Validator $validator, int $submitted): void
    {
        if (! $this->resultingActiveState()) {
            return;
        }

        if ($this->resultingQuestionCount($submitted) === 0) {
            $validator->errors()->add('questions', 'A survey needs at least one question before it can be active.');
        }
    }

    /**
     * @param  array<int|string, mixed>  $questions
     */
    private function checkQuestions(Validator $validator, array $questions): void
    {
        $promoters = 0;

        foreach ($questions as $index => $question) {
            if (! is_array($question)) {
                continue;
            }

            $type = SurveyQuestionType::tryFrom((string) ($question['type'] ?? ''));

            if ($type === null) {
                continue;
            }

            $options = $question['options'] ?? null;
            $hasOptions = is_array($options) && $options !== [];

            if ($type->hasOptions() && ! $hasOptions) {
                $validator->errors()->add(
                    "questions.{$index}.options",
                    "A {$type->label()} question needs 2 to 10 options.",
                );
            }

            if (! $type->hasOptions() && $hasOptions) {
                $validator->errors()->add(
                    "questions.{$index}.options",
                    "A {$type->label()} question takes no options.",
                );
            }

            if ($type === SurveyQuestionType::Nps) {
                $promoters++;
            }
        }

        if ($promoters > 1) {
            $validator->errors()->add('questions', 'A survey can ask the promoter question only once.');
        }
    }

    /**
     * Trim what the survey will display; `null` for an empty help line so "no help" is one value.
     */
    private function cleanQuestion(mixed $question): mixed
    {
        if (! is_array($question)) {
            return $question;
        }

        if (isset($question['prompt'])) {
            $question['prompt'] = trim((string) $question['prompt']);
        }

        if (array_key_exists('help', $question)) {
            $help = trim((string) $question['help']);
            $question['help'] = $help === '' ? null : $help;
        }

        if (isset($question['options']) && is_array($question['options'])) {
            $question['options'] = array_values(array_map(
                fn (mixed $option): string => trim((string) $option),
                $question['options'],
            ));
        }

        return $question;
    }
}
