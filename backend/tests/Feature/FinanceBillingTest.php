<?php

namespace Tests\Feature;

use App\Enums\CompanyStatus;
use App\Models\Company;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FinanceBillingTest extends TestCase
{
    use RefreshDatabase;

    public function test_managers_see_the_expanded_invoice_feed_and_salesreps_only_get_the_redacted_flag(): void
    {
        $team = Team::factory()->create();
        $rep = User::factory()->salesrep()->create(['team_id' => $team->id]);
        $manager = User::factory()->manager()->create(['team_id' => $team->id]);
        // This reference's deterministic seed is one of the overdue accounts.
        $company = Company::factory()->ownedBy($rep)->create([
            'reference' => 'CO-2026-0002',
            'status' => CompanyStatus::Active,
        ]);

        $this->actingAs($rep, 'api')
            ->getJson("/api/v1/companies/{$company->id}")
            ->assertOk()
            ->assertJsonPath('data.can_view_billing', false)
            ->assertJsonPath('data.billing_risk.has_overdue', true)
            ->assertJsonPath(
                'data.billing_risk.message',
                'Finance reports an overdue balance on this account. Follow up with Finance.',
            );

        $risk = $this->actingAs($rep, 'api')
            ->getJson("/api/v1/companies/{$company->id}")
            ->json('data.billing_risk');

        $this->assertArrayNotHasKey('outstanding', $risk);
        $this->assertArrayNotHasKey('overdue', $risk);
        $this->assertArrayNotHasKey('invoices', $risk);

        $this->actingAs($rep, 'api')
            ->getJson("/api/v1/companies/{$company->id}/billing")
            ->assertForbidden();

        $response = $this->actingAs($manager, 'api')
            ->getJson("/api/v1/companies/{$company->id}/billing")
            ->assertOk()
            ->assertJsonPath('meta.source', 'Finance (mocked)')
            ->assertJsonPath('meta.read_only', true)
            ->assertJsonPath('data.account_status', 'overdue')
            ->assertJsonCount(3, 'data.invoices')
            ->assertJsonPath('data.invoices.0.status', 'overdue')
            ->assertJsonPath('data.invoices.1.status', 'paid')
            ->assertJsonPath('data.invoices.2.status', 'paid')
            ->assertJsonStructure([
                'data' => [
                    'outstanding',
                    'overdue',
                    'overdue_invoices',
                    'aging' => ['current', '1_30', '31_60', '60_plus'],
                    'invoices' => [
                        ['reference', 'period_start', 'period_end', 'issued_at', 'due_at', 'amount', 'balance', 'status'],
                    ],
                ],
            ]);

        $this->assertSame(
            $response->json('data.invoices.0.balance'),
            $response->json('data.outstanding'),
        );
        $this->assertSame(
            $response->json('data.invoices.0.amount'),
            $response->json('data.overdue'),
        );

        // Managers do not need the redacted flag; they have the full snapshot.
        $this->actingAs($manager, 'api')
            ->getJson("/api/v1/companies/{$company->id}")
            ->assertOk()
            ->assertJsonMissingPath('data.billing_risk');

        // Finance answers deterministically for the same account.
        $first = $response->json('data');
        $second = $this->actingAs($manager, 'api')
            ->getJson("/api/v1/companies/{$company->id}/billing")
            ->json('data');
        $this->assertSame($first, $second);
    }

    public function test_a_current_account_has_no_redacted_flag_and_a_churned_account_closes_its_ar(): void
    {
        $rep = User::factory()->salesrep()->create();
        $company = Company::factory()->ownedBy($rep)->create([
            'reference' => 'CO-2026-0001',
            'status' => CompanyStatus::Active,
        ]);

        $this->actingAs($rep, 'api')
            ->getJson("/api/v1/companies/{$company->id}")
            ->assertOk()
            ->assertJsonPath('data.billing_risk.has_overdue', false)
            ->assertJsonPath('data.billing_risk.message', null);

        $company->update(['status' => CompanyStatus::Churned]);

        $this->actingAs($rep, 'api')
            ->getJson("/api/v1/companies/{$company->id}")
            ->assertOk()
            ->assertJsonPath('data.billing_risk.has_overdue', false);

        $manager = User::factory()->manager()->create();
        $company->update(['owner_id' => $manager->id]);

        $this->actingAs($manager, 'api')
            ->getJson("/api/v1/companies/{$company->id}/billing")
            ->assertOk()
            ->assertJsonPath('data.account_status', 'closed')
            ->assertJsonPath('data.outstanding', '0.00')
            ->assertJsonPath('data.overdue', '0.00')
            ->assertJsonPath('data.invoices.0.status', 'paid')
            ->assertJsonPath('data.invoices.1.status', 'paid')
            ->assertJsonPath('data.invoices.2.status', 'paid');
    }

    public function test_a_prospect_has_no_live_ar_feed(): void
    {
        $manager = User::factory()->manager()->create();
        $company = Company::factory()->ownedBy($manager)->create([
            'reference' => 'CO-2026-0001',
            'status' => CompanyStatus::Prospect,
        ]);

        $this->actingAs($manager, 'api')
            ->getJson("/api/v1/companies/{$company->id}/billing")
            ->assertOk()
            ->assertJsonPath('data.account_status', 'current')
            ->assertJsonPath('data.outstanding', '0.00')
            ->assertJsonPath('data.overdue', '0.00')
            ->assertJsonPath('data.overdue_invoices', 0)
            ->assertJsonPath('data.last_invoice_date', null)
            ->assertJsonPath('data.next_due_date', null)
            ->assertJsonCount(0, 'data.invoices');
    }
}
