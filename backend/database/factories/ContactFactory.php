<?php

namespace Database\Factories;

use App\Enums\ContactRoleType;
use App\Enums\ContactStatus;
use App\Models\Company;
use App\Models\Contact;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Contact>
 */
class ContactFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'name' => fake()->name(),
            'position' => fake()->jobTitle(),
            'role_type' => fake()->randomElement(ContactRoleType::cases()),
            'email' => fake()->unique()->safeEmail(),
            'phone' => '02'.fake()->numerify('#######'),
            'mobile' => '09'.fake()->numerify('#########'),
            'is_primary' => false,
            'status' => ContactStatus::Active,
        ];
    }

    public function forCompany(Company $company): static
    {
        return $this->state(fn (array $attributes): array => ['company_id' => $company->id]);
    }

    public function primary(): static
    {
        return $this->state(fn (array $attributes): array => ['is_primary' => true]);
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes): array => ['status' => ContactStatus::Inactive]);
    }

    public function role(ContactRoleType $role): static
    {
        return $this->state(fn (array $attributes): array => ['role_type' => $role]);
    }
}
