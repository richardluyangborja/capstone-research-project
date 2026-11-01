<?php

namespace Tests\Feature;

use App\Enums\CompanyStatus;
use App\Models\Company;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BillingBookTest extends TestCase
{
    use RefreshDatabase;

    public function test_salesreps_are_forbidden_and_managers_see_the_teams_ar_book(): void
    {
        $team = Team::factory()->create();
        $rep = User::factory()->salesrep()->create(['team_id' => $team->id]);
        $manager = User::factory()->manager()->create(['team_id' => $team->id]);
        $other = User::factory()->salesrep()->create();

        $overdue = $this->account($rep, 'CO-2026-0002', CompanyStatus::Onboarding, 'BPO Solutions PH');
        $current = $this->account($rep, 'CO-2026-0003', CompanyStatus::Active, 'Harbor Logistics');
        $this->account($rep, 'CO-2026-0001', CompanyStatus::Prospect, 'Sunrise Foods Inc');
        $this->account($other, 'CO-2026-0004', CompanyStatus::Churned, 'Greenfield Hotels');

        $this->actingAs($rep, 'api')
            ->getJson('/api/v1/billing')
            ->assertForbidden();

        $response = $this->actingAs($manager, 'api')
            ->getJson('/api/v1/billing')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.company.id', $overdue->id)
            ->assertJsonPath('data.0.account_status', 'overdue')
            ->assertJsonPath('data.0.overdue_invoices', 1)
            ->assertJsonPath('data.0.invoice_count', 3)
            ->assertJsonPath('data.0.open_invoices', 1)
            ->assertJsonPath('data.1.company.id', $current->id)
            ->assertJsonPath('data.1.account_status', 'current')
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('meta.read_only', true)
            ->assertJsonPath('meta.source', 'Finance (mocked)')
            ->assertJsonPath('summary.accounts', 2)
            ->assertJsonPath('summary.overdue_accounts', 1)
            ->assertJsonPath('summary.open_invoices', 2);

        $this->assertSame(
            $this->sumRows($response->json('data'), 'outstanding'),
            $response->json('summary.outstanding'),
        );
        $this->assertSame(
            $this->sumRows($response->json('data'), 'overdue'),
            $response->json('summary.overdue'),
        );
        $this->assertNotEmpty($response->json('meta.as_of'));

        // A churned/other-team account stays out of this manager's book but is visible to an admin.
        $this->actingAs(User::factory()->admin()->create(), 'api')
            ->getJson('/api/v1/billing')
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('data.2.company.name', 'Greenfield Hotels')
            ->assertJsonPath('data.2.account_status', 'closed')
            ->assertJsonPath('data.2.outstanding', '0.00')
            ->assertJsonPath('data.2.invoice_count', 3);
    }

    public function test_status_overdue_aging_company_owner_and_search_filters_narrow_the_table(): void
    {
        $team = Team::factory()->create();
        $rep = User::factory()->salesrep()->create(['team_id' => $team->id]);
        $other = User::factory()->salesrep()->create(['team_id' => $team->id]);
        $manager = User::factory()->manager()->create(['team_id' => $team->id]);
        $overdue = $this->account($rep, 'CO-2026-0002', CompanyStatus::Onboarding, 'BPO Solutions PH');
        $current = $this->account($other, 'CO-2026-0003', CompanyStatus::Active, 'Harbor Logistics');

        $this->actingAs($manager, 'api')
            ->getJson('/api/v1/billing?filter[status]=overdue')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.company.id', $overdue->id)
            ->assertJsonPath('summary.accounts', 2);

        $this->actingAs($manager, 'api')
            ->getJson('/api/v1/billing?filter[overdue]=true')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.company.id', $overdue->id);

        $this->actingAs($manager, 'api')
            ->getJson('/api/v1/billing?filter[overdue]=false')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.company.id', $current->id);

        $this->actingAs($manager, 'api')
            ->getJson('/api/v1/billing?filter[aging]=1_30')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.company.id', $overdue->id);

        $this->actingAs($manager, 'api')
            ->getJson('/api/v1/billing?filter[aging]=current')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.company.id', $current->id);

        $this->actingAs($manager, 'api')
            ->getJson('/api/v1/billing?filter[company]='.$current->id)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.company.id', $current->id);

        $this->actingAs($manager, 'api')
            ->getJson('/api/v1/billing?filter[owner]=me')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->actingAs($manager, 'api')
            ->getJson('/api/v1/billing?filter[owner]=team')
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $this->actingAs($manager, 'api')
            ->getJson('/api/v1/billing?filter[owner]='.$rep->id)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.company.id', $overdue->id);

        $this->actingAs($manager, 'api')
            ->getJson('/api/v1/billing?search=BPO')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.company.id', $overdue->id);

        $this->actingAs($manager, 'api')
            ->getJson('/api/v1/billing?search=Harbor')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.company.id', $current->id);
    }

    public function test_the_book_is_paginated_and_has_no_write_route(): void
    {
        $team = Team::factory()->create();
        $rep = User::factory()->salesrep()->create(['team_id' => $team->id]);
        $manager = User::factory()->manager()->create(['team_id' => $team->id]);
        $this->account($rep, 'CO-2026-0002', CompanyStatus::Onboarding, 'BPO Solutions PH');
        $this->account($rep, 'CO-2026-0003', CompanyStatus::Active, 'Harbor Logistics');
        $this->account($rep, 'CO-2026-0005', CompanyStatus::Onboarding, 'Greenfield Hotels Cebu');

        $this->actingAs($manager, 'api')
            ->getJson('/api/v1/billing?per_page=2')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.per_page', 2)
            ->assertJsonPath('meta.total', 3)
            ->assertJsonPath('meta.last_page', 2)
            ->assertJsonPath('summary.accounts', 3);

        $this->actingAs($manager, 'api')
            ->postJson('/api/v1/billing', [])
            ->assertStatus(405);
    }

    private function account(
        User $owner,
        string $reference,
        CompanyStatus $status,
        string $name,
    ): Company {
        return Company::factory()->ownedBy($owner)->create([
            'reference' => $reference,
            'status' => $status,
            'name' => $name,
        ]);
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function sumRows(array $rows, string $key): string
    {
        return number_format(
            array_sum(array_map(static fn (array $row): float => (float) $row[$key], $rows)),
            2,
            '.',
            '',
        );
    }
}
