<?php

namespace Tests\Feature;

use App\Enums\ActivityType;
use App\Models\Activity;
use App\Models\Company;
use App\Models\CompanySite;
use App\Models\Contract;
use App\Models\ContractRateCard;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ContractRateCardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    /**
     * @return array{manager: User, rep: User, company: Company, contract: Contract}
     */
    private function context(): array
    {
        $team = Team::factory()->create();
        $manager = User::factory()->manager()->create(['team_id' => $team->id]);
        $rep = User::factory()->salesrep()->create(['team_id' => $team->id]);
        $company = Company::factory()->ownedBy($rep)->create();
        $contract = Contract::factory()->forCompany($company)->create([
            'start_date' => '2026-01-01',
            'end_date' => '2027-01-01',
        ]);

        return compact('manager', 'rep', 'company', 'contract');
    }

    /**
     * @param  array<int, array<string, mixed>>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return [
            'rate_cards' => [
                [
                    'role_title' => 'Warehouse associate',
                    'headcount' => 6,
                    'billing_unit' => 'hourly',
                    'bill_rate' => 110,
                    'pay_rate' => 85,
                ],
                [
                    'role_title' => 'Site supervisor',
                    'headcount' => 1,
                    'billing_unit' => 'monthly',
                    'bill_rate' => 30000,
                    'pay_rate' => 22000,
                ],
                [
                    'role_title' => 'Recruitment placement',
                    'headcount' => 2,
                    'billing_unit' => 'placement',
                    'bill_rate' => 15000,
                    'pay_rate' => 4000,
                ],
                ...$overrides,
            ],
        ];
    }

    public function test_a_manager_replaces_the_set_and_the_totals_are_computed_on_read(): void
    {
        ['manager' => $manager, 'contract' => $contract] = $this->context();

        $response = $this->actingAs($manager, 'api')
            ->putJson("/api/v1/contracts/{$contract->id}/rate-cards", $this->payload())
            ->assertOk()
            ->assertJsonCount(3, 'data.rate_cards')
            ->assertJsonPath('data.rate_cards.0.position', 1)
            ->assertJsonPath('data.rate_cards.1.position', 2)
            ->assertJsonPath('data.rate_cards.2.position', 3)
            ->assertJsonPath('data.rate_cards.0.billing_unit', 'hourly')
            ->assertJsonPath('data.rate_cards.0.units_per_period', '176.00')
            ->assertJsonPath('data.rate_cards.1.units_per_period', '1.00')
            ->assertJsonPath('data.rate_cards.2.units_per_period', '1.00')
            ->assertJsonPath('data.rate_cards.0.line_client_monthly', '116160.00')
            ->assertJsonPath('data.rate_cards.0.line_internal_monthly', '89760.00')
            ->assertJsonPath('data.rate_cards.2.line_client_monthly', '0.00')
            ->assertJsonPath('data.rate_cards.2.line_placement_fee', '30000.00')
            ->assertJsonPath('data.commercial.client_monthly_value', '146160.00')
            ->assertJsonPath('data.commercial.internal_monthly_cost', '111760.00')
            ->assertJsonPath('data.commercial.gross_margin', '34400.00')
            ->assertJsonPath('data.commercial.gross_margin_percent', 23.54)
            ->assertJsonPath('data.commercial.estimated_total_value', '1783920.00')
            ->assertJsonPath('data.commercial.can_view_margin', true)
            ->assertJsonPath('data.can_manage_rate_cards', true);

        $this->assertSame(3, $contract->rateCards()->count());

        // The detail read and the dedicated list return the same set.
        $this->actingAs($manager, 'api')
            ->getJson("/api/v1/contracts/{$contract->id}")
            ->assertOk()
            ->assertJsonCount(3, 'data.rate_cards')
            ->assertJsonPath('data.commercial.client_monthly_value', '146160.00');

        $this->actingAs($manager, 'api')
            ->getJson("/api/v1/contracts/{$contract->id}/rate-cards")
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('data.0.pay_rate', '85.00')
            ->assertJsonPath('data.0.can_view_margin', true);
    }

    public function test_a_salesrep_sees_bill_rates_but_not_pay_rates_cost_or_margin(): void
    {
        ['rep' => $rep, 'company' => $company, 'contract' => $contract] = $this->context();

        ContractRateCard::factory()->hourly()->create([
            'contract_id' => $contract->id,
            'headcount' => 6,
            'bill_rate' => 120,
            'pay_rate' => 90,
        ]);

        $this->actingAs($rep, 'api')
            ->getJson("/api/v1/companies/{$company->id}/contracts")
            ->assertOk()
            ->assertJsonPath('data.0.commercial.client_monthly_value', '126720.00')
            ->assertJsonPath('data.0.commercial.internal_monthly_cost', null)
            ->assertJsonPath('data.0.commercial.gross_margin', null)
            ->assertJsonPath('data.0.commercial.gross_margin_percent', null)
            ->assertJsonPath('data.0.commercial.can_view_margin', false)
            ->assertJsonPath('data.0.rate_cards.0.bill_rate', '120.00')
            ->assertJsonPath('data.0.rate_cards.0.pay_rate', null)
            ->assertJsonPath('data.0.rate_cards.0.line_internal_monthly', null)
            ->assertJsonPath('data.0.can_manage_rate_cards', false);

        $this->actingAs($rep, 'api')
            ->getJson("/api/v1/contracts/{$contract->id}/rate-cards")
            ->assertOk()
            ->assertJsonPath('data.0.pay_rate', null)
            ->assertJsonPath('data.0.can_view_margin', false);

        // Writing is the manager's job; the stored pay rate is untouched.
        $this->actingAs($rep, 'api')
            ->putJson("/api/v1/contracts/{$contract->id}/rate-cards", $this->payload())
            ->assertForbidden();

        $this->assertSame('90.00', ContractRateCard::firstOrFail()->pay_rate);
    }

    public function test_a_manager_from_another_team_cannot_write_rate_cards(): void
    {
        ['contract' => $contract] = $this->context();
        $outsider = User::factory()->manager()->create();

        $this->actingAs($outsider, 'api')
            ->putJson("/api/v1/contracts/{$contract->id}/rate-cards", $this->payload())
            ->assertForbidden();

        $this->assertSame(0, $contract->rateCards()->count());
    }

    public function test_replacing_the_set_soft_deletes_the_old_rows_and_records_the_change(): void
    {
        ['manager' => $manager, 'company' => $company, 'contract' => $contract] = $this->context();

        ContractRateCard::factory()->count(2)->create(['contract_id' => $contract->id]);

        $this->actingAs($manager, 'api')
            ->putJson("/api/v1/contracts/{$contract->id}/rate-cards", [
                'rate_cards' => [[
                    'role_title' => 'Team lead',
                    'headcount' => 2,
                    'billing_unit' => 'monthly',
                    'bill_rate' => 45000,
                    'pay_rate' => 32000,
                ]],
            ])
            ->assertOk()
            ->assertJsonCount(1, 'data.rate_cards')
            ->assertJsonPath('data.rate_cards.0.position', 1);

        $this->assertSame(1, $contract->rateCards()->count());
        $this->assertSame(3, ContractRateCard::withTrashed()->count());

        $activity = Activity::query()
            ->where('subject_type', Contract::class)
            ->where('subject_id', $contract->id)
            ->where('subject', 'Contract '.$contract->reference.' rate cards updated')
            ->first();

        $this->assertNotNull($activity);
        $this->assertSame(ActivityType::System, $activity->type);
        $this->assertSame($manager->id, $activity->owner_id);
        $this->assertSame('1 line replaced · client monthly PHP 90,000.00.', $activity->body);

        // The account timeline carries the same note.
        $this->assertDatabaseHas('activities', [
            'subject_type' => Company::class,
            'subject_id' => $company->id,
            'subject' => 'Contract '.$contract->reference.' rate cards updated',
            'body' => '1 line replaced · client monthly PHP 90,000.00.',
        ]);
    }

    public function test_missing_pay_rates_leave_cost_and_margin_null(): void
    {
        ['manager' => $manager, 'contract' => $contract] = $this->context();

        $this->actingAs($manager, 'api')
            ->putJson("/api/v1/contracts/{$contract->id}/rate-cards", [
                'rate_cards' => [
                    [
                        'role_title' => 'Warehouse associate',
                        'headcount' => 6,
                        'billing_unit' => 'hourly',
                        'bill_rate' => 120,
                    ],
                    [
                        'role_title' => 'Recruitment placement',
                        'headcount' => 2,
                        'billing_unit' => 'placement',
                        'bill_rate' => 15000,
                    ],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('data.commercial.client_monthly_value', '126720.00')
            ->assertJsonPath('data.commercial.internal_monthly_cost', null)
            ->assertJsonPath('data.commercial.gross_margin', null)
            ->assertJsonPath('data.commercial.gross_margin_percent', null)
            ->assertJsonPath('data.commercial.estimated_total_value', '1550640.00');
    }

    public function test_a_contract_with_no_rate_cards_reads_as_zero_with_a_null_margin(): void
    {
        ['manager' => $manager, 'contract' => $contract] = $this->context();

        $this->actingAs($manager, 'api')
            ->getJson("/api/v1/contracts/{$contract->id}")
            ->assertOk()
            ->assertJsonCount(0, 'data.rate_cards')
            ->assertJsonPath('data.commercial.client_monthly_value', '0.00')
            ->assertJsonPath('data.commercial.internal_monthly_cost', null)
            ->assertJsonPath('data.commercial.gross_margin', null)
            ->assertJsonPath('data.commercial.gross_margin_percent', null)
            ->assertJsonPath('data.commercial.estimated_total_value', '0.00')
            ->assertJsonPath('data.commercial.can_view_margin', true);
    }

    public function test_an_empty_set_is_allowed_and_clears_the_contract(): void
    {
        ['manager' => $manager, 'contract' => $contract] = $this->context();

        ContractRateCard::factory()->hourly()->create(['contract_id' => $contract->id]);

        $this->actingAs($manager, 'api')
            ->putJson("/api/v1/contracts/{$contract->id}/rate-cards", ['rate_cards' => []])
            ->assertOk()
            ->assertJsonCount(0, 'data.rate_cards')
            ->assertJsonPath('data.commercial.client_monthly_value', '0.00')
            ->assertJsonPath('data.commercial.gross_margin', null);

        $this->assertSame(0, $contract->rateCards()->count());
    }

    public function test_rate_card_sites_must_belong_to_the_contract_company(): void
    {
        ['manager' => $manager, 'company' => $company, 'contract' => $contract] = $this->context();

        $ownSite = CompanySite::factory()->create(['company_id' => $company->id]);
        $otherCompany = Company::factory()->create();
        $otherSite = CompanySite::factory()->create(['company_id' => $otherCompany->id]);

        $this->actingAs($manager, 'api')
            ->putJson("/api/v1/contracts/{$contract->id}/rate-cards", [
                'rate_cards' => [[
                    'role_title' => 'Warehouse associate',
                    'headcount' => 1,
                    'billing_unit' => 'hourly',
                    'bill_rate' => 110,
                    'site_id' => $otherSite->id,
                ]],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('rate_cards.0.site_id');

        $this->actingAs($manager, 'api')
            ->putJson("/api/v1/contracts/{$contract->id}/rate-cards", [
                'rate_cards' => [[
                    'role_title' => 'Warehouse associate',
                    'headcount' => 1,
                    'billing_unit' => 'hourly',
                    'bill_rate' => 110,
                    'site_id' => $ownSite->id,
                ]],
            ])
            ->assertOk()
            ->assertJsonPath('data.rate_cards.0.site_id', $ownSite->id)
            ->assertJsonPath('data.rate_cards.0.site.label', $ownSite->label);
    }

    public function test_the_rate_card_payload_is_validated(): void
    {
        ['manager' => $manager, 'contract' => $contract] = $this->context();

        $this->actingAs($manager, 'api')
            ->putJson("/api/v1/contracts/{$contract->id}/rate-cards", [
                'rate_cards' => [[
                    'role_title' => '',
                    'headcount' => 0,
                    'billing_unit' => 'daily',
                    'bill_rate' => -1,
                    'pay_rate' => -5,
                    'units_per_period' => -1,
                ]],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors([
                'rate_cards.0.role_title',
                'rate_cards.0.headcount',
                'rate_cards.0.billing_unit',
                'rate_cards.0.bill_rate',
                'rate_cards.0.pay_rate',
                'rate_cards.0.units_per_period',
            ]);

        $this->actingAs($manager, 'api')
            ->putJson("/api/v1/contracts/{$contract->id}/rate-cards", [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('rate_cards');
    }

    public function test_a_renewal_clones_the_previous_contracts_rate_cards(): void
    {
        ['rep' => $rep, 'company' => $company, 'contract' => $contract] = $this->context();

        ContractRateCard::factory()->hourly()->create([
            'contract_id' => $contract->id,
            'role_title' => 'Warehouse associate',
            'headcount' => 6,
            'bill_rate' => 110,
            'pay_rate' => 85,
        ]);
        ContractRateCard::factory()->placement()->create([
            'contract_id' => $contract->id,
            'position' => 2,
        ]);

        $this->actingAs($rep, 'api')
            ->post("/api/v1/companies/{$company->id}/contracts", [
                'file' => UploadedFile::fake()->create('renewal.pdf', 120, 'application/pdf'),
                'title' => 'Forklift operator pooling agreement (renewal)',
                'renewal_of_contract_id' => $contract->id,
                'start_date' => '2027-01-01',
                'end_date' => '2028-01-01',
                'value' => 2400000,
            ], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('data.renewal_of_contract_id', $contract->id)
            ->assertJsonCount(2, 'data.rate_cards')
            ->assertJsonPath('data.rate_cards.0.role_title', 'Warehouse associate')
            ->assertJsonPath('data.rate_cards.0.line_client_monthly', '116160.00')
            // The salesrep still cannot see the cloned internal cost.
            ->assertJsonPath('data.rate_cards.0.pay_rate', null)
            ->assertJsonPath('data.commercial.client_monthly_value', '116160.00');

        $renewal = Contract::query()
            ->where('renewal_of_contract_id', $contract->id)
            ->firstOrFail();

        $this->assertSame(2, $renewal->rateCards()->count());
        $this->assertSame('85.00', $renewal->rateCards()->firstOrFail()->pay_rate);
        // The old agreement is untouched.
        $this->assertSame(2, $contract->rateCards()->count());
    }
}
