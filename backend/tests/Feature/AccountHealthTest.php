<?php

namespace Tests\Feature;

use App\Enums\CompanyStatus;
use App\Enums\HandoffStatus;
use App\Models\Company;
use App\Models\Contract;
use App\Models\ContractRateCard;
use App\Models\SurveyResponse;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class AccountHealthTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_healthy_account_has_no_flags_and_full_finance_for_a_manager(): void
    {
        $manager = User::factory()->manager()->create();
        $company = $this->account($manager, 'CO-2026-0003', CompanyStatus::Active, HandoffStatus::Sent);
        $this->contractWithCrew($company);

        $this->actingAs($manager, 'api')
            ->getJson("/api/v1/companies/{$company->id}")
            ->assertOk()
            ->assertJsonPath('data.health.state', 'healthy')
            ->assertJsonPath('data.health.flags', [])
            ->assertJsonPath('data.health.suggested_action', null)
            ->assertJsonPath('data.health.can_view_financial_detail', true);

        $this->assertSame(CompanyStatus::Active, $company->refresh()->status);
    }

    public function test_a_failed_handoff_with_overdue_ar_and_unhappy_client_is_at_risk(): void
    {
        $team = Team::factory()->create();
        $rep = User::factory()->salesrep()->create(['team_id' => $team->id]);
        $manager = User::factory()->manager()->create(['team_id' => $team->id]);

        $company = $this->account($rep, 'CO-2026-0002', CompanyStatus::Onboarding, HandoffStatus::Failed);
        $this->contractWithCrew($company);

        SurveyResponse::factory()->scored(2.0, 4)->create([
            'company_id' => $company->id,
            'needs_attention' => true,
        ]);

        $response = $this->actingAs($manager, 'api')
            ->getJson("/api/v1/companies/{$company->id}")
            ->assertOk()
            ->assertJsonPath('data.health.state', 'at_risk')
            ->assertJsonPath('data.health.suggested_action.code', 'handoff_failed')
            ->assertJsonPath('data.health.suggested_action.label', 'Retry handoff')
            ->assertJsonPath('data.health.can_view_financial_detail', true);

        $this->assertSame(
            [
                'handoff_failed',
                'ar_overdue',
                'satisfaction_attention',
                'deployment_underfilled',
                'job_order_stalled',
                'timesheets_pending',
            ],
            array_column($response->json('data.health.flags'), 'code'),
        );

        $flags = collect($response->json('data.health.flags'))->keyBy('code');
        $this->assertStringContainsString('PHP', $flags['ar_overdue']['reason']);
        $this->assertStringContainsString('overdue', $flags['ar_overdue']['reason']);
        $this->assertSame('Finance (mocked)', $flags['ar_overdue']['source']);
        $this->assertSame('Finance follow-up', $flags['ar_overdue']['action']['label']);
        $this->assertSame(
            '/accounts/'.$company->id.'?tab=commercial&section=billing',
            $flags['ar_overdue']['action']['url'],
        );

        // The read never moves the lifecycle, even when the flags are critical.
        $this->assertSame(CompanyStatus::Onboarding, $company->refresh()->status);

        $redacted = $this->actingAs($rep, 'api')
            ->getJson("/api/v1/companies/{$company->id}")
            ->assertOk()
            ->assertJsonPath('data.health.state', 'at_risk')
            ->assertJsonPath('data.health.can_view_financial_detail', false)
            ->json('data.health.flags');

        $redactedFlags = collect($redacted)->keyBy('code');
        $this->assertSame(
            'Finance reports an overdue balance. Follow up with your manager.',
            $redactedFlags['ar_overdue']['reason'],
        );
        $this->assertStringNotContainsString('PHP', $redactedFlags['ar_overdue']['reason']);
        $this->assertStringNotContainsString('935', $redactedFlags['ar_overdue']['reason']);
        $this->assertSame('Open account', $redactedFlags['ar_overdue']['action']['label']);
    }

    public function test_expiring_and_expired_contracts_raise_warnings(): void
    {
        $manager = User::factory()->manager()->create();

        $expiring = $this->account($manager, 'CO-2026-0003', CompanyStatus::Active, HandoffStatus::Sent);
        $this->contractWithCrew($expiring, now()->addDays(10));

        $this->actingAs($manager, 'api')
            ->getJson("/api/v1/companies/{$expiring->id}")
            ->assertOk()
            ->assertJsonPath('data.health.state', 'attention')
            ->assertJsonPath('data.health.flags.0.code', 'contract_expiring')
            ->assertJsonPath('data.health.flags.0.severity', 'warning')
            ->assertJsonPath('data.health.suggested_action.code', 'contract_expiring');

        $overdue = $this->account($manager, 'CO-2026-0005', CompanyStatus::Active, HandoffStatus::Sent);
        $this->contractWithCrew($overdue, now()->subMonths(2));

        $this->actingAs($manager, 'api')
            ->getJson("/api/v1/companies/{$overdue->id}")
            ->assertOk()
            ->assertJsonPath('data.health.state', 'attention')
            ->assertJsonPath('data.health.flags.0.code', 'renewal_overdue')
            ->assertJsonPath('data.health.flags.0.severity', 'warning');
    }

    public function test_an_account_without_recent_engagement_gets_an_informational_flag(): void
    {
        $manager = User::factory()->manager()->create();
        $company = $this->account($manager, 'CO-2026-0003', CompanyStatus::Active, HandoffStatus::Sent);
        $company->forceFill(['created_at' => now()->subDays(60)])->save();
        $this->contractWithCrew($company);

        $this->actingAs($manager, 'api')
            ->getJson("/api/v1/companies/{$company->id}")
            ->assertOk()
            ->assertJsonPath('data.health.state', 'healthy')
            ->assertJsonPath('data.health.flags.0.code', 'engagement_quiet')
            ->assertJsonPath('data.health.flags.0.severity', 'info');
    }

    public function test_an_expiring_contract_with_a_signed_renewal_does_not_raise_a_flag(): void
    {
        $manager = User::factory()->manager()->create();
        $company = $this->account($manager, 'CO-2026-0003', CompanyStatus::Active, HandoffStatus::Sent);
        $original = $this->contractWithCrew($company, now()->addDays(10));

        Contract::factory()->forCompany($company)->signed()->create([
            'start_date' => now()->addDays(11)->toDateString(),
            'end_date' => now()->addYear()->toDateString(),
            'renewal_of_contract_id' => $original->id,
        ]);

        $this->actingAs($manager, 'api')
            ->getJson("/api/v1/companies/{$company->id}")
            ->assertOk()
            ->assertJsonPath('data.health.state', 'healthy')
            ->assertJsonPath('data.health.flags', []);
    }

    public function test_the_account_book_filters_by_computed_health(): void
    {
        $manager = User::factory()->manager()->create();

        $healthy = $this->account($manager, 'CO-2026-0003', CompanyStatus::Active, HandoffStatus::Sent);
        $this->contractWithCrew($healthy);

        $attention = $this->account($manager, 'CO-2026-0005', CompanyStatus::Active, HandoffStatus::Sent);
        $this->contractWithCrew($attention, now()->addDays(10));

        $atRisk = $this->account($manager, 'CO-2026-0002', CompanyStatus::Onboarding, HandoffStatus::Failed);
        $this->contractWithCrew($atRisk);

        $this->actingAs($manager, 'api')
            ->getJson('/api/v1/companies?filter[health]=at_risk')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $atRisk->id)
            ->assertJsonPath('data.0.health.state', 'at_risk')
            ->assertJsonPath('meta.total', 1);

        $this->actingAs($manager, 'api')
            ->getJson('/api/v1/companies?filter[health]=healthy')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $healthy->id)
            ->assertJsonPath('data.0.health.state', 'healthy');

        $this->actingAs($manager, 'api')
            ->getJson('/api/v1/companies?filter[health]=attention')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $attention->id)
            ->assertJsonPath('data.0.health.state', 'attention');

        $this->actingAs($manager, 'api')
            ->getJson('/api/v1/companies')
            ->assertOk()
            ->assertJsonCount(3, 'data');
    }

    public function test_health_is_scoped_to_the_accounts_the_caller_may_see(): void
    {
        $manager = User::factory()->manager()->create();
        $other = User::factory()->salesrep()->create();

        $own = $this->account($manager, 'CO-2026-0003', CompanyStatus::Active, HandoffStatus::Sent);
        $this->contractWithCrew($own);

        $foreign = $this->account($other, 'CO-2026-0002', CompanyStatus::Onboarding, HandoffStatus::Failed);
        $this->contractWithCrew($foreign);

        $this->actingAs($manager, 'api')
            ->getJson('/api/v1/companies?filter[health]=at_risk')
            ->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('meta.total', 0);
    }

    private function account(
        User $owner,
        string $reference,
        CompanyStatus $status,
        ?HandoffStatus $handoff,
    ): Company {
        return Company::factory()->ownedBy($owner)->create([
            'reference' => $reference,
            'status' => $status,
            'handoff_status' => $handoff,
            'handoff_reference' => $handoff === HandoffStatus::Sent ? 'SVC-CON-2026-0001' : null,
            'handoff_requested_at' => $handoff === null ? null : now()->subMonth(),
        ]);
    }

    private function contractWithCrew(Company $company, ?Carbon $endDate = null): Contract
    {
        $contract = Contract::factory()->forCompany($company)->signed()->create([
            'start_date' => now()->subMonth()->toDateString(),
            'end_date' => ($endDate ?? now()->addMonths(11))->toDateString(),
        ]);

        ContractRateCard::factory()->forContract($contract)->hourly()->create([
            'role_title' => 'Customer service associate',
            'headcount' => 4,
            'position' => 1,
        ]);

        return $contract;
    }
}
