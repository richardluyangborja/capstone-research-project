<?php

namespace Tests\Feature;

use App\Enums\ActivityStatus;
use App\Enums\ActivityType;
use App\Enums\CompanyStatus;
use App\Enums\ContractStatus;
use App\Enums\HandoffStatus;
use App\Enums\LeadStatus;
use App\Enums\OpportunityStage;
use App\Models\Activity;
use App\Models\Communication;
use App\Models\Company;
use App\Models\Contract;
use App\Models\Lead;
use App\Models\Opportunity;
use App\Models\Survey;
use App\Models\SurveyResponse;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The needs-attention worklist (module 06, spec 14 §Worklist, D2 phase 2).
 *
 * It proves every source is represented once, that resolved/future/normal records stay out,
 * and that the three roles see exactly their tier — a salesrep their own rows, a manager the
 * team's plus the shared queue and renewals, an admin everything.
 */
class DashboardWorklistTest extends TestCase
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

    private Company $atRiskCompany;

    private Company $failedHandoffCompany;

    private Lead $repLead;

    private Lead $teammateLead;

    private Lead $outsiderLead;

    private Lead $unassignedLead;

    private SurveyResponse $repResponse;

    private SurveyResponse $resolvedResponse;

    private SurveyResponse $outsiderResponse;

    private Activity $repTask;

    private Activity $futureTask;

    private Activity $teammateTask;

    private Activity $outsiderTask;

    private Communication $repMessage;

    private Communication $futureMessage;

    private Communication $outsiderMessage;

    private Opportunity $stalledDeal;

    private Contract $repContract;

    private Contract $draftContract;

    private Contract $outsiderContract;

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
        $this->atRiskCompany = Company::factory()->ownedBy($this->rep)->create([
            'name' => 'Metro Builders',
            'status' => CompanyStatus::AtRisk,
        ]);
        $this->failedHandoffCompany = Company::factory()->ownedBy($this->rep)->create([
            'name' => 'Greenfield Hotels',
            'status' => CompanyStatus::Onboarding,
            'handoff_status' => HandoffStatus::Failed,
        ]);

        $this->repLead = Lead::factory()->create([
            'company_name' => 'Sunrise Foods Inc',
            'owner_id' => $this->rep->id,
            'status' => LeadStatus::New,
        ]);
        $this->teammateLead = Lead::factory()->create([
            'company_name' => 'BPO Solutions PH',
            'owner_id' => $this->teammate->id,
            'status' => LeadStatus::New,
        ]);
        $this->outsiderLead = Lead::factory()->create([
            'company_name' => 'Outsider Foods',
            'owner_id' => $this->outsider->id,
            'status' => LeadStatus::New,
        ]);
        $this->unassignedLead = Lead::factory()->create([
            'company_name' => 'Unassigned Manufacturing',
            'owner_id' => null,
            'status' => LeadStatus::New,
        ]);
        $this->unassignedLead->forceFill(['created_at' => now()->subDays(3)])->saveQuietly();

        Lead::factory()->create([
            'company_name' => 'Fresh Unassigned',
            'owner_id' => null,
            'status' => LeadStatus::New,
        ]);
        $archived = Lead::factory()->create([
            'company_name' => 'Archived Unassigned',
            'owner_id' => null,
            'status' => LeadStatus::Archived,
        ]);
        $archived->forceFill(['created_at' => now()->subDays(3)])->saveQuietly();

        $this->repTask = $this->task($this->repLead, $this->rep, 'Send the revised rate sheet', now()->subDay());
        $this->futureTask = $this->task($this->repLead, $this->rep, 'Future task', now()->addDay());
        $this->teammateTask = $this->task($this->teammateLead, $this->teammate, 'Chase the signed contract', now()->subDays(2));
        $this->outsiderTask = $this->task($this->outsiderLead, $this->outsider, 'Outsider task', now()->subDay());

        $this->repMessage = Communication::factory()->about($this->repLead, $this->rep)
            ->scheduled(now()->subDay())
            ->create(['subject' => 'Rate-sheet follow-up']);
        $this->futureMessage = Communication::factory()->about($this->repLead, $this->rep)
            ->scheduled(now()->addDay())
            ->create(['subject' => 'Future message']);
        $this->outsiderMessage = Communication::factory()->about($this->outsiderLead, $this->outsider)
            ->scheduled(now()->subDay())
            ->create(['subject' => 'Outsider message']);

        $this->stalledDeal = Opportunity::factory()->forCompany($this->repCompany)->ownedBy($this->rep)
            ->stage(OpportunityStage::Discovery)->create([
                'name' => 'Sunrise commissary crew',
                'amount' => 1_250_000,
            ]);
        $this->stalledDeal->forceFill([
            'created_at' => now()->subDays(40),
            'stage_changed_at' => now()->subDays(30),
            'last_activity_at' => now()->subDays(30),
        ])->saveQuietly();

        Opportunity::factory()->forCompany($this->repCompany)->ownedBy($this->rep)
            ->stage(OpportunityStage::Negotiation)->create([
                'name' => 'Fresh deal',
                'amount' => 200_000,
            ]);

        $survey = Survey::factory()->create(['name' => 'Post-deployment check-in']);

        $this->repResponse = SurveyResponse::factory()->scored(2.0, 4)->create([
            'survey_id' => $survey->id,
            'company_id' => $this->repCompany->id,
            'submitted_at' => now()->subDays(3),
            'needs_attention' => true,
        ]);
        $this->resolvedResponse = SurveyResponse::factory()->scored(1.0, 2)->create([
            'survey_id' => $survey->id,
            'company_id' => $this->repCompany->id,
            'submitted_at' => now()->subDays(4),
            'needs_attention' => true,
        ]);
        $this->resolvedResponse->forceFill([
            'resolved_at' => now()->subDay(),
            'resolved_by' => $this->rep->id,
        ])->saveQuietly();
        $this->outsiderResponse = SurveyResponse::factory()->scored(2.0, 3)->create([
            'survey_id' => $survey->id,
            'company_id' => $this->outsiderCompany->id,
            'submitted_at' => now()->subDays(2),
            'needs_attention' => true,
        ]);

        $this->repContract = Contract::factory()->forCompany($this->repCompany)->signed()->create([
            'title' => 'Sunrise housekeeping agreement',
            'end_date' => now()->addDays(5)->toDateString(),
        ]);
        $this->draftContract = Contract::factory()->forCompany($this->repCompany)->create([
            'title' => 'Draft agreement',
            'status' => ContractStatus::Draft,
            'end_date' => now()->addDays(5)->toDateString(),
        ]);
        $this->outsiderContract = Contract::factory()->forCompany($this->outsiderCompany)->signed()->create([
            'title' => 'Outsider agreement',
            'end_date' => now()->addDays(3)->toDateString(),
        ]);
    }

    public function test_salesrep_sees_their_own_attention_rows_only(): void
    {
        $data = $this->worklist($this->rep);

        $this->assertSame(6, $data['meta']['total']);
        $this->assertCount(6, $data['data']);

        $ids = array_column($data['data'], 'id');

        $this->assertContains('survey:'.$this->repResponse->id, $ids);
        $this->assertContains('task:'.$this->repTask->id, $ids);
        $this->assertContains('message:'.$this->repMessage->id, $ids);
        $this->assertContains('company:'.$this->failedHandoffCompany->id.':handoff', $ids);
        $this->assertContains('company:'.$this->atRiskCompany->id.':risk', $ids);
        $this->assertContains('opportunity:'.$this->stalledDeal->id, $ids);

        $this->assertNotContains('survey:'.$this->resolvedResponse->id, $ids);
        $this->assertNotContains('task:'.$this->futureTask->id, $ids);
        $this->assertNotContains('message:'.$this->futureMessage->id, $ids);
        $this->assertNotContains('task:'.$this->teammateTask->id, $ids);
        $this->assertNotContains('task:'.$this->outsiderTask->id, $ids);
        $this->assertNotContains('message:'.$this->outsiderMessage->id, $ids);
        $this->assertNotContains('survey:'.$this->outsiderResponse->id, $ids);
        $this->assertNotContains('lead:'.$this->unassignedLead->id.':unassigned', $ids);
        $this->assertNotContains('contract:'.$this->repContract->id.':renewal', $ids);
        $this->assertNotContains('contract:'.$this->draftContract->id.':renewal', $ids);
        $this->assertNotContains('contract:'.$this->outsiderContract->id.':renewal', $ids);

        $survey = $this->row($data, 'survey:'.$this->repResponse->id);
        $this->assertSame('Sunrise Foods Inc', $survey['subject']);
        $this->assertSame('CSAT 2 · NPS 4 on the Post-deployment check-in', $survey['detail']);
        $this->assertSame('danger', $survey['due']['severity']);
        $this->assertSame($this->rep->id, $survey['owner']['id']);
        $this->assertSame('/satisfaction/responses/'.$this->repResponse->id, $survey['href']);

        $task = $this->row($data, 'task:'.$this->repTask->id);
        $this->assertSame('Sunrise Foods Inc — Send the revised rate sheet', $task['subject']);
        $this->assertSame('1 day overdue', $task['due']['label']);
        $this->assertSame('/follow-ups/'.$this->repTask->id, $task['href']);

        $deal = $this->row($data, 'opportunity:'.$this->stalledDeal->id);
        $this->assertStringContainsString('₱1.25M', $deal['detail']);
        $this->assertStringContainsString('30 days without a touch', $deal['detail']);
        $this->assertSame('Stalled', $deal['due']['label']);

        $this->assertSeverityOrdered($data);
    }

    public function test_manager_sees_the_team_shared_queue_and_renewals(): void
    {
        $data = $this->worklist($this->manager);

        $this->assertSame(9, $data['meta']['total']);

        $ids = array_column($data['data'], 'id');

        $this->assertContains('task:'.$this->teammateTask->id, $ids);
        $this->assertContains('lead:'.$this->unassignedLead->id.':unassigned', $ids);
        $this->assertContains('contract:'.$this->repContract->id.':renewal', $ids);

        $this->assertNotContains('task:'.$this->outsiderTask->id, $ids);
        $this->assertNotContains('message:'.$this->outsiderMessage->id, $ids);
        $this->assertNotContains('survey:'.$this->outsiderResponse->id, $ids);
        $this->assertNotContains('contract:'.$this->outsiderContract->id.':renewal', $ids);

        $unassigned = $this->row($data, 'lead:'.$this->unassignedLead->id.':unassigned');
        $this->assertNull($unassigned['owner']);
        $this->assertSame('team', $unassigned['minTier']);
        $this->assertSame('/leads/'.$this->unassignedLead->id, $unassigned['href']);
        $this->assertSame('Claim lead', $unassigned['action_label']);

        $contract = $this->row($data, 'contract:'.$this->repContract->id.':renewal');
        $this->assertSame('team', $contract['minTier']);
        $this->assertSame('danger', $contract['due']['severity']);
        $this->assertSame('/companies/'.$this->repCompany->id.'?tab=contracts', $contract['href']);

        $this->assertSeverityOrdered($data);
    }

    public function test_admin_sees_every_team(): void
    {
        $data = $this->worklist($this->admin);

        $this->assertSame(13, $data['meta']['total']);

        $ids = array_column($data['data'], 'id');

        $this->assertContains('task:'.$this->outsiderTask->id, $ids);
        $this->assertContains('message:'.$this->outsiderMessage->id, $ids);
        $this->assertContains('survey:'.$this->outsiderResponse->id, $ids);
        $this->assertContains('contract:'.$this->outsiderContract->id.':renewal', $ids);

        $this->assertSeverityOrdered($data);
    }

    public function test_rows_are_shaped_for_the_client(): void
    {
        $data = $this->worklist($this->rep);

        foreach ($data['data'] as $row) {
            $this->assertSame(
                ['id', 'kind', 'subject', 'detail', 'owner', 'due', 'href', 'action_label', 'minTier'],
                array_keys($row),
            );
            $this->assertArrayHasKey('label', $row['due']);
            $this->assertArrayHasKey('severity', $row['due']);
            $this->assertStringStartsWith('/', $row['href']);
        }

        $this->assertSame('30d', $data['meta']['range']);
    }

    public function test_worklist_requires_authentication_and_validates_the_range(): void
    {
        $this->getJson('/api/v1/dashboard/worklist')->assertUnauthorized();

        $this->actingAs($this->rep, 'api')
            ->getJson('/api/v1/dashboard/worklist?range=all-time')
            ->assertUnprocessable();
    }

    /**
     * @return array<string, mixed>
     */
    private function worklist(User $user, string $range = '30d'): array
    {
        return $this->actingAs($user, 'api')
            ->getJson('/api/v1/dashboard/worklist?range='.$range)
            ->assertOk()
            ->json();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function row(array $data, string $id): array
    {
        return collect($data['data'])->firstWhere('id', $id);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function assertSeverityOrdered(array $data): void
    {
        $rank = ['danger' => 0, 'warning' => 1, 'info' => 2];
        $seen = array_map(fn (array $row): int => $rank[$row['due']['severity']], $data['data']);
        $sorted = $seen;
        sort($sorted);

        $this->assertSame($sorted, $seen);
    }

    private function task(Lead $lead, User $owner, string $subject, Carbon $dueAt): Activity
    {
        return $lead->activities()->create([
            'type' => ActivityType::Task,
            'subject' => $subject,
            'body' => 'Task body',
            'status' => ActivityStatus::Open,
            'due_at' => $dueAt,
            'owner_id' => $owner->id,
        ]);
    }
}
