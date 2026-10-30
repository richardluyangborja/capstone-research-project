<?php

namespace Database\Factories;

use App\Enums\BillingUnit;
use App\Models\Contract;
use App\Models\ContractRateCard;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ContractRateCard>
 */
class ContractRateCardFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $unit = fake()->randomElement(BillingUnit::cases());
        $billRate = fake()->randomFloat(2, 80, 500);

        return [
            'contract_id' => Contract::factory(),
            'site_id' => null,
            'role_title' => fake()->randomElement([
                'Warehouse associate',
                'Forklift operator',
                'Team lead',
                'Housekeeper',
                'Account manager',
            ]),
            'headcount' => fake()->numberBetween(1, 12),
            'billing_unit' => $unit,
            'bill_rate' => $billRate,
            'pay_rate' => $unit === BillingUnit::Hourly
                ? fake()->randomFloat(2, 60, 400)
                : fake()->randomFloat(2, 15000, 70000),
            'units_per_period' => $unit === BillingUnit::Hourly ? 176 : 1,
            'notes' => null,
            'position' => 1,
        ];
    }

    public function forContract(Contract $contract): static
    {
        return $this->state(fn (): array => ['contract_id' => $contract->id]);
    }

    public function hourly(): static
    {
        return $this->state(fn (): array => [
            'billing_unit' => BillingUnit::Hourly,
            'units_per_period' => 176,
            'bill_rate' => 120,
            'pay_rate' => 90,
        ]);
    }

    public function monthly(): static
    {
        return $this->state(fn (): array => [
            'billing_unit' => BillingUnit::Monthly,
            'units_per_period' => 1,
            'bill_rate' => 45000,
            'pay_rate' => 32000,
        ]);
    }

    public function placement(): static
    {
        return $this->state(fn (): array => [
            'billing_unit' => BillingUnit::Placement,
            'units_per_period' => 1,
            'bill_rate' => 15000,
            'pay_rate' => 4000,
        ]);
    }

    /** A line with no internal cost — the contract's margin stays null. */
    public function withoutPayRate(): static
    {
        return $this->state(fn (): array => ['pay_rate' => null]);
    }
}
