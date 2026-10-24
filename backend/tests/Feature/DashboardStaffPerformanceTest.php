<?php

namespace Tests\Feature;

use App\Enums\ActivityStatus;
use App\Enums\ActivityType;
use App\Enums\LeadStatus;
use App\Enums\OpportunityStage;
use App\Models\Activity;
use App\Models\Company;
use App\Models\Lead;
use App\Models\Opportunity;
use App\Models\Survey;
use App\Models\SurveyInvitation;
use App\Models\SurveyResponse;
use App\Models\Team;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The staff scorecard (module 06, spec 14 §Staff performance, D2 phase 3).
 *
 * It proves the CRM aggregates read the period correctly, that HR arrives through the mocked
 * `HrGateway` (and disappears cleanly when core2 is down), that a salesrep/manager/admin see
 * exactly their tier, and that a won-value cell reconciles with module 02's deal book.
 */
class DashboardStaffPerformanceTest extends TestCase
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

    private Survey $survey;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-10-24 09:00:00', 'Asia/Manila'));

        $this->team = Team::factory()->create(['name' => 'Business Development']);
        $otherTeam = Team::factory()->create(['name' => 'Other Team']);

        $this->manager = User::factory()->manager()->create([
            'name' => 'Maria Manager',
            'team_id' => $this->team->id,
        ]);
        $this->rep = User::factory()->salesrep()->create([
            'name' => 'Sam Salesrep',
            'team_id' => $this->team->id,
        ]);
        $this->teammate = User::factory()->salesrep()->create([
            'name' => 'Liza Lopez',
            'team_id' => $this->team->id,
        ]);
        $this->outsider = User::factory()->salesrep()->create([
            'name' => 'Oscar Outsider',
            'team_id' => $otherTeam->id,
        ]);
        $this->admin = User::factory()->admin()->create(['name' => 'Ada Admin']);

        $this->repCompany = Company::factory()->client()->ownedBy($this->rep)->create(['name' => 'Sunrise Foods Inc']);
        $this->teammateCompany = Company::factory()->client()->ownedBy($this->teammate)->create(['name' => 'Harbor Logistics']);
        $this->outsiderCompany = Company::factory()->client()->ownedBy($this->outsider)->create(['name' => 'Outsider Foods']);

        $this->seedLeads();
        $this->seedDeals();
        $this->seedTasks();
        $this->seedSurveyAnswers();
    }

    public function test_salesrep_sees_only_their_own_scorecard(): void
    {
        $data = $this->staffPerformance($this->rep);

        $this->assertSame(1, count($data['data']));
        $this->assertSame(['period' => '30d', 'hr_available' => true], $data['meta']);

        $row = $data['data'][0];

        $this->assertSame([
            'user', 'leads_handled', 'qualified', 'deals_won', 'won_value',
            'conversion_rate', 'follow_up_on_time', 'csat', 'hr', 'minTier',
        ], array_keys($row));

        $this->assertSame([
            'id' => $this->rep->id,
            'name' => 'Sam Salesrep',
            'job_title' => 'Sales representative',
            'team' => 'Business Development',
        ], $row['user']);

        $this->assertSame(2, $row['leads_handled']);
        $this->assertSame(1, $row['qualified']);
        $this->assertSame(1, $row['deals_won']);
        $this->assertEquals(1_000_000.0, $row['won_value']);
        $this->assertEquals(50.0, $row['conversion_rate']);
        $this->assertEquals(33.3, $row['follow_up_on_time']);
        $this->assertEquals(4.5, $row['csat']);
        $this->assertSame('personal', $row['minTier']);

        $this->assertSame('core2', $row['hr']['source']);
        $this->assertSame(
            ['attendance_rate', 'leave_days', 'late_arrivals', 'source'],
            array_keys($row['hr']),
        );
        $this->assertGreaterThanOrEqual(92, $row['hr']['attendance_rate']);
        $this->assertLessThanOrEqual(99, $row['hr']['attendance_rate']);
        $this->assertGreaterThan(0, $row['hr']['leave_days']);
        $this->assertGreaterThanOrEqual(0, $row['hr']['late_arrivals']);
        $this->assertLessThanOrEqual(2, $row['hr']['late_arrivals']);
    }

    public function test_manager_sees_the_team_and_no_one_else(): void
    {
        $data = $this->staffPerformance($this->manager);

        $this->assertSame(3, count($data['data']));
        $this->assertSame(
            ['Maria Manager', 'Liza Lopez', 'Sam Salesrep'],
            array_column(array_column($data['data'], 'user'), 'name'),
        );

        $maria = $this->row($data, 'Maria Manager');
        $liza = $this->row($data, 'Liza Lopez');
        $sam = $this->row($data, 'Sam Salesrep');

        $this->assertSame('Sales manager', $maria['user']['job_title']);
        $this->assertSame('personal', $maria['minTier']);
        $this->assertSame(0, $maria['leads_handled']);
        $this->assertSame(0, $maria['deals_won']);
        $this->assertEquals(0.0, $maria['conversion_rate']);
        $this->assertEquals(100.0, $maria['follow_up_on_time']);
        $this->assertNull($maria['csat']);

        $this->assertSame('team', $liza['minTier']);
        $this->assertSame(1, $liza['leads_handled']);
        $this->assertSame(1, $liza['qualified']);
        $this->assertSame(1, $liza['deals_won']);
        $this->assertEquals(2_000_000.0, $liza['won_value']);
        $this->assertEquals(100.0, $liza['conversion_rate']);
        $this->assertEquals(100.0, $liza['follow_up_on_time']);
        $this->assertEquals(3.0, $liza['csat']);

        $this->assertSame('team', $sam['minTier']);
        $this->assertSame(1, $sam['deals_won']);

        $this->assertNotContains('Oscar Outsider', array_column(array_column($data['data'], 'user'), 'name'));
    }

    public function test_admin_sees_every_team(): void
    {
        $data = $this->staffPerformance($this->admin);

        $this->assertSame(4, count($data['data']));
        $this->assertSame(
            ['Maria Manager', 'Liza Lopez', 'Oscar Outsider', 'Sam Salesrep'],
            array_column(array_column($data['data'], 'user'), 'name'),
        );

        foreach ($data['data'] as $row) {
            $this->assertSame('org', $row['minTier']);
        }

        $outsider = $this->row($data, 'Oscar Outsider');
        $this->assertSame(1, $outsider['deals_won']);
        $this->assertEquals(4_000_000.0, $outsider['won_value']);
        $this->assertEquals(5.0, $outsider['csat']);
        $this->assertEquals(0.0, $outsider['follow_up_on_time']);

        $this->assertNotContains('Ada Admin', array_column(array_column($data['data'], 'user'), 'name'));
    }

    public function test_range_moves_the_crm_window_but_hr_stays_deterministic(): void
    {
        $month = $this->staffPerformance($this->rep);
        $quarter = $this->staffPerformance($this->rep, 'quarter');

        $this->assertSame('quarter', $quarter['meta']['period']);

        $monthly = $month['data'][0];
        $quarterly = $quarter['data'][0];

        $this->assertSame(2, $monthly['leads_handled']);
        $this->assertSame(3, $quarterly['leads_handled']);

        $this->assertSame(1, $monthly['deals_won']);
        $this->assertEquals(1_000_000.0, $monthly['won_value']);
        $this->assertEquals(50.0, $monthly['conversion_rate']);

        $this->assertSame(2, $quarterly['deals_won']);
        $this->assertEquals(3_000_000.0, $quarterly['won_value']);
        $this->assertEquals(66.7, $quarterly['conversion_rate']);

        $this->assertEquals(4.5, $monthly['csat']);
        $this->assertEquals(3.33, $quarterly['csat']);
        $this->assertEquals(33.3, $monthly['follow_up_on_time']);
        $this->assertEquals(50.0, $quarterly['follow_up_on_time']);

        $this->assertGreaterThanOrEqual(
            $monthly['hr']['leave_days'],
            $quarterly['hr']['leave_days'],
        );
    }

    public function test_won_value_reconciles_with_the_deal_book(): void
    {
        $scorecard = $this->row($this->staffPerformance($this->rep), 'Sam Salesrep');

        $book = $this->actingAs($this->rep, 'api')
            ->getJson('/api/v1/opportunities?filter[stage]=won&per_page=100')
            ->assertOk()
            ->json();

        $wonInPeriod = collect($book['data'])->filter(
            fn (array $deal): bool => $deal['actual_close_date'] >= '2026-09-25'
                && $deal['actual_close_date'] <= '2026-10-24',
        );

        $this->assertSame($scorecard['deals_won'], $wonInPeriod->count());
        $this->assertEquals($scorecard['won_value'], (float) $wonInPeriod->sum('amount'));
    }

    public function test_hr_failure_returns_null_figures_and_flags_the_meta(): void
    {
        Config::set('crm.integrations.hr.simulate_failure', true);

        $data = $this->staffPerformance($this->manager);

        $this->assertFalse($data['meta']['hr_available']);

        foreach ($data['data'] as $row) {
            $this->assertNull($row['hr']);
        }
    }

    public function test_hr_figures_are_deterministic(): void
    {
        $first = $this->staffPerformance($this->manager);
        $second = $this->staffPerformance($this->manager);

        $this->assertSame(
            array_map(fn (array $row): ?array => $row['hr'], $first['data']),
            array_map(fn (array $row): ?array => $row['hr'], $second['data']),
        );
    }

    public function test_staff_performance_requires_authentication_and_validates_the_range(): void
    {
        $this->getJson('/api/v1/dashboard/staff-performance')->assertUnauthorized();

        $this->actingAs($this->rep, 'api')
            ->getJson('/api/v1/dashboard/staff-performance?range=all-time')
            ->assertUnprocessable();
    }

    /**
     * @return array<string, mixed>
     */
    private function staffPerformance(User $user, string $range = '30d'): array
    {
        return $this->actingAs($user, 'api')
            ->getJson('/api/v1/dashboard/staff-performance?range='.$range)
            ->assertOk()
            ->json();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function row(array $data, string $name): array
    {
        return collect($data['data'])->firstWhere('user.name', $name);
    }

    private function seedLeads(): void
    {
        $this->lead($this->rep, '2026-10-01 10:00', LeadStatus::Qualified, '2026-10-03 10:00');
        $this->lead($this->rep, '2026-10-02 10:00', LeadStatus::Contacted);
        $this->lead($this->rep, '2026-09-01 10:00', LeadStatus::New);
        $this->lead($this->rep, '2026-10-04 10:00', LeadStatus::Archived);

        $this->lead($this->teammate, '2026-10-05 10:00', LeadStatus::Qualified, '2026-10-06 10:00');
        $this->lead($this->outsider, '2026-10-07 10:00', LeadStatus::Contacted);
    }

    private function seedDeals(): void
    {
        $this->deal($this->rep, $this->repCompany, OpportunityStage::Won, '2026-10-10', 1_000_000);
        $this->deal($this->rep, $this->repCompany, OpportunityStage::Lost, '2026-10-12', 500_000);
        $this->deal($this->rep, $this->repCompany, OpportunityStage::Won, '2026-09-01', 2_000_000);
        $this->deal($this->rep, $this->repCompany, OpportunityStage::Negotiation, null, 300_000);

        $this->deal($this->teammate, $this->teammateCompany, OpportunityStage::Won, '2026-10-15', 2_000_000);
        $this->deal($this->outsider, $this->outsiderCompany, OpportunityStage::Won, '2026-10-16', 4_000_000);
    }

    private function seedTasks(): void
    {
        $this->task($this->rep, '2026-10-10 09:00', ActivityStatus::Done, '2026-10-09 09:00');
        $this->task($this->rep, '2026-10-12 09:00', ActivityStatus::Done, '2026-10-13 09:00');
        $this->task($this->rep, '2026-10-14 09:00', ActivityStatus::Open);
        $this->task($this->rep, '2026-09-01 09:00', ActivityStatus::Done, '2026-08-31 09:00');

        $this->task($this->outsider, '2026-10-15 09:00', ActivityStatus::Done, '2026-10-16 09:00');
    }

    private function seedSurveyAnswers(): void
    {
        $this->survey = Survey::factory()->create([
            'name' => 'Post-deployment check-in',
            'created_by' => $this->admin->id,
        ]);

        $this->answer($this->repCompany, 4.0, '2026-10-05 10:00');
        $this->answer($this->repCompany, 5.0, '2026-10-06 10:00');
        $this->answer($this->repCompany, null, '2026-10-07 10:00');
        $this->answer($this->repCompany, 1.0, '2026-09-01 10:00');

        $this->answer($this->teammateCompany, 3.0, '2026-10-08 10:00');
        $this->answer($this->outsiderCompany, 5.0, '2026-10-09 10:00');
    }

    private function lead(
        User $owner,
        string $createdAt,
        LeadStatus $status,
        ?string $statusChangedAt = null,
    ): Lead {
        $lead = Lead::factory()->create([
            'company_name' => 'Lead '.$owner->id.' '.$createdAt,
            'owner_id' => $owner->id,
            'status' => $status,
        ]);

        $dates = ['created_at' => Carbon::parse($createdAt, 'Asia/Manila')];

        if ($statusChangedAt !== null) {
            $dates['status_changed_at'] = Carbon::parse($statusChangedAt, 'Asia/Manila');
        }

        $lead->forceFill($dates)->saveQuietly();

        return $lead;
    }

    private function deal(
        User $owner,
        Company $company,
        OpportunityStage $stage,
        ?string $closeDate,
        float $amount,
    ): Opportunity {
        return Opportunity::factory()
            ->forCompany($company)
            ->ownedBy($owner)
            ->stage($stage)
            ->create([
                'name' => $stage->value.' deal '.$owner->id,
                'amount' => $amount,
                'actual_close_date' => $closeDate,
            ]);
    }

    private function task(
        User $owner,
        string $dueAt,
        ActivityStatus $status,
        ?string $updatedAt = null,
    ): Activity {
        $task = Activity::query()->create([
            'type' => ActivityType::Task,
            'subject' => 'Follow up with '.$owner->name,
            'status' => $status,
            'due_at' => Carbon::parse($dueAt, 'Asia/Manila'),
            'owner_id' => $owner->id,
        ]);

        if ($updatedAt !== null) {
            DB::table('activities')
                ->where('id', $task->id)
                ->update(['updated_at' => Carbon::parse($updatedAt, 'Asia/Manila')]);
        }

        return $task->refresh();
    }

    private function answer(Company $company, ?float $csat, string $submittedAt): SurveyResponse
    {
        $invitation = SurveyInvitation::factory()->create([
            'survey_id' => $this->survey->id,
            'company_id' => $company->id,
            'created_by' => $this->admin->id,
        ]);

        return SurveyResponse::factory()->forInvitation($invitation)->scored($csat, null)->create([
            'submitted_at' => Carbon::parse($submittedAt, 'Asia/Manila'),
        ]);
    }
}
