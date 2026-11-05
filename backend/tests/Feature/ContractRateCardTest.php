<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Contract;
use App\Models\ContractRateCard;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ContractRateCardTest extends TestCase
{
    use RefreshDatabase;

    public function test_rate_cards_are_read_only_and_compute_sensitive_values_by_role(): void
    {
        $rep = User::factory()->salesrep()->create();
        $contract = Contract::factory()->forCompany(Company::factory()->ownedBy($rep)->create())->create();
        ContractRateCard::factory()->for($contract)->create([
            'bill_rate' => 120,
            'pay_rate' => 80,
            'headcount' => 2,
            'units_per_period' => 176,
        ]);

        $this->actingAs($rep, 'api')
            ->getJson("/api/v1/contracts/{$contract->id}/rate-cards")
            ->assertOk()
            ->assertJsonPath('meta.read_only', true)
            ->assertJsonPath('data.0.bill_rate', '120.00')
            ->assertJsonPath('data.0.pay_rate', null)
            ->assertJsonPath('data.0.can_view_margin', false);

        $this->actingAs($rep, 'api')
            ->putJson("/api/v1/contracts/{$contract->id}/rate-cards", ['rate_cards' => []])
            ->assertStatus(405);

        $this->assertDatabaseCount('contract_rate_cards', 1);
    }
}
