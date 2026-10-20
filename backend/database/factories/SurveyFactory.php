<?php

namespace Database\Factories;

use App\Models\Survey;
use App\Models\SurveyQuestion;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Survey>
 */
class SurveyFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->words(3, true),
            'description' => fake()->sentence(12),
            // A survey is a draft until somebody activates it (spec 13 §Business rules 3).
            'is_active' => false,
            'created_by' => User::factory(),
        ];
    }

    /**
     * Ready to be sent: active, with one rating question.
     */
    public function active(): static
    {
        return $this->state(fn (array $attributes): array => ['is_active' => true]);
    }

    /**
     * An active survey carrying the given questions, in order. `position` is stamped from the
     * array order so a test never has to write it.
     *
     * @param  array<int, array<string, mixed>>  $questions
     */
    public function withQuestions(array $questions): static
    {
        return $this->afterCreating(function (Survey $survey) use ($questions): void {
            foreach (array_values($questions) as $index => $question) {
                SurveyQuestion::factory()
                    ->for($survey)
                    ->create($question + ['position' => $index + 1]);
            }
        });
    }

    public function authoredBy(User $user): static
    {
        return $this->state(fn (array $attributes): array => ['created_by' => $user->id]);
    }
}
