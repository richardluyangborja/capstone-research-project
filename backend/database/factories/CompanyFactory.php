<?php

namespace Database\Factories;

use App\Enums\CompanySize;
use App\Enums\CompanyStatus;
use App\Models\Company;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Company>
 */
class CompanyFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->company(),
            'industry' => fake()->randomElement([
                'Manpower',
                'Construction',
                'Hospitality',
                'Logistics',
                'Food Manufacturing',
                'Business Process Outsourcing',
            ]),
            'size' => fake()->randomElement(CompanySize::cases()),
            'website' => fake()->url(),
            'status' => CompanyStatus::Prospect,
            'owner_id' => User::factory(),
        ];
    }

    /**
     * A company that has already become a client (spec 00 — status past `prospect`).
     */
    public function client(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => CompanyStatus::Active,
            'client_since' => now()->subMonths(6)->toDateString(),
        ]);
    }

    public function ownedBy(User $user): static
    {
        return $this->state(fn (array $attributes): array => [
            'owner_id' => $user->id,
        ]);
    }
}
