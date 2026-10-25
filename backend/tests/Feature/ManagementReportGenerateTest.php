<?php

namespace Tests\Feature;

use App\Enums\ActivityStatus;
use App\Enums\ActivityType;
use App\Enums\LeadStatus;
use App\Enums\OpportunityStage;
use App\Models\Communication;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Lead;
use App\Models\Opportunity;
use App\Models\SurveyResponse;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * The on-demand management report (module 07, spec 15, S2).
 *
 * The provider is always faked. These tests cover the cache, cooldown and quota controls, the
 * fallback contract, the fixed ID-to-route map and the privacy boundary: the outgoing context
 * never contains a name, email, phone, opportunity name or message body.
 */
class ManagementReportGenerateTest extends TestCase
{
    use RefreshDatabase;

    private User $rep;

    private Company $company;

    private Lead $lead;

    private Contact $contact;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-10-24 09:00:00', 'Asia/Manila'));

        Cache::flush();
        Http::preventStrayRequests();
        config([
            'ai.report.enabled' => true,
            'ai.report.service_url' => 'http://ai.test',
            'ai.report.service_token' => 'test-service-token',
            'ai.report.timeout' => 0.5,
            'ai.report.cache_ttl_minutes' => 15,
            'ai.report.refresh_cooldown_minutes' => 5,
            'ai.report.daily_limit' => 800,
            'ai.report.user_daily_limit' => 30,
        ]);

        $team = Team::factory()->create(['name' => 'Business Development']);
        $this->rep = User::factory()->salesrep()->create(['team_id' => $team->id]);

        $this->company = Company::factory()->client()->ownedBy($this->rep)->create([
            'name' => 'Aurora Confidential Holdings',
        ]);
        $this->contact = Contact::factory()->forCompany($this->company)->primary()->create([
            'name' => 'Jane Secret Doe',
            'email' => 'jane.secret@example.com',
            'phone' => '09171234567',
            'mobile' => '09987654321',
        ]);
        $this->lead = Lead::factory()->create([
            'company_name' => 'Nightingale Secret Lead',
            'owner_id' => $this->rep->id,
            'status' => LeadStatus::Qualified,
        ]);

        Opportunity::factory()->forCompany($this->company)->ownedBy($this->rep)
            ->stage(OpportunityStage::Negotiation)->create([
                'name' => 'Project Nightingale Secret',
                'amount' => 1_000_000,
            ]);
        Opportunity::factory()->forCompany($this->company)->ownedBy($this->rep)
            ->stage(OpportunityStage::Won)->create([
                'name' => 'Project Closed Secret',
                'amount' => 500_000,
                'actual_close_date' => now()->subDays(3)->toDateString(),
            ]);

        Communication::factory()->about($this->company, $this->rep)->create([
            'subject' => 'Secret rate-sheet subject',
            'body' => 'The confidential message body must never reach the provider.',
        ]);

        $this->lead->activities()->create([
            'type' => ActivityType::Task,
            'subject' => 'Secret task title',
            'body' => 'Secret task notes',
            'status' => ActivityStatus::Open,
            'due_at' => now()->subDay(),
            'owner_id' => $this->rep->id,
        ]);

