<?php

namespace Tests\Feature;

use App\Enums\CompanyStatus;
use App\Enums\ContractStatus;
use App\Enums\HandoffStatus;
use App\Models\Company;
use App\Models\Contract;
use App\Models\ContractRateCard;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DeploymentBookTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_salesrep_sees_only_deployments_on_their_own_accounts(): void
    {
        $rep = User::factory()->salesrep()->create();
        $other = User::factory()->salesrep()->create();
        $own = $this->activeAccount($rep);
        $theirs = $this->activeAccount($other);

        $response = $this->actingAs($rep, 'api')
            ->getJson('/api/v1/deployments')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.company.id', $own->company_id)
            ->assertJsonPath('data.0.status', 'active')
            ->assertJsonPath('data.0.fill_rate', 100)
            ->assertJsonPath('data.0.timesheet.status', 'current')
            ->assertJsonPath('data.0.operations_owner', 'Deployment operations (mocked)')
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('meta.read_only', true)
            ->assertJsonPath('meta.source', 'Deployment / Timekeeping (mocked, read-only)')
            ->assertJsonPath('summary.total', 1)
            ->assertJsonPath('summary.active', 1)
            ->assertJsonPath('summary.fill_rate', 100);

        $this->assertNotEmpty($response->json('meta.as_of'));

        $this->actingAs($other, 'api')
            ->getJson('/api/v1/deployments')
            ->assertOk()
            ->assertJsonPath('data.0.company.id', $theirs->company_id);
    }

    public function test_a_manager_sees_the_teams_deployments(): void
    {
        $team = Team::factory()->create();
        $rep = User::factory()->salesrep()->create(['team_id' => $team->id]);
        $manager = User::factory()->manager()->create(['team_id' => $team->id]);
        $this->activeAccount($rep);

        $this->actingAs($manager, 'api')
            ->getJson('/api/v1/deployments')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.owner.name', $rep->name);
    }

    public function test_status_company_contract_and_search_filters_narrow_the_table_but_not_the_summary(): void
    {
        $rep = User::factory()->salesrep()->create();
        $active = $this->activeAccount($rep, ['Warehouse associate', 'Forklift operator']);
        $pending = $this->pendingAccount($rep);

        $this->actingAs($rep, 'api')
            ->getJson('/api/v1/deployments?filter[status]=active')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('summary.total', 3)
            ->assertJsonPath('summary.active', 2)
            ->assertJsonPath('summary.pending', 1);

        $this->actingAs($rep, 'api')
            ->getJson('/api/v1/deployments?filter[status]=pending')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.company.id', $pending->company_id)
            ->assertJsonPath('data.0.timesheet.status', 'missing');

        $this->actingAs($rep, 'api')
            ->getJson('/api/v1/deployments?filter[company]='.$pending->company_id)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.company.id', $pending->company_id);

        $this->actingAs($rep, 'api')
            ->getJson('/api/v1/deployments?filter[contract]='.$active->id)
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.company.id', $active->company_id);

        $this->actingAs($rep, 'api')
            ->getJson('/api/v1/deployments?search=Forklift')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.role', 'Forklift operator');
    }

    public function test_the_owner_filter_follows_the_account_owner(): void
    {
        $team = Team::factory()->create();
        $rep = User::factory()->salesrep()->create(['team_id' => $team->id]);
        $other = User::factory()->salesrep()->create(['team_id' => $team->id]);
        $manager = User::factory()->manager()->create(['team_id' => $team->id]);
        $mine = $this->activeAccount($rep);
        $theirs = $this->activeAccount($other);

        $this->actingAs($manager, 'api')
            ->getJson('/api/v1/deployments?filter[owner]=me')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->actingAs($manager, 'api')
            ->getJson('/api/v1/deployments?filter[owner]=team')
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $this->actingAs($manager, 'api')
            ->getJson('/api/v1/deployments?filter[owner]='.$rep->id)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.company.id', $mine->company_id)
            ->assertJsonPath('summary.total', 2);

        $this->actingAs($manager, 'api')
            ->getJson('/api/v1/deployments?filter[owner]='.$other->id)
            ->assertOk()
            ->assertJsonPath('data.0.company.id', $theirs->company_id);
    }

    public function test_the_book_is_paginated_and_has_no_write_route(): void
    {
        $rep = User::factory()->salesrep()->create();
        $this->activeAccount($rep);
        $this->activeAccount($rep);
        $this->activeAccount($rep);

        $this->actingAs($rep, 'api')
            ->getJson('/api/v1/deployments?per_page=2')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.per_page', 2)
            ->assertJsonPath('meta.total', 3)
            ->assertJsonPath('meta.last_page', 2)
            ->assertJsonPath('summary.total', 3);

        $this->actingAs($rep, 'api')
            ->postJson('/api/v1/deployments', [])
            ->assertStatus(405);
    }

    /**
     * @param  array<int, string>  $roles
     */
    private function activeAccount(User $owner, array $roles = ['Warehouse associate']): Contract
    {
        $company = Company::factory()->ownedBy($owner)->create([
            'status' => CompanyStatus::Active,
            'handoff_status' => HandoffStatus::Sent,
        ]);

        return $this->contractWithRoles($company, $roles, ContractStatus::Signed);
    }

    private function pendingAccount(User $owner): Contract
    {
        $company = Company::factory()->ownedBy($owner)->create([
            'status' => CompanyStatus::Onboarding,
            'handoff_status' => HandoffStatus::Failed,
        ]);

        return $this->contractWithRoles($company, ['Customer service associate'], ContractStatus::Signed);
    }

    /**
     * @param  array<int, string>  $roles
     */
    private function contractWithRoles(Company $company, array $roles, ContractStatus $status): Contract
    {
        $contract = Contract::factory()->forCompany($company)->create([
            'status' => $status,
            'start_date' => now()->subMonth()->toDateString(),
            'end_date' => now()->addMonths(11)->toDateString(),
            'signed_at' => $status === ContractStatus::Signed ? now()->subMonth() : null,
        ]);

        foreach ($roles as $position => $role) {
            ContractRateCard::factory()->forContract($contract)->hourly()->create([
                'role_title' => $role,
                'headcount' => 4,
                'position' => $position + 1,
            ]);
        }

        return $contract;
    }
}
