<?php

namespace Database\Factories;

use App\Enums\LeadSource;
use App\Enums\LostReason;
use App\Enums\OpportunityStage;
use App\Models\Company;
use App\Models\Opportunity;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Opportunity>
 */
class OpportunityFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $stage = fake()->randomElement(OpportunityStage::cases());

        return [
            'company_id' => Company::factory(),
            'name' => fake()->catchPhrase().' staffing engagement',
            'stage' => $stage,
            'amount' => fake()->numberBetween(50_000, 3_000_000),
            'currency' => 'PHP',
            'probability' => $stage->defaultProbability(),
            'expected_close_date' => fake()->dateTimeBetween('now', '+4 months'),
            'source' => fake()->randomElement(LeadSource::cases()),
            'owner_id' => User::factory(),
            'lost_reason' => $stage === OpportunityStage::Lost
                ? fake()->randomElement(LostReason::cases())
                : null,
        ];
    }

    public function forCompany(Company $company): static
    {
        return $this->state(fn (array $attributes): array => ['company_id' => $company->id]);
    }

    public function ownedBy(User $owner): static
    {
        return $this->state(fn (array $attributes): array => ['owner_id' => $owner->id]);
    }

    public function stage(OpportunityStage $stage): static
    {
        return $this->state(fn (array $attributes): array => [
            'stage' => $stage,
            'probability' => $stage->defaultProbability(),
            'lost_reason' => $stage === OpportunityStage::Lost ? LostReason::Price : null,
        ]);
    }
}
