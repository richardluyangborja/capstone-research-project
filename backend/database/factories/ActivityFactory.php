<?php

namespace Database\Factories;

use App\Enums\ActivityStatus;
use App\Enums\ActivityType;
use App\Models\Activity;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Activity>
 */
class ActivityFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'type' => ActivityType::Note,
            'subject' => fake()->sentence(4),
            'body' => fake()->paragraph(),
            'status' => ActivityStatus::Open,
            'occurred_at' => now(),
            'owner_id' => User::factory(),
        ];
    }

    public function system(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => ActivityType::System,
            'status' => ActivityStatus::Done,
        ]);
    }
}
