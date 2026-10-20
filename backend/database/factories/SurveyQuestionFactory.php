<?php

namespace Database\Factories;

use App\Enums\SurveyQuestionType;
use App\Models\Survey;
use App\Models\SurveyQuestion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SurveyQuestion>
 */
class SurveyQuestionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'survey_id' => Survey::factory(),
            'type' => SurveyQuestionType::Rating,
            'prompt' => fake()->sentence(6),
            'help' => null,
            'required' => true,
            'position' => 1,
            'options' => null,
        ];
    }

    public function nps(): static
    {
        return $this->state(fn (array $attributes): array => ['type' => SurveyQuestionType::Nps]);
    }

    /**
     * @param  array<int, string>  $options
     */
    public function choice(array $options, bool $multiple = false): static
    {
        return $this->state(fn (array $attributes): array => [
            'type' => $multiple ? SurveyQuestionType::MultiChoice : SurveyQuestionType::SingleChoice,
            'options' => $options,
        ]);
    }

    public function text(): static
    {
        return $this->state(fn (array $attributes): array => ['type' => SurveyQuestionType::Text]);
    }
}
