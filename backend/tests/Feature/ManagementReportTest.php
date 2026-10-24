<?php

namespace Tests\Feature;

use App\Enums\ActivityStatus;
use App\Enums\ActivityType;
use App\Enums\CompanyStatus;
use App\Enums\LeadStatus;
use App\Enums\ManagementReportVariant;
use App\Enums\OpportunityStage;
use App\Models\Company;
use App\Models\Lead;
use App\Models\Opportunity;
use App\Models\SurveyResponse;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The rules-based management report (module 07, spec 15, S1).
 *
 * The report is a scoped read over the dashboard data. These tests prove the three variants, the
 * three roles and the three periods, and that the read path never reaches the provider seam.
 */
class ManagementReportTest extends TestCase
{
    use RefreshDatabase;

    private Team $team;

    private User $manager;

    private User $rep;

    private User $teammate;

    private User $outsider;

    private User $admin;

    private Company $repCompany;

    private Company $teammateCompany;

    private Company $outsiderCompany;

    private Lead $repLead;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-10-24 09:00:00', 'Asia/Manila'));

        $this->team = Team::factory()->create(['name' => 'Business Development']);
        $otherTeam = Team::factory()->create(['name' => 'Other Team']);

        $this->manager = User::factory()->manager()->create(['team_id' => $this->team->id]);
        $this->rep = User::factory()->salesrep()->create(['team_id' => $this->team->id]);
        $this->teammate = User::factory()->salesrep()->create(['team_id' => $this->team->id]);
        $this->outsider = User::factory()->salesrep()->create(['team_id' => $otherTeam->id]);
        $this->admin = User::factory()->admin()->create();

        $this->repCompany = Company::factory()->client()->ownedBy($this->rep)->create([
            'name' => 'Sunrise Foods Inc',
        ]);
        $this->teammateCompany = Company::factory()->client()->ownedBy($this->teammate)->create([
            'name' => 'BPO Solutions PH',
        ]);
        $this->outsiderCompany = Company::factory()->client()->ownedBy($this->outsider)->create([
            'name' => 'Outsider Foods',
        ]);
        Company::factory()->ownedBy($this->rep)->create([
            'name' => 'Metro Builders',
            'status' => CompanyStatus::AtRisk,
        ]);
        Company::factory()->ownedBy($this->rep)->create([
            'name' => 'Greenfield Hotels',
            'status' => CompanyStatus::Churned,
        ]);

        $this->repLead = Lead::factory()->create([
            'company_name' => 'Sunrise Foods Inc',
            'owner_id' => $this->rep->id,
            'status' => LeadStatus::Qualified,
        ]);
        $this->repLead->forceFill([
            'created_at' => now()->subDays(4),
            'status_changed_at' => now()->subDays(3),
        ])->saveQuietly();

        // This lead is inside the trailing quarter but outside the last 30 days, so the period
        // toggle has a number to change.
        $olderLead = Lead::factory()->create([
            'company_name' => 'Older Manufacturing',
            'owner_id' => $this->rep->id,
            'status' => LeadStatus::New,
        ]);
        $olderLead->forceFill(['created_at' => now()->subDays(45)])->saveQuietly();

        Opportunity::factory()->forCompany($this->repCompany)->ownedBy($this->rep)
            ->stage(OpportunityStage::Negotiation)->create([
                'name' => 'Sunrise night-shift crew',
                'amount' => 1_000_000,
                'actual_close_date' => null,
            ]);
        Opportunity::factory()->forCompany($this->teammateCompany)->ownedBy($this->teammate)
            ->stage(OpportunityStage::ProposalSent)->create([
                'name' => 'BPO 40-seat start',
                'amount' => 2_000_000,
                'actual_close_date' => null,
            ]);
        Opportunity::factory()->forCompany($this->outsiderCompany)->ownedBy($this->outsider)
            ->stage(OpportunityStage::NewInquiry)->create([
                'name' => 'Outsider warehouse crew',
                'amount' => 9_000_000,
                'actual_close_date' => null,
            ]);
        Opportunity::factory()->forCompany($this->repCompany)->ownedBy($this->rep)
            ->stage(OpportunityStage::Won)->create([
                'name' => 'Sunrise housekeeping',
                'amount' => 500_000,
                'actual_close_date' => now()->subDays(3)->toDateString(),
            ]);
        Opportunity::factory()->forCompany($this->teammateCompany)->ownedBy($this->teammate)
            ->stage(OpportunityStage::Lost)->create([
                'name' => 'BPO contact centre',
                'amount' => 300_000,
                'actual_close_date' => now()->subDays(4)->toDateString(),
            ]);

        $this->repLead->activities()->create([
            'type' => ActivityType::Task,
            'subject' => 'Send the revised rate sheet',
            'status' => ActivityStatus::Open,
            'due_at' => now()->subDay(),
            'owner_id' => $this->rep->id,
        ]);

        SurveyResponse::factory()->scored(2.0, 4)->create([
            'company_id' => $this->repCompany->id,
            'submitted_at' => now()->subDays(2),
            'needs_attention' => true,
        ]);

