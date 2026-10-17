<?php

namespace Database\Factories;

use App\Enums\ContractStatus;
use App\Models\Company;
use App\Models\Contract;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * @extends Factory<Contract>
 */
class ContractFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $start = Carbon::now()->subMonths(2);

        return [
            'company_id' => Company::factory(),
            'title' => fake()->randomElement([
                'Forklift operator pooling agreement',
                'Housekeeping crew service agreement',
                'Contact centre staffing agreement',
                'Warehouse staffing agreement',
            ]),
            'status' => ContractStatus::Draft,
            'start_date' => $start->toDateString(),
            'end_date' => $start->copy()->addMonths(12)->toDateString(),
            'value' => fake()->randomElement([450000, 620000, 980000, 1250000, 2400000]),
            'currency' => 'PHP',
            'terms' => 'Net 30, 30-day notice on volume changes.',
            'owner_id' => User::factory(),
        ];
    }

    public function forCompany(Company $company): static
    {
        return $this->state(fn (array $attributes): array => [
            'company_id' => $company->id,
            'owner_id' => $company->owner_id,
        ]);
    }

    public function ownedBy(User $user): static
    {
        return $this->state(fn (array $attributes): array => ['owner_id' => $user->id]);
    }

    public function status(ContractStatus $status): static
    {
        return $this->state(fn (array $attributes): array => ['status' => $status]);
    }

    /**
     * A signed contract — the state that activates a client.
     */
    public function signed(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => ContractStatus::Signed,
            'signed_at' => Carbon::now()->subMonths(2),
        ]);
    }

    public function expired(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => ContractStatus::Expired,
            'signed_at' => Carbon::now()->subYears(2),
            'start_date' => Carbon::now()->subYears(2)->toDateString(),
            'end_date' => Carbon::now()->subMonths(2)->toDateString(),
        ]);
    }

    /**
     * A signed contract expiring inside the renewal window.
     */
    public function expiringSoon(): static
    {
        return $this->signed()->state(fn (array $attributes): array => [
            'end_date' => Carbon::now()->addDays(10)->toDateString(),
        ]);
    }

    public function renewalOf(Contract $contract): static
    {
        return $this->state(fn (array $attributes): array => [
            'renewal_of_contract_id' => $contract->id,
            'company_id' => $contract->company_id,
        ]);
    }
}
