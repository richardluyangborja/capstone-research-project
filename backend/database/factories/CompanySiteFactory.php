<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\CompanySite;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CompanySite>
 */
class CompanySiteFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'label' => fake()->randomElement(['Head office', 'Warehouse', 'Branch office', 'Site']),
            'street_address' => fake()->streetAddress(),
            'barangay' => 'Barangay '.fake()->numberBetween(1, 200),
            'city_municipality' => fake()->randomElement([
                'Quezon City',
                'Makati',
                'Pasig',
                'Taguig',
                'Cebu City',
                'Davao City',
            ]),
            'province' => fake()->randomElement(['Metro Manila', 'Cebu', 'Davao del Sur']),
            'region' => fake()->randomElement(['NCR', 'Region VII', 'Region XI']),
            'postal_code' => fake()->numerify('####'),
            'is_primary' => false,
        ];
    }

    public function primary(): static
    {
        return $this->state(fn (array $attributes): array => ['is_primary' => true]);
    }
}
