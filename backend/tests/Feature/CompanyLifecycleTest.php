<?php

namespace Tests\Feature;

use App\Enums\CompanyStatus;
use App\Events\CompanyActivated;
use App\Events\CompanyChurned;
use App\Models\Company;
use App\Models\Contract;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class CompanyLifecycleTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_manager_can_move_a_client_along_the_lifecycle(): void
    {
        $manager = User::factory()->manager()->create();
        $company = Company::factory()->ownedBy($manager)->create(['status' => CompanyStatus::Prospect]);

        $this->actingAs($manager, 'api')
            ->patchJson("/api/v1/companies/{$company->id}/lifecycle", ['status' => CompanyStatus::Onboarding->value])
            ->assertOk()
            ->assertJsonPath('data.status', 'onboarding')
            ->assertJsonPath('data.is_client', true)
            ->assertJsonPath('data.client_since', now()->toDateString());

        $this->assertDatabaseHas('activities', [
            'subject_type' => Company::class,
            'subject_id' => $company->id,
            'subject' => 'Client status changed prospect → onboarding',
        ]);

        $this->actingAs($manager, 'api')
            ->patchJson("/api/v1/companies/{$company->id}/lifecycle", ['status' => CompanyStatus::Active->value])
            ->assertOk()
            ->assertJsonPath('data.status', 'active');
    }

    public function test_the_lifecycle_payload_exposes_only_legal_moves(): void
    {
        $manager = User::factory()->manager()->create();
        $company = Company::factory()->ownedBy($manager)->create(['status' => CompanyStatus::Active]);

        $this->actingAs($manager, 'api')
            ->getJson("/api/v1/companies/{$company->id}")
            ->assertOk()
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.allowed_status_transitions', ['at_risk', 'dormant', 'churned'])
            ->assertJsonPath('data.can_manage_lifecycle', true);
    }

    public function test_an_illegal_lifecycle_move_is_rejected(): void
    {
        $manager = User::factory()->manager()->create();
        $company = Company::factory()->ownedBy($manager)->create(['status' => CompanyStatus::Prospect]);

        // A prospect never jumps straight to at-risk (spec 09 §1).
        $this->actingAs($manager, 'api')
            ->patchJson("/api/v1/companies/{$company->id}/lifecycle", ['status' => CompanyStatus::AtRisk->value])
            ->assertStatus(422)
            ->assertJsonValidationErrors('status');

        $this->assertSame(CompanyStatus::Prospect, $company->fresh()->status);
    }

    public function test_a_salesrep_cannot_change_the_lifecycle(): void
    {
        $rep = User::factory()->salesrep()->create();
        $company = Company::factory()->ownedBy($rep)->create(['status' => CompanyStatus::Active]);

        $this->actingAs($rep, 'api')
            ->patchJson("/api/v1/companies/{$company->id}/lifecycle", ['status' => CompanyStatus::AtRisk->value])
            ->assertStatus(403);

        $this->actingAs($rep, 'api')
            ->postJson("/api/v1/companies/{$company->id}/reactivate")
            ->assertStatus(403);

        $this->assertSame(CompanyStatus::Active, $company->fresh()->status);
    }

    public function test_a_manager_can_only_reach_their_own_team(): void
    {
        $team = Team::factory()->create();
        $otherTeam = Team::factory()->create();
        $manager = User::factory()->manager()->create(['team_id' => $team->id]);
        $outsider = User::factory()->salesrep()->create(['team_id' => $otherTeam->id]);
        $company = Company::factory()->ownedBy($outsider)->create(['status' => CompanyStatus::Active]);

        $this->actingAs($manager, 'api')
            ->patchJson("/api/v1/companies/{$company->id}/lifecycle", ['status' => CompanyStatus::AtRisk->value])
            ->assertStatus(403);
    }

    public function test_churning_requires_a_reason_and_records_it(): void
    {
        $manager = User::factory()->manager()->create();
        $company = Company::factory()->ownedBy($manager)->create([
            'status' => CompanyStatus::Active,
            'client_since' => now()->subYear()->toDateString(),
        ]);

        $this->actingAs($manager, 'api')
            ->patchJson("/api/v1/companies/{$company->id}/lifecycle", ['status' => CompanyStatus::Churned->value])
            ->assertStatus(422)
            ->assertJsonValidationErrors('reason');

        $churned = null;
        Event::listen(CompanyChurned::class, function (CompanyChurned $event) use (&$churned): void {
            $churned = $event;
        });

        $this->actingAs($manager, 'api')
            ->patchJson("/api/v1/companies/{$company->id}/lifecycle", [
                'status' => CompanyStatus::Churned->value,
                'reason' => 'price',
                'note' => 'Moved to an in-house team.',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'churned')
            ->assertJsonPath('data.churn_reason', 'price');

        $company->refresh();

        $this->assertNotNull($company->churned_at);
        $this->assertNotNull($churned);
        $this->assertSame('price', $churned->reason);

        $this->assertDatabaseHas('activities', [
            'subject_type' => Company::class,
            'subject_id' => $company->id,
            'subject' => 'Client status changed active → churned',
            'body' => 'Moved to an in-house team.',
        ]);
    }

    public function test_reactivation_is_only_for_churned_clients(): void
    {
        $manager = User::factory()->manager()->create();

        $prospect = Company::factory()->ownedBy($manager)->create(['status' => CompanyStatus::Prospect]);

        $this->actingAs($manager, 'api')
            ->postJson("/api/v1/companies/{$prospect->id}/reactivate")
            ->assertStatus(422)
            ->assertJsonValidationErrors('status');

        $churned = Company::factory()->ownedBy($manager)->create([
            'status' => CompanyStatus::Churned,
            'client_since' => now()->subYears(3)->toDateString(),
            'churn_reason' => 'price',
            'churned_at' => now()->subMonth(),
        ]);

        $activated = null;
        Event::listen(CompanyActivated::class, function (CompanyActivated $event) use (&$activated): void {
            $activated = $event;
        });

        $this->actingAs($manager, 'api')
            ->postJson("/api/v1/companies/{$churned->id}/reactivate", ['note' => 'Won back on a pilot.'])
            ->assertOk()
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.churn_reason', null)
            ->assertJsonPath('data.churned_at', null);

        $churned->refresh();

        // The original client date survives the churn/win-back round trip.
        $this->assertSame(now()->subYears(3)->toDateString(), $churned->client_since?->toDateString());
        $this->assertNotNull($activated);
    }

    public function test_a_client_can_be_parked_at_risk_or_dormant_manually(): void
    {
        $manager = User::factory()->manager()->create();
        $company = Company::factory()->ownedBy($manager)->create(['status' => CompanyStatus::Active]);

        // Aging is manual in Phase 1 (spec 09 §5, ADR-0013) — no scheduler flips these.
        $this->actingAs($manager, 'api')
            ->patchJson("/api/v1/companies/{$company->id}/lifecycle", ['status' => CompanyStatus::AtRisk->value])
            ->assertOk()
            ->assertJsonPath('data.status', 'at_risk');

        $this->actingAs($manager, 'api')
            ->patchJson("/api/v1/companies/{$company->id}/lifecycle", ['status' => CompanyStatus::Dormant->value])
            ->assertOk()
            ->assertJsonPath('data.status', 'dormant')
            ->assertJsonPath('data.allowed_status_transitions', ['active', 'at_risk', 'churned']);
    }

    public function test_billing_is_hidden_from_salesreps_and_read_only_for_managers(): void
    {
        $team = Team::factory()->create();
        $rep = User::factory()->salesrep()->create(['team_id' => $team->id]);
        $manager = User::factory()->manager()->create(['team_id' => $team->id]);
        $company = Company::factory()->ownedBy($rep)->create(['status' => CompanyStatus::Active]);

        $this->actingAs($rep, 'api')
            ->getJson("/api/v1/companies/{$company->id}/billing")
            ->assertStatus(403);

        $this->actingAs($manager, 'api')
            ->getJson("/api/v1/companies/{$company->id}/billing")
            ->assertOk()
            ->assertJsonPath('meta.source', 'Finance (mocked)')
            ->assertJsonPath('meta.read_only', true)
            ->assertJsonStructure([
                'data' => [
                    'account_status',
                    'outstanding',
                    'overdue',
                    'overdue_invoices',
                    'last_invoice_date',
                    'next_due_date',
                    'credit_terms',
                    'currency',
                    'aging' => ['current', '1_30', '31_60', '60_plus'],
                ],
            ]);

        // The snapshot is deterministic — the same company always reads the same numbers.
        $first = $this->actingAs($manager, 'api')->getJson("/api/v1/companies/{$company->id}/billing")->json('data');
        $second = $this->actingAs($manager, 'api')->getJson("/api/v1/companies/{$company->id}/billing")->json('data');

        $this->assertSame($first, $second);

        $company->update(['status' => CompanyStatus::Churned]);

        $this->actingAs($manager, 'api')
            ->getJson("/api/v1/companies/{$company->id}/billing")
            ->assertOk()
            ->assertJsonPath('data.account_status', 'closed');
    }

    public function test_the_lifecycle_of_a_client_with_a_live_contract_is_not_cleared_by_signing(): void
    {
        $manager = User::factory()->manager()->create();
        $company = Company::factory()->ownedBy($manager)->create(['status' => CompanyStatus::Prospect]);

        // A signed contract activates the client; a manual at-risk move afterwards sticks
        // even if another agreement is signed later (spec 09 §3).
        $this->actingAs($manager, 'api')
            ->patchJson("/api/v1/companies/{$company->id}/lifecycle", ['status' => CompanyStatus::Onboarding->value])
            ->assertOk();

        $contract = Contract::factory()->forCompany($company)->create();

        $this->actingAs($manager, 'api')
            ->postJson("/api/v1/contracts/{$contract->id}/mark-signed")
            ->assertOk();

        $this->assertSame(CompanyStatus::Active, $company->fresh()->status);

        $this->actingAs($manager, 'api')
            ->patchJson("/api/v1/companies/{$company->id}/lifecycle", ['status' => CompanyStatus::AtRisk->value])
            ->assertOk();

        $second = Contract::factory()->forCompany($company)->create();

        $this->actingAs($manager, 'api')
            ->postJson("/api/v1/contracts/{$second->id}/mark-signed")
            ->assertOk();

        $this->assertSame(CompanyStatus::AtRisk, $company->fresh()->status);
    }
}
