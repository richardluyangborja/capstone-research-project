<?php

namespace Tests\Feature;

use App\Enums\AuditSeverity;
use App\Enums\CommunicationChannel;
use App\Enums\CommunicationDirection;
use App\Enums\CommunicationStatus;
use App\Models\AuditLog;
use App\Models\Communication;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Integration and operational health (module 08 slice S6, spec 19 §S6, ADR-0030).
 *
 * The contract under test: both endpoints are admin+ only; an application admin never receives
 * the platform block and never counts a security event; every check carries exactly one of the
 * four statuses; mocked feeds are labelled rather than claimed live; the AI probe is bounded and
 * degrades to a stable unavailable check; nothing secret, path-like or exception-shaped leaks;
 * and every count degrades to an explicit state when an optional dependency is absent.
 */
class AdminOperationsTest extends TestCase
{
    use RefreshDatabase;

    private const STATUSES = ['healthy', 'degraded', 'unavailable', 'not_configured'];

    private const APPLICATION_KEYS = [
        'database', 'storage', 'email', 'ai', 'hr', 'core2_employees', 'recruitment',
        'deployment', 'timekeeping', 'notifications', 'application',
    ];

    // --- Role boundaries -----------------------------------------------------

    public function test_both_endpoints_require_authentication(): void
    {
        $this->getJson('/api/v1/admin/operations/health')->assertUnauthorized();
        $this->getJson('/api/v1/admin/operations/summary')->assertUnauthorized();
    }

    public function test_salesreps_and_managers_are_rejected_from_both_endpoints(): void
    {
        foreach ([User::factory()->salesrep()->create(), User::factory()->manager()->create()] as $user) {
            $this->actingAs($user, 'api')
                ->getJson('/api/v1/admin/operations/health')
                ->assertForbidden()
                ->assertJsonPath('message', 'This action is unauthorized.');

            $this->actingAs($user, 'api')
                ->getJson('/api/v1/admin/operations/summary')
                ->assertForbidden()
                ->assertJsonPath('message', 'This action is unauthorized.');
        }
    }

    public function test_an_admin_and_a_superadmin_may_read_both_endpoints(): void
    {
        foreach ([User::factory()->admin()->create(), User::factory()->superadmin()->create()] as $user) {
            $this->actingAs($user, 'api')
                ->getJson('/api/v1/admin/operations/health')
                ->assertOk()
                ->assertJsonPath('meta.read_only', true);

            $this->actingAs($user, 'api')
                ->getJson('/api/v1/admin/operations/summary')
                ->assertOk();
        }
    }

    // --- Health checks -------------------------------------------------------

    public function test_health_returns_the_application_checks_with_exactly_one_status_each(): void
    {
        $checks = $this->actingAs(User::factory()->admin()->create(), 'api')
            ->getJson('/api/v1/admin/operations/health')
            ->assertOk()
            ->json('data.checks');

        $this->assertSame(
            self::APPLICATION_KEYS,
            array_values(array_intersect(collect($checks)->pluck('key')->all(), self::APPLICATION_KEYS)),
        );

        // One additional application-plane check reports the retired Service handoff (ADR-0027).
        $this->assertContains('service_handoff', collect($checks)->pluck('key')->all());

        foreach ($checks as $check) {
            $this->assertContains($check['status'], self::STATUSES, "Check {$check['key']} has a bad status.");
            $this->assertContains($check['source'], ['application', 'mocked', 'external']);
            $this->assertNotEmpty($check['label']);
            $this->assertNotEmpty($check['summary']);
            $this->assertNotEmpty($check['checked_at']);
            $this->assertArrayHasKey('remediation', $check);
        }
    }

    public function test_an_admin_gets_no_platform_checks_but_a_superadmin_does(): void
    {
        $adminKeys = collect(
            $this->actingAs(User::factory()->admin()->create(), 'api')
                ->getJson('/api/v1/admin/operations/health')
                ->assertOk()
                ->assertJsonPath('meta.platform', false)
                ->json('data.checks'),
        )->pluck('key')->all();

        $this->assertNotContains('queue', $adminKeys);
        $this->assertNotContains('schema', $adminKeys);

        $superKeys = collect(
            $this->actingAs(User::factory()->superadmin()->create(), 'api')
                ->getJson('/api/v1/admin/operations/health')
                ->assertOk()
                ->assertJsonPath('meta.platform', true)
                ->json('data.checks'),
        )->pluck('key')->all();

        $this->assertContains('queue', $superKeys);
        $this->assertContains('schema', $superKeys);
    }

