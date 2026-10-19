<?php

namespace Database\Factories;

use App\Enums\CommunicationChannel;
use App\Models\CommunicationTemplate;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CommunicationTemplate>
 */
class CommunicationTemplateFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->words(3, true),
            'channel' => CommunicationChannel::Email,
            'subject' => fake()->sentence(4),
            'body' => fake()->paragraph(),
            'is_active' => true,
            'created_by' => User::factory(),
        ];
    }

    public function channel(CommunicationChannel $channel): static
    {
        return $this->state(fn (array $attributes): array => ['channel' => $channel]);
    }

    /**
     * Kept, but no longer offered in the composer's picker.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes): array => ['is_active' => false]);
    }

    public function authoredBy(User $user): static
    {
        return $this->state(fn (array $attributes): array => ['created_by' => $user->id]);
    }
}
