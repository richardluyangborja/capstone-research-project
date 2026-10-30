<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Contract;
use App\Models\ContractRateCard;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ContractBookTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    public function test_a_salesrep_only_sees_contracts_from_their_own_accounts(): void
    {
        $rep1 = User::factory()->salesrep()->create();
        $rep2 = User::factory()->salesrep()->create();

        $company1 = Company::factory()->ownedBy($rep1)->create();
        $company2 = Company::factory()->ownedBy($rep2)->create();

        $contract1 = Contract::factory()->forCompany($company1)->signed()->create(['title' => 'Rep 1 Contract']);
        $contract2 = Contract::factory()->forCompany($company2)->signed()->create(['title' => 'Rep 2 Contract']);

        $response = $this->actingAs($rep1, 'api')
            ->getJson('/api/v1/contracts')
            ->assertOk();

        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertContains($contract1->id, $ids);
        $this->assertNotContains($contract2->id, $ids);
        $this->assertSame(1, $response->json('summary.all'));
    }

    public function test_a_manager_sees_contracts_from_their_teams_accounts(): void
    {
        $team = Team::factory()->create();
        $manager = User::factory()->manager()->create(['team_id' => $team->id]);
        $team->update(['manager_id' => $manager->id]);

        $rep = User::factory()->salesrep()->create(['team_id' => $team->id]);
        $otherRep = User::factory()->salesrep()->create(); // different team / unassigned

        $teamCompany = Company::factory()->ownedBy($rep)->create();
        $otherCompany = Company::factory()->ownedBy($otherRep)->create();

        $teamContract = Contract::factory()->forCompany($teamCompany)->signed()->create();
        $otherContract = Contract::factory()->forCompany($otherCompany)->signed()->create();

        $response = $this->actingAs($manager, 'api')
            ->getJson('/api/v1/contracts')
            ->assertOk();

        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertContains($teamContract->id, $ids);
        $this->assertNotContains($otherContract->id, $ids);
    }

    public function test_an_admin_sees_all_contracts(): void
    {
        $admin = User::factory()->admin()->create();
        $rep1 = User::factory()->salesrep()->create();
        $rep2 = User::factory()->salesrep()->create();

        $c1 = Contract::factory()->forCompany(Company::factory()->ownedBy($rep1)->create())->signed()->create();
        $c2 = Contract::factory()->forCompany(Company::factory()->ownedBy($rep2)->create())->draft()->create();

        $response = $this->actingAs($admin, 'api')
            ->getJson('/api/v1/contracts')
            ->assertOk();

        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertContains($c1->id, $ids);
        $this->assertContains($c2->id, $ids);
        $this->assertSame(2, $response->json('summary.all'));
    }

    public function test_contracts_can_be_filtered_by_status(): void
    {
        $admin = User::factory()->admin()->create();
        $company = Company::factory()->create();

        $signed = Contract::factory()->forCompany($company)->signed()->create();
        $draft = Contract::factory()->forCompany($company)->draft()->create();
        $sent = Contract::factory()->forCompany($company)->sent()->create();

        $response = $this->actingAs($admin, 'api')
            ->getJson('/api/v1/contracts?filter[status]=signed')
            ->assertOk();

        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertContains($signed->id, $ids);
        $this->assertNotContains($draft->id, $ids);
        $this->assertNotContains($sent->id, $ids);

        // Multiple comma-separated statuses
        $multi = $this->actingAs($admin, 'api')
            ->getJson('/api/v1/contracts?filter[status]=draft,sent')
            ->assertOk();

        $multiIds = collect($multi->json('data'))->pluck('id')->all();
        $this->assertContains($draft->id, $multiIds);
        $this->assertContains($sent->id, $multiIds);
        $this->assertNotContains($signed->id, $multiIds);
    }

    public function test_contracts_can_be_filtered_by_expiring_soon(): void
    {
        $admin = User::factory()->admin()->create();
        $company = Company::factory()->create();

        // Expiring in 10 days
        $expiring = Contract::factory()->forCompany($company)->signed()->expiringSoon()->create();

        // Expiring in 120 days (not expiring soon)
        $farFuture = Contract::factory()->forCompany($company)->signed()->create([
            'end_date' => now()->addDays(120)->toDateString(),
        ]);

        $response = $this->actingAs($admin, 'api')
            ->getJson('/api/v1/contracts?filter[expiring]=1')
            ->assertOk();

        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertContains($expiring->id, $ids);
        $this->assertNotContains($farFuture->id, $ids);
    }

    public function test_contracts_can_be_filtered_by_renewal_flag(): void
    {
        $admin = User::factory()->admin()->create();
        $company = Company::factory()->create();

        $original = Contract::factory()->forCompany($company)->signed()->create(['title' => 'Original Agreement']);
        $renewal = Contract::factory()->forCompany($company)->renewalOf($original)->signed()->create(['title' => 'Renewal Agreement']);

        // Filter only renewals
        $response = $this->actingAs($admin, 'api')
            ->getJson('/api/v1/contracts?filter[renewal]=1')
            ->assertOk();

        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertContains($renewal->id, $ids);
        $this->assertNotContains($original->id, $ids);

        // Filter original (non-renewals)
        $origResponse = $this->actingAs($admin, 'api')
            ->getJson('/api/v1/contracts?filter[renewal]=0')
            ->assertOk();

        $origIds = collect($origResponse->json('data'))->pluck('id')->all();
        $this->assertContains($original->id, $origIds);
        $this->assertNotContains($renewal->id, $origIds);
    }

    public function test_contracts_search_by_reference_title_and_company_name(): void
    {
        $admin = User::factory()->admin()->create();
        $company = Company::factory()->create(['name' => 'Acme Logistics Corp']);

        $c1 = Contract::factory()->forCompany($company)->create([
            'reference' => 'CON-2026-9999',
            'title' => 'Forklift Staffing Master Agreement',
        ]);

        $otherCompany = Company::factory()->create(['name' => 'Global Retail']);
        $c2 = Contract::factory()->forCompany($otherCompany)->create([
            'reference' => 'CON-2026-0001',
            'title' => 'Store Associate Pooling',
        ]);

        // Search by contract title
        $resTitle = $this->actingAs($admin, 'api')
            ->getJson('/api/v1/contracts?search=Forklift')
            ->assertOk();
        $this->assertContains($c1->id, collect($resTitle->json('data'))->pluck('id')->all());
        $this->assertNotContains($c2->id, collect($resTitle->json('data'))->pluck('id')->all());

        // Search by company name
        $resComp = $this->actingAs($admin, 'api')
            ->getJson('/api/v1/contracts?search=Acme')
            ->assertOk();
        $this->assertContains($c1->id, collect($resComp->json('data'))->pluck('id')->all());
        $this->assertNotContains($c2->id, collect($resComp->json('data'))->pluck('id')->all());

        // Search by reference
        $resRef = $this->actingAs($admin, 'api')
            ->getJson('/api/v1/contracts?search=CON-2026-9999')
            ->assertOk();
        $this->assertContains($c1->id, collect($resRef->json('data'))->pluck('id')->all());
        $this->assertNotContains($c2->id, collect($resRef->json('data'))->pluck('id')->all());
    }

    public function test_contract_detail_includes_rate_cards_and_renewal_chain(): void
    {
        $manager = User::factory()->manager()->create();
        $company = Company::factory()->ownedBy($manager)->create();

        $original = Contract::factory()->forCompany($company)->signed()->create(['title' => 'Original Contract']);
        $renewal = Contract::factory()->forCompany($company)->renewalOf($original)->signed()->create(['title' => 'Renewed Contract']);

        ContractRateCard::factory()->forContract($renewal)->hourly()->create([
            'role_title' => 'Warehouse Associate',
            'headcount' => 5,
            'bill_rate' => 180,
            'pay_rate' => 120,
            'units_per_period' => 176,
        ]);

        $response = $this->actingAs($manager, 'api')
            ->getJson("/api/v1/contracts/{$renewal->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $renewal->id)
            ->assertJsonPath('data.renewal_of.id', $original->id)
            ->assertJsonPath('data.renewal_of.reference', $original->reference)
            ->assertJsonPath('data.renewal_of.title', 'Original Contract')
            ->assertJsonPath('data.commercial.can_view_margin', true)
            ->assertJsonCount(1, 'data.rate_cards');

        // Check original contract detail lists its renewal child
        $origResponse = $this->actingAs($manager, 'api')
            ->getJson("/api/v1/contracts/{$original->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $original->id)
            ->assertJsonCount(1, 'data.renewals')
            ->assertJsonPath('data.renewals.0.id', $renewal->id)
            ->assertJsonPath('data.renewals.0.reference', $renewal->reference);
    }

    public function test_contracts_can_be_filtered_by_company_and_owner(): void
    {
        $admin = User::factory()->admin()->create();
        $owner = User::factory()->salesrep()->create();

        $company = Company::factory()->create();
        $otherCompany = Company::factory()->create();

        $matching = Contract::factory()->forCompany($company)->ownedBy($owner)->create();
        $otherOwner = Contract::factory()->forCompany($company)->create();
        $otherCompanyContract = Contract::factory()->forCompany($otherCompany)->ownedBy($owner)->create();

        $byCompany = $this->actingAs($admin, 'api')
            ->getJson("/api/v1/contracts?filter[company]={$company->id}")
            ->assertOk();

        $companyIds = collect($byCompany->json('data'))->pluck('id')->all();
        $this->assertContains($matching->id, $companyIds);
        $this->assertContains($otherOwner->id, $companyIds);
        $this->assertNotContains($otherCompanyContract->id, $companyIds);

        $byOwner = $this->actingAs($admin, 'api')
            ->getJson("/api/v1/contracts?filter[owner]={$owner->id}")
            ->assertOk();

        $ownerIds = collect($byOwner->json('data'))->pluck('id')->all();
        $this->assertContains($matching->id, $ownerIds);
        $this->assertNotContains($otherOwner->id, $ownerIds);

        // A contract outside the caller's scope stays outside even when filtered by owner.
        $rep = User::factory()->salesrep()->create();
        $otherRep = User::factory()->salesrep()->create();
        $repCompany = Company::factory()->ownedBy($otherRep)->create();
        $repContract = Contract::factory()->forCompany($repCompany)->ownedBy($owner)->create();

        $scoped = $this->actingAs($rep, 'api')
            ->getJson("/api/v1/contracts?filter[owner]={$owner->id}")
            ->assertOk();

        $this->assertNotContains($repContract->id, collect($scoped->json('data'))->pluck('id')->all());
    }

    public function test_a_contract_write_response_keeps_the_renewal_chain(): void
    {
        $admin = User::factory()->admin()->create();
        $company = Company::factory()->create();

        $original = Contract::factory()->forCompany($company)->signed()->create();
        Contract::factory()->forCompany($company)->renewalOf($original)->signed()->create();

        $this->actingAs($admin, 'api')
            ->patchJson("/api/v1/contracts/{$original->id}", ['title' => $original->title])
            ->assertOk()
            ->assertJsonPath('data.id', $original->id)
            ->assertJsonPath('data.renewal_of', null)
            ->assertJsonCount(1, 'data.renewals');
    }

    public function test_salesrep_cannot_see_sensitive_margins_in_contract_detail(): void
    {
        $rep = User::factory()->salesrep()->create();
        $company = Company::factory()->ownedBy($rep)->create();

        $contract = Contract::factory()->forCompany($company)->signed()->create();

        ContractRateCard::factory()->forContract($contract)->hourly()->create([
            'bill_rate' => 180,
            'pay_rate' => 120,
            'headcount' => 2,
        ]);

        $response = $this->actingAs($rep, 'api')
            ->getJson("/api/v1/contracts/{$contract->id}")
            ->assertOk()
            ->assertJsonPath('data.commercial.can_view_margin', false)
            ->assertJsonPath('data.commercial.internal_monthly_cost', null)
            ->assertJsonPath('data.commercial.gross_margin', null)
            ->assertJsonPath('data.commercial.gross_margin_percent', null)
            ->assertJsonPath('data.rate_cards.0.pay_rate', null)
            ->assertJsonPath('data.rate_cards.0.can_view_margin', false);
    }
}