    public function test_mocked_feeds_are_labelled_mocked_and_never_claimed_live(): void
    {
        $checks = collect(
            $this->actingAs(User::factory()->admin()->create(), 'api')
                ->getJson('/api/v1/admin/operations/health')
                ->json('data.checks'),
        )->keyBy('key');

        foreach (['hr', 'core2_employees', 'recruitment', 'deployment', 'timekeeping'] as $key) {
            $check = $checks[$key];
            $this->assertSame('mocked', $check['source'], "{$key} should be marked as a mocked source.");
            $this->assertSame('degraded', $check['status']);
            $this->assertStringContainsString('mocked', $check['summary']);
            $this->assertNotNull($check['remediation']);
        }

        // A mocked mailbox that accepts sends is healthy: the failure is what degrades it, not
        // the mock itself (spec 19 §Health-check contract, email).
        $email = $checks['email'];
        $this->assertSame('mocked', $email['source']);
        $this->assertSame('healthy', $email['status']);
        $this->assertStringContainsString('mocked', $email['summary']);
    }

    public function test_the_ai_probe_is_healthy_when_the_service_answers_its_health_endpoint(): void
    {
        config([
            'ai.report.enabled' => true,
            'ai.report.service_url' => 'http://ai.test:8001',
            'ai.report.service_token' => 'shared-token',
        ]);

        Http::fake(['ai.test:8001/health' => Http::response(['status' => 'ok'], 200)]);

        $check = collect(
            $this->actingAs(User::factory()->admin()->create(), 'api')
                ->getJson('/api/v1/admin/operations/health')
                ->assertOk()
                ->json('data.checks'),
        )->firstWhere('key', 'ai');

        $this->assertSame('healthy', $check['status']);
        $this->assertSame('external', $check['source']);

        Http::assertSent(fn ($request): bool => $request->url() === 'http://ai.test:8001/health');
    }

    public function test_a_timed_out_ai_probe_is_unavailable_without_leaking_the_service_details(): void
    {
        config([
            'ai.report.enabled' => true,
            'ai.report.service_url' => 'http://ai.test:8001',
            'ai.report.service_token' => 'shared-token',
        ]);

        Http::fake(fn () => throw new ConnectionException('Connection timed out after 2000ms'));

        $check = collect(
            $this->actingAs(User::factory()->admin()->create(), 'api')
                ->getJson('/api/v1/admin/operations/health')
                ->assertOk()
                ->json('data.checks'),
        )->firstWhere('key', 'ai');

        $this->assertSame('unavailable', $check['status']);
        $this->assertNotNull($check['remediation']);
        $this->assertStringNotContainsString('ai.test', $check['summary']);
        $this->assertStringNotContainsString('shared-token', $check['summary']);
        $this->assertStringNotContainsString('timed out after', $check['summary']);
    }

    public function test_a_non_success_ai_probe_reports_the_status_code_and_safe_guidance(): void
    {
        config([
            'ai.report.enabled' => true,
            'ai.report.service_url' => 'http://ai.test:8001',
            'ai.report.service_token' => 'shared-token',
        ]);

        Http::fake(['ai.test:8001/health' => Http::response(['error' => 'boom'], 503)]);

        $check = collect(
            $this->actingAs(User::factory()->admin()->create(), 'api')
                ->getJson('/api/v1/admin/operations/health')
                ->json('data.checks'),
        )->firstWhere('key', 'ai');

        $this->assertSame('unavailable', $check['status']);
        $this->assertStringContainsString('503', $check['summary']);
        $this->assertStringNotContainsString('boom', $check['summary']);
    }

    public function test_a_disabled_or_unconfigured_ai_service_is_not_configured_and_never_called(): void
    {
        Http::fake();

        config(['ai.report.enabled' => false]);
        $disabled = collect(
            $this->actingAs(User::factory()->admin()->create(), 'api')
                ->getJson('/api/v1/admin/operations/health')
                ->json('data.checks'),
        )->firstWhere('key', 'ai');
        $this->assertSame('not_configured', $disabled['status']);

        config(['ai.report.enabled' => true, 'ai.report.service_url' => '']);
        $noUrl = collect(
            $this->actingAs(User::factory()->admin()->create(), 'api')
                ->getJson('/api/v1/admin/operations/health')
                ->json('data.checks'),
        )->firstWhere('key', 'ai');
        $this->assertSame('not_configured', $noUrl['status']);

        Http::assertNothingSent();
    }

    public function test_a_simulated_email_failure_marks_the_email_check_degraded(): void
    {
        config(['crm.integrations.email.simulate_failure' => true]);

        $check = collect(
            $this->actingAs(User::factory()->admin()->create(), 'api')
                ->getJson('/api/v1/admin/operations/health')
                ->json('data.checks'),
        )->firstWhere('key', 'email');

        $this->assertSame('degraded', $check['status']);
        $this->assertStringContainsString('simulated failure', $check['summary']);
    }

