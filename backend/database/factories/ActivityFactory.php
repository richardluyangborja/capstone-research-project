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

    /**
     * A follow-up task (module 04, spec 12): something owed by a time, not a record of
     * something done — so it carries a `due_at` and has not happened yet.
     */
    public function task(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => ActivityType::Task,
            'status' => ActivityStatus::Open,
            'due_at' => now()->addDay(),
            'occurred_at' => null,
        ]);
    }
}
