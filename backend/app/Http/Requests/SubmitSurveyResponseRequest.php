<?php

namespace App\Http\Requests;

use App\Enums\SurveyQuestionType;
use App\Models\SurveyInvitation;
use App\Models\SurveyQuestion;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * The client's answers (module 05 slice S2, spec 13 §Business rules 2 and 7).
 *
 * No account, so nothing to authorize: the token in the route is the credential. When it does
 * not resolve — or resolves to an invitation that can no longer be answered — the rules are
 * empty on purpose, leaving the controller to answer 404/410 rather than a misleading 422.
 */
class SubmitSurveyResponseRequest extends FormRequest
{
    private ?SurveyInvitation $invitation = null;

    private bool $resolved = false;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * The invitation the token names, with what the form needs loaded.
     */
    public function invitation(): ?SurveyInvitation
    {
        if (! $this->resolved) {
            $this->invitation = SurveyInvitation::query()
                ->with(['survey.questions', 'company', 'contact'])
                ->where('token', (string) $this->route('token'))
                ->first();

            $this->resolved = true;
        }

        return $this->invitation;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $invitation = $this->invitation();

        if ($invitation === null || ! $invitation->isAnswerable()) {
            return [];
        }

        return [
            'answers' => ['required', 'array'],
            'answers.*.question_id' => ['required', 'string'],
            'answers.*.value_number' => ['nullable', 'integer'],
            'answers.*.value_text' => ['nullable', 'string', 'max:5000'],
            'answers.*.value_options' => ['nullable', 'array'],
            'answers.*.value_options.*' => ['string', 'max:255'],
        ];
    }

    /**
     * Every question the survey asks, checked by its own type: a required answer is present,
     * a rating sits in 1–5, an NPS in 0–10, and a choice only names offered options.
     *
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $invitation = $this->invitation();

                if ($invitation === null || ! $invitation->isAnswerable()) {
                    return;
                }

                $questions = $invitation->survey->questions;
                $answers = $this->input('answers');
                $answers = is_array($answers) ? $answers : [];

                /** @var array<string, array<string, mixed>> $byQuestion */
                $byQuestion = [];

                foreach ($answers as $answer) {
                    if (! is_array($answer)) {
                        continue;
                    }

                    $questionId = $answer['question_id'] ?? null;

                    if (! is_string($questionId) || ! $questions->contains('id', $questionId)) {
                        $validator->errors()->add('answers', 'One of the answers does not belong to this survey.');

                        continue;
                    }

                    if (array_key_exists($questionId, $byQuestion)) {
                        $validator->errors()->add('answers', 'A question was answered twice.');

                        continue;
                    }

                    $byQuestion[$questionId] = $answer;
                }

                foreach ($questions as $question) {
                    $this->checkAnswer($validator, $question, $byQuestion[$question->id] ?? null);
                }
            },
        ];
    }

    /**
     * The answers to store, in the order the survey asks them, with only the value column the
     * question's type uses filled in.
     *
     * @return array<int, array{question_id: string, value_number: int|null, value_text: string|null, value_options: array<int, string>|null}>
     */
    public function answerRows(): array
    {
        $invitation = $this->invitation();

        if ($invitation === null) {
            return [];
        }

        $submitted = collect($this->input('answers', []))
            ->filter(fn (mixed $answer): bool => is_array($answer) && isset($answer['question_id']))
            ->keyBy('question_id');

        $rows = [];

        foreach ($invitation->survey->questions as $question) {
            $answer = $submitted->get($question->id);

            if (! is_array($answer)) {
                continue;
            }

            $rows[] = [
                'question_id' => $question->id,
                'value_number' => $question->type->numericBounds() === null
                    ? null
                    : (int) $answer['value_number'],
                'value_text' => $question->type === SurveyQuestionType::Text
                    ? (trim((string) ($answer['value_text'] ?? '')) ?: null)
                    : null,
                'value_options' => $question->type->hasOptions()
                    ? array_values(array_map('strval', (array) ($answer['value_options'] ?? [])))
                    : null,
            ];
        }

        return $rows;
    }

    /**
     * @param  array<string, mixed>|null  $answer
     */
    private function checkAnswer(Validator $validator, SurveyQuestion $question, ?array $answer): void
    {
        $key = 'answers.'.$question->id;
        $missing = $answer === null;

        if ($missing && $question->required) {
            $validator->errors()->add($key, sprintf('Please answer: %s', $question->prompt));

            return;
        }

        if ($missing) {
            return;
        }

        $bounds = $question->type->numericBounds();

        if ($bounds !== null) {
            $raw = $answer['value_number'] ?? null;

            if ($raw === null || $raw === '') {
                if ($question->required) {
                    $validator->errors()->add($key, sprintf('Please answer: %s', $question->prompt));
                }

                return;
            }

            $number = is_numeric($raw) ? (int) $raw : null;

            if ($number === null || $number < $bounds['min'] || $number > $bounds['max']) {
                $validator->errors()->add(
                    $key,
                    sprintf('Choose a whole number from %d to %d.', $bounds['min'], $bounds['max']),
                );
            }

            return;
        }

        if ($question->type->hasOptions()) {
            $selected = $answer['value_options'] ?? [];
            $selected = is_array($selected) ? array_values(array_unique(array_map('strval', $selected))) : [];
            $offered = array_map('strval', (array) $question->options);

            if ($selected === []) {
                if ($question->required) {
                    $validator->errors()->add($key, sprintf('Please answer: %s', $question->prompt));
                }

                return;
            }

            if ($question->type === SurveyQuestionType::SingleChoice && count($selected) !== 1) {
                $validator->errors()->add($key, 'Choose one option.');

                return;
            }

            if (array_diff($selected, $offered) !== []) {
                $validator->errors()->add($key, 'Choose one of the offered options.');
            }

            return;
        }

        if ($question->required && trim((string) ($answer['value_text'] ?? '')) === '') {
            $validator->errors()->add($key, sprintf('Please answer: %s', $question->prompt));
        }
    }
}