    public function test_a_disabled_notification_channel_is_degraded_not_healthy(): void
    {
        config(['notifications.database_enabled' => false]);

        $check = collect(
            $this->actingAs(User::factory()->admin()->create(), 'api')
                ->getJson('/api/v1/admin/operations/health')
                ->json('data.checks'),
        )->firstWhere('key', 'notifications');

        $this->assertSame('degraded', $check['status']);
    }

    public function test_health_never_leaks_paths_credentials_or_exceptions(): void
    {
        config(['crm.integrations.email.simulate_failure' => true]);
        Http::fake(fn () => throw new ConnectionException('connect failed to 10.0.0.5 db-password'));

        $response = $this->actingAs(User::factory()->superadmin()->create(), 'api')
            ->getJson('/api/v1/admin/operations/health')
            ->assertOk();

        $body = $response->getContent();

        foreach (['password', 'storage/app', 'Authorization', 'access_token', 'DB_PASSWORD', 'stack'] as $needle) {
            $this->assertStringNotContainsStringIgnoringCase($needle, $body);
        }

        $this->assertStringNotContainsString((string) config('app.key'), $body);
    }

    // --- Summary -------------------------------------------------------------

    public function test_the_summary_carries_the_expected_blocks(): void
    {
        $data = $this->actingAs(User::factory()->admin()->create(), 'api')
            ->getJson('/api/v1/admin/operations/summary')
            ->assertOk()
            ->json('data');

        foreach ([
            'generated_at', 'accounts', 'administrative_activity', 'integrations',
            'failed_operations', 'maintenance', 'configuration_changes', 'platform',
        ] as $key) {
            $this->assertArrayHasKey($key, $data);
        }
    }

    public function test_accounts_are_counted_by_status(): void
    {
        User::factory()->admin()->create();
        User::factory()->salesrep()->create();
        User::factory()->salesrep()->create()->update([
            'status' => 'suspended',
            'suspended_at' => now(),
            'status_reason' => 'Testing',
        ]);

        $this->actingAs(User::factory()->admin()->create(), 'api')
            ->getJson('/api/v1/admin/operations/summary')
            ->assertOk()
            ->assertJsonPath('data.accounts.suspended', 1)
            ->assertJsonPath('data.accounts.active', 3);
    }

    public function test_an_admin_summary_excludes_security_events_but_a_superadmin_sees_them(): void
    {
        $admin = User::factory()->admin()->create();
        AuditLog::record('user.updated', AuditSeverity::Info, $admin->id);
        AuditLog::record('security.access_denied', AuditSeverity::Warning, $admin->id);
        AuditLog::record('auth.login_failed', AuditSeverity::Notice, null);

        $adminData = $this->actingAs($admin, 'api')
            ->getJson('/api/v1/admin/operations/summary')
            ->assertOk()
            ->json('data');

        $actions = collect($adminData['administrative_activity']['recent'])->pluck('action')->all();
        $this->assertContains('user.updated', $actions);
        $this->assertNotContains('security.access_denied', $actions);
        $this->assertNotContains('auth.login_failed', $actions);
        $this->assertSame(0, $adminData['administrative_activity']['severities']['warning']);
        $this->assertSame(1, $adminData['administrative_activity']['total']);

        $superData = $this->actingAs(User::factory()->superadmin()->create(), 'api')
            ->getJson('/api/v1/admin/operations/summary')
            ->assertOk()
            ->json('data');

        $superActions = collect($superData['administrative_activity']['recent'])->pluck('action')->all();
        $this->assertContains('security.access_denied', $superActions);
        $this->assertSame(3, $superData['administrative_activity']['total']);
        $this->assertSame(1, $superData['administrative_activity']['severities']['warning']);
        $this->assertSame(1, $superData['platform']['security_events']['warning']);
    }

    public function test_administrative_activity_carries_no_snapshots_or_pii(): void
    {
        $admin = User::factory()->admin()->create();
        AuditLog::record('user.updated', AuditSeverity::Info, $admin->id, User::class, $admin->id, [
            'email' => 'someone@example.test',
        ], ['email' => 'someone@example.test']);

        $recent = $this->actingAs($admin, 'api')
            ->getJson('/api/v1/admin/operations/summary')
            ->json('data.administrative_activity.recent');

        $this->assertNotEmpty($recent);

        foreach ($recent as $row) {
            $this->assertSame(['id', 'action', 'severity', 'actor', 'created_at'], array_keys($row));
            $this->assertStringNotContainsString('@', (string) json_encode($row));
        }
    }

