<?php

namespace Tests\Feature;

use App\Enums\CompanyStatus;
use App\Enums\ContractStatus;
use App\Enums\HandoffStatus;
use App\Models\Company;
use App\Models\CompanySite;
use App\Models\Contract;
use App\Models\ContractRateCard;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CompanyDeliveryTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_active_account_returns_filled_job_orders_active_deployments_and_current_hours(): void
    {
        [$rep, $company] = $this->account(HandoffStatus::Sent, CompanyStatus::Active);
        $contract = $this->contract($company);
        $site = CompanySite::factory()->create([
            'company_id' => $company->id,
            'label' => 'Manila South Harbor office',
            'is_primary' => true,
        ]);
        ContractRateCard::factory()->forContract($contract)->hourly()->create([
            'role_title' => 'Warehouse associate',
            'headcount' => 6,
            'site_id' => $site->id,
        ]);

        $response = $this->actingAs($rep, 'api')
            ->getJson("/api/v1/companies/{$company->id}/delivery")
            ->assertOk()
            ->assertJsonPath('data.handoff.status', 'sent')
            ->assertJsonPath('data.job_orders.0.role', 'Warehouse associate')
            ->assertJsonPath('data.job_orders.0.requested', 6)
            ->assertJsonPath('data.job_orders.0.filled', 6)
            ->assertJsonPath('data.job_orders.0.status', 'filled')
            ->assertJsonPath('data.job_orders.0.recruiter', 'Recruitment (mocked)')
            ->assertJsonPath('data.deployments.0.status', 'active')
            ->assertJsonPath('data.deployments.0.active_headcount', 6)
            ->assertJsonPath('data.deployments.0.requested_headcount', 6)
            ->assertJsonPath('data.deployments.0.fill_rate', 100)
            ->assertJsonPath('data.deployments.0.site', 'Manila South Harbor office')
            ->assertJsonPath('data.deployments.0.operations_owner', 'Deployment operations (mocked)')
            ->assertJsonPath('data.deployments.0.timesheet.status', 'current')
            ->assertJsonPath('data.deployments.0.timesheet.approved_hours', 1056)
            ->assertJsonPath('data.timesheets.approved_hours', 1056)
            ->assertJsonPath('data.timesheets.pending_hours', 0)
            ->assertJsonPath('data.timesheets.deployments_pending', 0)
            ->assertJsonPath('meta.read_only', true)
            ->assertJsonPath('meta.source', 'Recruitment / Deployment / Timekeeping (mocked, read-only)');

        $this->assertNotEmpty($response->json('meta.as_of'));
    }

    public function test_a_failed_handoff_still_has_open_demand_and_a_missing_timesheet(): void
    {
        [$rep, $company] = $this->account(HandoffStatus::Failed, CompanyStatus::Onboarding);
        $contract = $this->contract($company);
        ContractRateCard::factory()->forContract($contract)->hourly()->create([
            'role_title' => 'Customer service associate',
            'headcount' => 4,
        ]);

        $this->actingAs($rep, 'api')
            ->getJson("/api/v1/companies/{$company->id}/delivery")
            ->assertOk()
            ->assertJsonPath('data.handoff.status', 'failed')
            ->assertJsonPath('data.handoff.can_retry', true)
            ->assertJsonPath('data.job_orders.0.status', 'open')
            ->assertJsonPath('data.job_orders.0.priority', 'high')
            ->assertJsonPath('data.job_orders.0.filled', 0)
            ->assertJsonPath('data.deployments.0.status', 'pending')
            ->assertJsonPath('data.deployments.0.active_headcount', 0)
            ->assertJsonPath('data.deployments.0.fill_rate', 0)
            ->assertJsonPath('data.deployments.0.timesheet.status', 'missing')
            ->assertJsonPath('data.deployments.0.timesheet.approved_hours', 0)
            ->assertJsonPath('data.deployments.0.timesheet.pending_hours', 704)
            ->assertJsonPath('data.timesheets.pending_hours', 704)
            ->assertJsonPath('data.timesheets.deployments_pending', 1);
    }

    public function test_a_prospect_without_a_signed_contract_has_no_delivery(): void
    {
        [$rep, $company] = $this->account(null, CompanyStatus::Prospect);

        Contract::factory()->forCompany($company)->sent()->create();

        $this->actingAs($rep, 'api')
            ->getJson("/api/v1/companies/{$company->id}/delivery")
            ->assertOk()
            ->assertJsonPath('data.handoff.status', null)
            ->assertJsonCount(0, 'data.job_orders')
            ->assertJsonCount(0, 'data.deployments')
            ->assertJsonPath('data.timesheets.approved_hours', 0)
            ->assertJsonPath('data.timesheets.pending_hours', 0)
            ->assertJsonPath('data.timesheets.deployments_pending', 0);
    }

    public function test_a_churned_account_has_ended_deployments_and_closed_demand(): void
    {
        [$rep, $company] = $this->account(HandoffStatus::Sent, CompanyStatus::Churned);
        $contract = $this->contract($company, ContractStatus::Terminated);
        ContractRateCard::factory()->forContract($contract)->hourly()->create([
            'role_title' => 'Housekeeper',
            'headcount' => 5,
        ]);

        $this->actingAs($rep, 'api')
            ->getJson("/api/v1/companies/{$company->id}/delivery")
            ->assertOk()
            ->assertJsonPath('data.job_orders.0.status', 'closed')
            ->assertJsonPath('data.deployments.0.status', 'ended')
            ->assertJsonPath('data.deployments.0.active_headcount', 0)
            ->assertJsonPath('data.deployments.0.fill_rate', 0)
            ->assertJsonPath('data.deployments.0.timesheet.status', 'current')
            ->assertJsonPath('data.deployments.0.timesheet.approved_hours', 880);
    }

    public function test_placement_lines_are_not_deployed(): void
    {
        [$rep, $company] = $this->account(HandoffStatus::Sent, CompanyStatus::Active);
        $contract = $this->contract($company);

        ContractRateCard::factory()->forContract($contract)->hourly()->create(['position' => 1]);
        ContractRateCard::factory()->forContract($contract)->placement()->create(['position' => 2]);

        $this->actingAs($rep, 'api')
            ->getJson("/api/v1/companies/{$company->id}/delivery")
            ->assertOk()
            ->assertJsonCount(1, 'data.job_orders')
            ->assertJsonCount(1, 'data.deployments');
    }

    public function test_a_salesrep_cannot_read_another_accounts_delivery_feed(): void
    {
        [$owner, $company] = $this->account(HandoffStatus::Sent, CompanyStatus::Active);
        $other = User::factory()->salesrep()->create();

        $this->actingAs($other, 'api')
            ->getJson("/api/v1/companies/{$company->id}/delivery")
            ->assertForbidden();
    }

    public function test_a_manager_can_read_a_team_accounts_delivery_feed(): void
    {
        $team = Team::factory()->create();
        $rep = User::factory()->salesrep()->create(['team_id' => $team->id]);
        $manager = User::factory()->manager()->create(['team_id' => $team->id]);
        $company = Company::factory()->ownedBy($rep)->create([
            'status' => CompanyStatus::Active,
            'handoff_status' => HandoffStatus::Sent,
        ]);

        $this->actingAs($manager, 'api')
            ->getJson("/api/v1/companies/{$company->id}/delivery")
            ->assertOk()
            ->assertJsonPath('data.handoff.status', 'sent');
    }

    public function test_the_delivery_feed_has_no_write_route(): void
    {
        [$rep, $company] = $this->account(HandoffStatus::Sent, CompanyStatus::Active);

        $this->actingAs($rep, 'api')
            ->postJson("/api/v1/companies/{$company->id}/delivery", [])
            ->assertStatus(405);
    }

    /**
     * @return array{0: User, 1: Company}
     */
    private function account(?HandoffStatus $handoff, CompanyStatus $status): array
    {
        $rep = User::factory()->salesrep()->create();
        $company = Company::factory()->ownedBy($rep)->create([
            'status' => $status,
            'handoff_status' => $handoff,
            'handoff_reference' => $handoff === HandoffStatus::Sent ? 'SVC-CON-2026-0001' : null,
            'handoff_requested_at' => $handoff === null ? null : now()->subMonth(),
        ]);

        return [$rep, $company];
    }

    private function contract(Company $company, ContractStatus $status = ContractStatus::Signed): Contract
    {
        return Contract::factory()->forCompany($company)->create([
            'status' => $status,
            'start_date' => now()->subMonth()->toDateString(),
            'end_date' => now()->addMonths(11)->toDateString(),
            'signed_at' => $status === ContractStatus::Signed ? now()->subMonth() : now()->subMonths(3),
        ]);
    }
}
