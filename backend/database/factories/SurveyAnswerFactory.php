<?php

namespace Database\Factories;

use App\Models\SurveyAnswer;
use App\Models\SurveyQuestion;
use App\Models\SurveyResponse;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SurveyAnswer>
 */
class SurveyAnswerFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'response_id' => SurveyResponse::factory(),
            'question_id' => SurveyQuestion::factory(),
            'value_number' => null,
            'value_text' => null,
            'value_options' => null,
        ];
    }
}