        SurveyResponse::factory()->scored(2.0, 4)->create([
            'company_id' => $this->company->id,
            'submitted_at' => now()->subDays(2),
            'needs_attention' => true,
        ]);
    }

    public function test_generate_calls_the_ai_service_and_maps_ids_to_owned_routes(): void
    {
        Http::fake(['http://ai.test/reports/dashboard' => Http::response($this->providerReport())]);

        $response = $this->generate('executive');

        $response
            ->assertOk()
            ->assertJsonPath('data.variant', 'executive')
            ->assertJsonPath('data.title', 'Executive management report')
            ->assertJsonPath('data.source', 'model')
            ->assertJsonPath('data.model', 'openai/gpt-oss-20b')
            ->assertJsonPath('data.cached', false)
            ->assertJsonPath('data.fallback', null)
            ->assertJsonPath('meta.ai_available', true);

        $data = $response->json('data');

        $this->assertCount(3, $data['insights']);
        $this->assertCount(4, $data['actions']);
        $this->assertSame('/satisfaction/responses?attention=true', $data['insights'][0]['href']);
        $this->assertSame('/pipeline', $data['actions'][1]['href']);
        $this->assertSame('people', $data['insights'][0]['domain']);

        Http::assertSentCount(1);
        Http::assertSent(function (ClientRequest $request): bool {
            return $request->url() === 'http://ai.test/reports/dashboard'
                && $request->method() === 'POST'
                && $request->hasHeader('Authorization', 'Bearer test-service-token')
                && $request->data()['context']['variant'] === 'executive';
        });
    }

    public function test_the_outgoing_context_is_bounded_and_contains_no_record_data(): void
    {
        Http::fake(['http://ai.test/reports/dashboard' => Http::response($this->providerReport())]);

        $this->generate('executive')->assertOk();

        Http::assertSent(function (ClientRequest $request): bool {
            $body = $request->body();

            foreach ([
                'Aurora Confidential Holdings',
                'Jane Secret Doe',
                'jane.secret@example.com',
                '09171234567',
                '09987654321',
                'Nightingale Secret Lead',
                'Project Nightingale Secret',
                'Project Closed Secret',
                'Secret rate-sheet subject',
                'confidential message body',
                'Secret task title',
                'Secret task notes',
            ] as $secret) {
                $this->assertStringNotContainsString($secret, $body);
            }

            $context = $request->data()['context'];

            $this->assertLessThanOrEqual(5, count($context['line']['labels']));
            $this->assertLessThanOrEqual(5, count($context['pipeline']['stages']));
            $this->assertArrayNotHasKey('name', $context['scope']);
            $this->assertArrayNotHasKey('label', $context['kpis'][0]);
            $this->assertSame('personal', $context['scope']['tier']);
            $this->assertSame('30d', $context['period']['range']);

            return true;
        });
    }

    public function test_a_successful_report_is_cached_and_get_returns_it_without_a_provider_call(): void
    {
        Http::fake(['http://ai.test/reports/dashboard' => Http::response($this->providerReport())]);

        $this->generate('executive')->assertOk();
        Http::assertSentCount(1);

        $this->actingAs($this->rep, 'api')
            ->getJson('/api/v1/reports/management?variant=executive&range=30d')
            ->assertOk()
            ->assertJsonPath('data.source', 'model')
            ->assertJsonPath('data.cached', true)
            ->assertJsonPath('data.model', 'openai/gpt-oss-20b');

        Http::assertSentCount(1);
    }

    public function test_a_context_change_invalidates_the_cached_report(): void
    {
        Http::fake(['http://ai.test/reports/dashboard' => Http::response($this->providerReport())]);

        $this->generate('executive')->assertOk();

        Opportunity::factory()->forCompany($this->company)->ownedBy($this->rep)
            ->stage(OpportunityStage::Discovery)->create([
                'name' => 'A new secret deal',
                'amount' => 250_000,
            ]);

        $this->actingAs($this->rep, 'api')
            ->getJson('/api/v1/reports/management?variant=executive&range=30d')
            ->assertOk()
            ->assertJsonPath('data.source', 'rules')
            ->assertJsonPath('data.cached', false);

        Http::assertSentCount(1);
    }

    public function test_refresh_bypasses_the_cache_but_the_cooldown_blocks_a_second_try(): void
    {
        Http::fake(['http://ai.test/reports/dashboard' => Http::response($this->providerReport())]);

        $this->generate('executive')->assertOk();
        Http::assertSentCount(1);

        $this->actingAs($this->rep, 'api')
            ->postJson('/api/v1/reports/management/generate', [
                'variant' => 'executive',
                'range' => '30d',
                'refresh' => true,
            ])
            ->assertStatus(429)
            ->assertJsonPath('message', 'Try again in a few minutes.')
            ->assertJsonStructure(['retry_after_seconds']);

        Http::assertSentCount(1);

        $this->travel(6)->minutes();

        $this->actingAs($this->rep, 'api')
            ->postJson('/api/v1/reports/management/generate', [
                'variant' => 'executive',
                'range' => '30d',
                'refresh' => true,
            ])
            ->assertOk()
            ->assertJsonPath('data.source', 'model')
            ->assertJsonPath('data.cached', false);

        Http::assertSentCount(2);
    }

    public function test_disabled_switch_returns_rules_without_calling_the_provider(): void
    {
        config(['ai.report.enabled' => false]);

        $this->generate('client_health')
            ->assertOk()
            ->assertJsonPath('data.source', 'rules')
            ->assertJsonPath('data.fallback.reason', 'not_configured')
            ->assertJsonPath('meta.ai_available', false);

        Http::assertNothingSent();
    }

    public function test_missing_service_token_returns_rules_without_calling_the_provider(): void
    {
        config(['ai.report.service_token' => '']);

        $this->generate('executive')
            ->assertOk()
            ->assertJsonPath('data.source', 'rules')
            ->assertJsonPath('data.fallback.reason', 'not_configured');

        Http::assertNothingSent();
    }

    public function test_provider_rate_limit_falls_back_to_rules(): void
    {
        Http::fake(['*' => Http::response(['error' => 'rate limited'], 429)]);

        $this->generate('executive')
            ->assertOk()
            ->assertJsonPath('data.source', 'rules')
            ->assertJsonPath('data.fallback.reason', 'rate_limited');
    }

    public function test_provider_timeout_falls_back_to_rules(): void
    {
        Http::fake(fn () => throw new ConnectionException('cURL error 28: Operation timed out'));

        $this->generate('executive')
            ->assertOk()
            ->assertJsonPath('data.source', 'rules')
            ->assertJsonPath('data.fallback.reason', 'timeout');
    }

    public function test_missing_ai_service_configuration_falls_back_to_rules(): void
    {
        Http::fake(['*' => Http::response(['detail' => ['reason' => 'not_configured']], 503)]);

        $this->generate('executive')
            ->assertOk()
            ->assertJsonPath('data.source', 'rules')
            ->assertJsonPath('data.fallback.reason', 'not_configured');
    }

    public function test_provider_error_falls_back_to_rules(): void
    {
        Http::fake(['*' => Http::response(['error' => 'boom'], 500)]);

        $this->generate('executive')
            ->assertOk()
            ->assertJsonPath('data.source', 'rules')
            ->assertJsonPath('data.fallback.reason', 'provider_error');
    }

    public function test_unauthorized_ai_service_falls_back_to_rules(): void
    {
        Http::fake(['*' => Http::response(['detail' => ['reason' => 'unauthorized']], 401)]);

        $this->generate('executive')
            ->assertOk()
            ->assertJsonPath('data.source', 'rules')
            ->assertJsonPath('data.fallback.reason', 'provider_error');
    }

    public function test_invalid_provider_json_falls_back_to_rules(): void
    {
        Http::fake(['*' => Http::response([
            'headline' => 'Too short',
            'narrative' => 'No insights or actions here.',
            'insights' => [],
            'actions' => [],
            'model' => 'openai/gpt-oss-20b',
        ])]);

        $this->generate('executive')
            ->assertOk()
            ->assertJsonPath('data.source', 'rules')
            ->assertJsonPath('data.fallback.reason', 'invalid_response');
    }

    public function test_invented_or_duplicate_action_ids_fall_back_to_rules(): void
    {
        $payload = $this->providerReport();
        $payload['actions'][0]['id'] = 'invented_route';

        Http::fake(['*' => Http::response($payload)]);

        $this->generate('executive')
            ->assertOk()
            ->assertJsonPath('data.source', 'rules')
            ->assertJsonPath('data.fallback.reason', 'invalid_response');
    }

    public function test_a_url_in_the_narrative_falls_back_to_rules(): void
    {
        $payload = $this->providerReport();
        $payload['narrative'] = 'See https://example.com for the detail behind this report.';

        Http::fake(['*' => Http::response($payload)]);

        $this->generate('executive')
            ->assertOk()
            ->assertJsonPath('data.source', 'rules')
            ->assertJsonPath('data.fallback.reason', 'invalid_response');
    }

    public function test_per_user_daily_cap_falls_back_to_rules(): void
    {
        config([
            'ai.report.user_daily_limit' => 1,
            'ai.report.daily_limit' => 800,
        ]);
        Http::fake(['*' => Http::response($this->providerReport())]);

        $this->generate('executive')
            ->assertOk()
            ->assertJsonPath('data.source', 'model');

        $this->generate('pipeline')
            ->assertOk()
            ->assertJsonPath('data.source', 'rules')
            ->assertJsonPath('data.fallback.reason', 'rate_limited');

        Http::assertSentCount(1);
    }

    public function test_org_daily_cap_falls_back_to_rules(): void
    {
        config([
            'ai.report.user_daily_limit' => 30,
            'ai.report.daily_limit' => 1,
        ]);
        Http::fake(['*' => Http::response($this->providerReport())]);

        $this->generate('executive')
            ->assertOk()
            ->assertJsonPath('data.source', 'model');

        $this->generate('client_health')
            ->assertOk()
            ->assertJsonPath('data.source', 'rules')
            ->assertJsonPath('data.fallback.reason', 'rate_limited');

        Http::assertSentCount(1);
    }

    public function test_generate_requires_a_known_variant(): void
    {
        $this->actingAs($this->rep, 'api')
            ->postJson('/api/v1/reports/management/generate', ['range' => '30d'])
            ->assertUnprocessable();

        $this->actingAs($this->rep, 'api')
            ->postJson('/api/v1/reports/management/generate', [
                'variant' => 'team_performance',
                'range' => '30d',
            ])
            ->assertUnprocessable();

        Http::assertNothingSent();
    }

    private function generate(string $variant): TestResponse
    {
        return $this->actingAs($this->rep, 'api')
            ->postJson('/api/v1/reports/management/generate', [
                'variant' => $variant,
                'range' => '30d',
            ]);
    }

    /**
     * A provider payload the shape validator should accept for the executive variant.
     *
     * @return array<string, mixed>
     */
    private function providerReport(): array
    {
        return [
            'headline' => 'The book is at a 50% win rate with one callback due.',
            'narrative' => 'The team carries ₱1,000,000 open and closed ₱500,000 this period.',
            'insights' => [
                [
                    'id' => 'attention',
                    'title' => 'One account needs a callback',
                    'body' => 'An unhappy answer is waiting for a human.',
                ],
                [
                    'id' => 'pipeline',
                    'title' => 'Negotiation holds the value',
                    'body' => 'The open stage carries the period’s only open deal.',
                ],
                [
                    'id' => 'follow_ups',
                    'title' => 'One follow-up is owed',
                    'body' => 'The task is already past its due moment.',
                ],
            ],
            'actions' => [
                [
                    'id' => 'read_answers',
                    'label' => 'Read the unhappy answer',
                    'description' => 'Close the loop with a note.',
                ],
                [
                    'id' => 'open_board',
                    'label' => 'Open the pipeline board',
                    'description' => 'Review the open stage.',
                ],
                [
                    'id' => 'open_worklist',
                    'label' => 'Open the worklist',
                    'description' => 'Reschedule the overdue follow-up.',
                ],
                [
                    'id' => 'open_clients',
                    'label' => 'Review the client book',
                    'description' => 'Check the account lifecycle.',
                ],
            ],
            'model' => 'openai/gpt-oss-20b',
            'usage' => [
                'prompt_tokens' => 420,
                'completion_tokens' => 180,
                'total_tokens' => 600,
            ],
        ];
    }
}