    public function test_the_platform_block_is_null_for_an_admin_and_populated_for_a_superadmin(): void
    {
        $this->actingAs(User::factory()->admin()->create(), 'api')
            ->getJson('/api/v1/admin/operations/summary')
            ->assertOk()
            ->assertJsonPath('data.platform', null);

        $platform = $this->actingAs(User::factory()->superadmin()->create(), 'api')
            ->getJson('/api/v1/admin/operations/summary')
            ->assertOk()
            ->json('data.platform');

        $this->assertNotNull($platform);
        $this->assertArrayHasKey('application', $platform);
        $this->assertArrayHasKey('schema', $platform);
        $this->assertContains($platform['schema']['state'], ['current', 'pending', 'unavailable', 'not_configured']);
        $this->assertArrayHasKey('queue', $platform);
        $this->assertArrayHasKey('entity_totals', $platform);
        $this->assertArrayHasKey('security_events', $platform);
        $this->assertSame('current', $platform['schema']['state']);
    }

    public function test_entity_totals_are_allow_listed_counts_only(): void
    {
        Company::factory()->count(2)->create();

        $totals = $this->actingAs(User::factory()->superadmin()->create(), 'api')
            ->getJson('/api/v1/admin/operations/summary')
            ->json('data.platform.entity_totals');

        $this->assertSame(
            ['leads', 'accounts', 'contacts', 'opportunities', 'contracts', 'communications', 'users'],
            array_keys($totals),
        );
        $this->assertSame(2, $totals['accounts']);
        $this->assertIsInt($totals['users']);
    }

    public function test_failed_email_operations_are_counted_without_their_contents(): void
    {
        Communication::factory()->create([
            'channel' => CommunicationChannel::Email,
            'direction' => CommunicationDirection::Outbound,
            'status' => CommunicationStatus::Failed,
            'failed_reason' => 'Provider rejected this message for recipient jane@example.test.',
        ]);

        $data = $this->actingAs(User::factory()->admin()->create(), 'api')
            ->getJson('/api/v1/admin/operations/summary')
            ->json('data');

        $this->assertSame(1, $data['failed_operations']['failed_emails']);
        $this->assertTrue($data['failed_operations']['available']);
        $this->assertSame(1, $data['maintenance']['retryable_failed_emails']);
        $this->assertTrue($data['maintenance']['actions_available']);
        $this->assertStringNotContainsString('jane@example.test', json_encode($data));
        $this->assertStringNotContainsString('Provider rejected', json_encode($data));
    }

    public function test_configuration_changes_count_setting_events_in_the_last_seven_days(): void
    {
        $admin = User::factory()->admin()->create();
        AuditLog::record('setting.updated', AuditSeverity::Notice, $admin->id);
        AuditLog::record('setting.reset', AuditSeverity::Notice, $admin->id);

        $old = AuditLog::record('setting.updated', AuditSeverity::Notice, $admin->id);
        $old->forceFill(['created_at' => now()->subDays(30)])->saveQuietly();

        $this->actingAs($admin, 'api')
            ->getJson('/api/v1/admin/operations/summary')
            ->assertOk()
            ->assertJsonPath('data.configuration_changes.last_7_days', 2);
    }

    public function test_integration_counts_match_the_health_checks(): void
    {
        $this->actingAs(User::factory()->admin()->create(), 'api');

        $data = $this->actingAs(User::factory()->admin()->create(), 'api')
            ->getJson('/api/v1/admin/operations/summary')
            ->json('data');

        $counts = $data['integrations']['counts'];

        $this->assertSame(
            array_keys(array_fill_keys(self::STATUSES, 0)),
            array_keys($counts),
        );
        $this->assertSame($data['integrations']['total'], array_sum($counts));
        $this->assertSame(count(self::APPLICATION_KEYS) + 1, $data['integrations']['total']);
    }

    public function test_a_failed_probe_degrades_only_its_own_check(): void
    {
        // A closed database would break the whole page; instead prove a gateway failure is
        // contained: the AI probe fails while every other check still reports.
        config([
            'ai.report.enabled' => true,
            'ai.report.service_url' => 'http://ai.test:8001',
            'ai.report.service_token' => 'shared-token',
        ]);
        Http::fake(fn () => throw new ConnectionException('refused'));

        $checks = collect(
            $this->actingAs(User::factory()->admin()->create(), 'api')
                ->getJson('/api/v1/admin/operations/health')
                ->assertOk()
                ->json('data.checks'),
        )->keyBy('key');

        $this->assertSame('unavailable', $checks['ai']['status']);
        $this->assertSame('healthy', $checks['database']['status']);
        $this->assertCount(count(self::APPLICATION_KEYS) + 1, $checks);
    }
}
