<?php

namespace Tests\Feature;

use App\Enums\ActivityStatus;
use App\Enums\ActivityType;
use App\Enums\CompanyStatus;
use App\Enums\LeadStatus;
use App\Enums\OpportunityStage;
use App\Models\Communication;
use App\Models\Company;
use App\Models\Contract;
use App\Models\Lead;
use App\Models\Opportunity;
use App\Models\SurveyResponse;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The dashboard read model (module 06, spec 14, D2).
 *
 * Every assertion compares the dashboard against the module it reads — the pipeline KPI against
 * `/api/v1/pipeline`, the activity feed against the record scopes — so a wrong scope cannot pass
 * by agreeing with itself.
 */
class DashboardSummaryTest extends TestCase
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

    private Company $managerCompany;

    private Company $outsiderCompany;

    private Company $atRiskCompany;

    private Lead $repLead;

    private Lead $teammateLead;

    private Lead $managerLead;

    private Lead $outsiderLead;

    private Lead $unassignedLead;

    private Lead $archivedLead;

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
            'client_since' => now()->subDays(14)->toDateString(),
        ]);
        $this->teammateCompany = Company::factory()->client()->ownedBy($this->teammate)->create([
            'name' => 'BPO Solutions PH',
        ]);
        $this->managerCompany = Company::factory()->client()->ownedBy($this->manager)->create([
            'name' => 'Harbor Logistics',
        ]);
        $this->outsiderCompany = Company::factory()->client()->ownedBy($this->outsider)->create([
            'name' => 'Outsider Foods',
        ]);
        $this->atRiskCompany = Company::factory()->ownedBy($this->rep)->create([
            'name' => 'Metro Builders',
            'status' => CompanyStatus::AtRisk,
        ]);

        $this->repLead = $this->leadFor($this->rep, 'Sunrise Foods Inc', LeadStatus::Qualified, now()->subDays(5), now()->subDays(4));
        $this->teammateLead = $this->leadFor($this->teammate, 'BPO Solutions PH', LeadStatus::New, now()->subDays(3));
        $this->managerLead = $this->leadFor($this->manager, 'Harbor Logistics', LeadStatus::New, now()->subDays(2));
        $this->outsiderLead = $this->leadFor($this->outsider, 'Outsider Foods', LeadStatus::New, now()->subDay());
        $this->unassignedLead = $this->leadFor(null, 'Unassigned Manufacturing', LeadStatus::New, now()->subDays(2));
        $this->archivedLead = $this->leadFor($this->rep, 'Archived Account', LeadStatus::Archived, now()->subDay());

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
        Opportunity::factory()->forCompany($this->managerCompany)->ownedBy($this->manager)
            ->stage(OpportunityStage::NewInquiry)->create([
                'name' => 'Harbor forklift pool',
                'amount' => 400_000,
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
        Opportunity::factory()->forCompany($this->outsiderCompany)->ownedBy($this->outsider)
            ->stage(OpportunityStage::Won)->create([
                'name' => 'Outsider kitchen crew',
                'amount' => 1_000_000,
                'actual_close_date' => now()->subDays(6)->toDateString(),
            ]);

        Contract::factory()->forCompany($this->repCompany)->expiringSoon()->create();
        Contract::factory()->forCompany($this->teammateCompany)->expiringSoon()->create([
            'end_date' => now()->addDays(5)->toDateString(),
        ]);
        Contract::factory()->forCompany($this->outsiderCompany)->expiringSoon()->create([
            'end_date' => now()->addDays(2)->toDateString(),
        ]);

        $this->repLead->activities()->create([
            'type' => ActivityType::Task,
            'subject' => 'Send the revised rate sheet',
            'status' => ActivityStatus::Open,
            'due_at' => now()->subDay(),
            'owner_id' => $this->rep->id,
        ]);
        $this->repLead->activities()->create([
            'type' => ActivityType::Task,
            'subject' => 'Confirm the commissary visit',
            'status' => ActivityStatus::Open,
            'due_at' => now()->addDay(),
            'owner_id' => $this->rep->id,
        ]);
        Communication::factory()->about($this->repLead, $this->rep)
            ->scheduled(now()->addDay())
            ->create(['subject' => 'Rate-sheet follow-up']);

        $this->teammateLead->activities()->create([
            'type' => ActivityType::Task,
            'subject' => 'Chase the signed contract',
            'status' => ActivityStatus::Open,
            'due_at' => now()->setTime(17, 0),
            'owner_id' => $this->teammate->id,
        ]);
        $this->outsiderLead->activities()->create([
            'type' => ActivityType::Task,
            'subject' => 'Outsider-only task',
            'status' => ActivityStatus::Open,
            'due_at' => now()->addHour(),
            'owner_id' => $this->outsider->id,
        ]);

        $this->repLead->activities()->create([
            'type' => ActivityType::Note,
            'subject' => 'Called Grace about the night shift',
            'body' => null,
            'status' => ActivityStatus::Done,
            'occurred_at' => now()->subHours(2),
            'owner_id' => $this->rep->id,
        ]);
        $this->outsiderLead->activities()->create([
            'type' => ActivityType::Note,
            'subject' => 'Outsider secret',
            'body' => null,
            'status' => ActivityStatus::Done,
            'occurred_at' => now()->subHour(),
            'owner_id' => $this->outsider->id,
        ]);

        SurveyResponse::factory()->scored(2.0, 4)->create([
            'company_id' => $this->repCompany->id,
            'submitted_at' => now()->subDays(2),
            'needs_attention' => true,
        ]);
        SurveyResponse::factory()->scored(3.0, 5)->create([
            'company_id' => $this->teammateCompany->id,
            'submitted_at' => now()->subDays(3),
            'needs_attention' => true,
        ]);
        SurveyResponse::factory()->scored(2.0, 3)->create([
            'company_id' => $this->outsiderCompany->id,
            'submitted_at' => now()->subDay(),
            'needs_attention' => true,
        ]);
    }

    public function test_salesrep_sees_only_their_book(): void
    {
        $data = $this->dashboard($this->rep);

        $this->assertSame('personal', $data['scope']['tier']);
        $this->assertSame('Your book', $data['scope']['label']);
        $this->assertSame('salesrep', $data['scope']['role']);

        $this->assertEquals(2, $this->kpi($data, 'new-leads')['value']);
        $this->assertEquals(1, $this->kpi($data, 'qualified')['value']);
        $this->assertEquals(1_000_000, $this->kpi($data, 'pipeline')['value']);
        $this->assertEquals(500_000, $this->kpi($data, 'won')['value']);
        $this->assertEquals(100, $this->kpi($data, 'win-rate')['value']);
        $this->assertEquals(2, $this->kpi($data, 'clients')['value']);
        $this->assertEquals(3, $this->kpi($data, 'follow-ups')['value']);
        $this->assertEquals(2, $this->kpi($data, 'attention')['value']);

        $this->assertCount(5, $data['line']['labels']);
        $this->assertSame([2, 1, 1], [
            array_sum($data['line']['series'][0]['values']),
            array_sum($data['line']['series'][1]['values']),
            array_sum($data['line']['series'][2]['values']),
        ]);

        $this->assertEquals(1_000_000, $data['donut']['total']);
        $this->assertCount(1, $data['donut']['segments']);
        $this->assertSame('Negotiation', $data['donut']['segments'][0]['label']);

        $this->assertSame(
            ['alert-attention', 'alert-follow-ups'],
            array_column($data['alerts'], 'id'),
        );

        // Reconcile the KPI against the module's own list.
        $pipeline = $this->actingAs($this->rep, 'api')
            ->getJson('/api/v1/pipeline')
            ->assertOk()
            ->json('meta');

        $this->assertEquals(
            (float) $pipeline['open_value'],
            (float) $this->kpi($data, 'pipeline')['value'],
        );
    }

    public function test_manager_sees_the_team_and_the_shared_queue(): void
    {
        $data = $this->dashboard($this->manager);

        $this->assertSame('team', $data['scope']['tier']);
        $this->assertSame('Business Development team', $data['scope']['label']);

        $this->assertEquals(4, $this->kpi($data, 'new-leads')['value']);
        $this->assertEquals(3_400_000, $this->kpi($data, 'pipeline')['value']);
        $this->assertEquals(500_000, $this->kpi($data, 'won')['value']);
        $this->assertEquals(50, $this->kpi($data, 'win-rate')['value']);
        $this->assertEquals(4, $this->kpi($data, 'clients')['value']);
        $this->assertEquals(4, $this->kpi($data, 'follow-ups')['value']);
        $this->assertEquals(3, $this->kpi($data, 'attention')['value']);

        $this->assertSame(
            ['alert-attention', 'alert-follow-ups', 'alert-contracts', 'alert-unassigned'],
            array_column($data['alerts'], 'id'),
        );
        $this->assertStringStartsWith('2 contracts', $data['alerts'][2]['title']);

        foreach ($data['activity'] as $row) {
            $this->assertStringNotContainsString('Outsider secret', $row['detail']);
        }
    }

    public function test_admin_sees_the_org(): void
    {
        $data = $this->dashboard($this->admin);

        $this->assertSame('org', $data['scope']['tier']);
        $this->assertSame('Primepower · CRM org-wide', $data['scope']['label']);

        $this->assertEquals(5, $this->kpi($data, 'new-leads')['value']);
        $this->assertEquals(12_400_000, $this->kpi($data, 'pipeline')['value']);
        $this->assertEquals(1_500_000, $this->kpi($data, 'won')['value']);
        $this->assertEquals(66.7, $this->kpi($data, 'win-rate')['value']);
        $this->assertEquals(5, $this->kpi($data, 'clients')['value']);
        $this->assertEquals(5, $this->kpi($data, 'follow-ups')['value']);
        $this->assertEquals(4, $this->kpi($data, 'attention')['value']);
        $this->assertEquals(12_400_000, $data['donut']['total']);
        $this->assertCount(4, $data['alerts']);
    }

    public function test_activity_feed_is_scoped_to_what_the_caller_may_see(): void
    {
        $data = $this->dashboard($this->rep);

        $details = array_column($data['activity'], 'detail');

        $this->assertTrue(
            collect($details)->contains(fn (string $detail): bool => str_contains($detail, 'Called Grace')),
        );
        $this->assertFalse(
            collect($details)->contains(fn (string $detail): bool => str_contains($detail, 'Outsider secret')),
        );

        $note = collect($data['activity'])
            ->first(fn (array $row): bool => str_contains($row['detail'], 'Called Grace'));

        $this->assertNotNull($note);
        $this->assertSame('activity', $note['domain']);
        $this->assertSame('Note added', $note['title']);
        $this->assertSame('/leads/'.$this->repLead->id, $note['href']);
        $this->assertArrayHasKey('occurred_at', $note);
    }

    public function test_ai_report_is_rules_based_and_linked(): void
    {
        $data = $this->dashboard($this->rep);

        $this->assertSame('rules', $data['ai']['source']);
        $this->assertSame('Primepower rules v1', $data['ai']['model']);
        $this->assertCount(3, $data['ai']['insights']);
        $this->assertCount(4, $data['ai']['actions']);
        $this->assertStringContainsString('2 accounts need a human', $data['ai']['headline']);

        foreach ($data['ai']['insights'] as $insight) {
            $this->assertStringStartsWith('/', $insight['href']);
        }

        foreach ($data['ai']['actions'] as $action) {
            $this->assertStringStartsWith('/', $action['href']);
        }
    }

    public function test_period_toggle_resolves_manila_windows(): void
    {
        $last30 = $this->dashboard($this->rep, '30d');
        $quarter = $this->dashboard($this->rep, 'quarter');
        $ytd = $this->dashboard($this->rep, 'ytd');

        $this->assertSame('30d', $last30['range']);
        $this->assertCount(5, $last30['line']['labels']);
        $this->assertStringStartsWith('Weekly,', $last30['line']['caption']);

        $this->assertSame('quarter', $quarter['range']);
        $this->assertCount(4, $quarter['line']['labels']);
        $this->assertStringStartsWith('Monthly,', $quarter['line']['caption']);

        $this->assertSame('ytd', $ytd['range']);
        $this->assertCount(10, $ytd['line']['labels']);
        $this->assertStringContainsString('2026', $ytd['line']['caption']);
    }

    public function test_missing_range_defaults_and_an_unknown_range_is_rejected(): void
    {
        $this->actingAs($this->rep, 'api')
            ->getJson('/api/v1/dashboard')
            ->assertOk()
            ->assertJsonPath('data.range', '30d');

        $this->actingAs($this->rep, 'api')
            ->getJson('/api/v1/dashboard?range=all-time')
            ->assertUnprocessable();
    }

    public function test_dashboard_requires_authentication(): void
    {
        $this->getJson('/api/v1/dashboard')->assertUnauthorized();
    }

    /**
     * @return array<string, mixed>
     */
    private function dashboard(User $user, string $range = '30d'): array
    {
        return $this->actingAs($user, 'api')
            ->getJson('/api/v1/dashboard?range='.$range)
            ->assertOk()
            ->json('data');
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function kpi(array $data, string $id): array
    {
        return collect([...$data['primary'], ...$data['secondary']])
            ->firstWhere('id', $id);
    }

    private function leadFor(
        ?User $owner,
        string $companyName,
        LeadStatus $status,
        Carbon $createdAt,
        ?Carbon $statusChangedAt = null,
    ): Lead {
        $lead = Lead::factory()->create([
            'company_name' => $companyName,
            'owner_id' => $owner?->id,
            'status' => $status,
        ]);

        $lead->forceFill([
            'created_at' => $createdAt,
            'status_changed_at' => $statusChangedAt,
        ])->saveQuietly();

        return $lead;
    }
}