        Http::fake();
    }

    public function test_default_report_is_the_executive_rules_report_with_a_complete_shape(): void
    {
        $response = $this->actingAs($this->rep, 'api')
            ->getJson('/api/v1/reports/management')
            ->assertOk();

        $response
            ->assertJsonPath('data.variant', 'executive')
            ->assertJsonPath('data.title', 'Executive management report')
            ->assertJsonPath('data.source', 'rules')
            ->assertJsonPath('data.model', 'Primepower rules v1')
            ->assertJsonPath('data.cached', false)
            ->assertJsonPath('data.fallback', null)
            ->assertJsonPath('meta.range', '30d')
            ->assertJsonPath('meta.ai_available', false)
            ->assertJsonPath('meta.variants', ['executive', 'pipeline', 'client_health']);

        $data = $response->json('data');

        $this->assertSame('personal', $response->json('meta.scope.tier'));
        $this->assertSame('Your book', $response->json('meta.scope.label'));
        $this->assertNotEmpty($data['headline']);
        $this->assertNotEmpty($data['narrative']);
        $this->assertCount(3, $data['insights']);
        $this->assertCount(4, $data['actions']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T/', $data['generated_at']);

        foreach ([...$data['insights'], ...$data['actions']] as $link) {
            $this->assertStringStartsWith('/', $link['href']);
        }

        Http::assertNothingSent();
    }

    public function test_each_variant_answers_its_own_question_from_the_same_scope(): void
    {
        $reports = [];

        foreach (ManagementReportVariant::cases() as $variant) {
            $reports[$variant->value] = $this->report($this->rep, $variant->value);
        }

        $this->assertSame('Pipeline management report', $reports['pipeline']['title']);
        $this->assertSame('Client health report', $reports['client_health']['title']);
        $this->assertNotSame($reports['executive']['headline'], $reports['pipeline']['headline']);
        $this->assertNotSame($reports['pipeline']['narrative'], $reports['client_health']['narrative']);

        $this->assertStringContainsString('Negotiation', $reports['pipeline']['headline']);
        $this->assertStringContainsString('1 open deal', $reports['pipeline']['narrative']);
        $this->assertStringContainsString('CSAT 2 / 5', $reports['client_health']['narrative']);
        $this->assertStringContainsString('NPS -100', $reports['client_health']['narrative']);

        foreach ($reports as $report) {
            $this->assertCount(3, $report['insights']);
            $this->assertCount(4, $report['actions']);
        }

        Http::assertNothingSent();
    }

    public function test_scope_is_inherited_from_the_caller_never_from_the_query(): void
    {
        $rep = $this->report($this->rep, 'pipeline');
        $manager = $this->report($this->manager, 'pipeline');
        $admin = $this->report($this->admin, 'pipeline');

        $this->assertStringContainsString('worth ₱1.00M', $rep['narrative']);
        $this->assertStringContainsString('worth ₱3.00M', $manager['narrative']);
        $this->assertStringContainsString('worth ₱12.00M', $admin['narrative']);

        $repMeta = $this->actingAs($this->rep, 'api')
            ->getJson('/api/v1/reports/management?variant=pipeline')
            ->json('meta.scope');
        $managerMeta = $this->actingAs($this->manager, 'api')
            ->getJson('/api/v1/reports/management?variant=pipeline')
            ->json('meta.scope');
        $adminMeta = $this->actingAs($this->admin, 'api')
            ->getJson('/api/v1/reports/management?variant=pipeline')
            ->json('meta.scope');

        $this->assertSame('personal', $repMeta['tier']);
        $this->assertSame('team', $managerMeta['tier']);
        $this->assertSame('org', $adminMeta['tier']);

        $this->assertSame(
            'personal',
            $this->actingAs($this->rep, 'api')
                ->getJson('/api/v1/reports/management?variant=pipeline&scope=org')
                ->assertOk()
                ->json('meta.scope.tier'),
        );

        Http::assertNothingSent();
    }

    public function test_the_period_toggle_changes_the_rule_report(): void
    {
        $last30 = $this->actingAs($this->rep, 'api')
            ->getJson('/api/v1/reports/management?variant=executive&range=30d')
            ->assertOk()
            ->json();
        $quarter = $this->actingAs($this->rep, 'api')
            ->getJson('/api/v1/reports/management?variant=executive&range=quarter')
            ->assertOk()
            ->json();
        $ytd = $this->actingAs($this->rep, 'api')
            ->getJson('/api/v1/reports/management?variant=executive&range=ytd')
            ->assertOk()
            ->json();

        $this->assertSame('30d', $last30['meta']['range']);
        $this->assertSame('quarter', $quarter['meta']['range']);
        $this->assertSame('ytd', $ytd['meta']['range']);

        $this->assertStringContainsString('1 new lead', $last30['data']['narrative']);
        $this->assertStringContainsString('2 new leads', $quarter['data']['narrative']);
        $this->assertStringContainsString('2 new leads', $ytd['data']['narrative']);

        Http::assertNothingSent();
    }

    public function test_unknown_variant_or_range_is_rejected(): void
    {
        $this->actingAs($this->rep, 'api')
            ->getJson('/api/v1/reports/management?variant=team_performance')
            ->assertUnprocessable();

        $this->actingAs($this->rep, 'api')
            ->getJson('/api/v1/reports/management?range=all-time')
            ->assertUnprocessable();
    }

    public function test_the_report_requires_authentication(): void
    {
        $this->getJson('/api/v1/reports/management')->assertUnauthorized();
    }

    /**
     * @return array<string, mixed>
     */
    private function report(User $user, string $variant): array
    {
        return $this->actingAs($user, 'api')
            ->getJson('/api/v1/reports/management?variant='.$variant)
            ->assertOk()
            ->json('data');
    }
}
