<?php

namespace Tests\Feature;

use App\Enums\AuditSeverity;
use App\Models\Attachment;
use App\Models\AuditLog;
use App\Models\Communication;
use App\Models\Lead;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Superadmin platform diagnostics (module 08 slice S8, spec 19 §S8, ADR-0030).
 *
 * The contract under test: the route is superadmin-only; the payload is read-only, bounded
 * and built from fixed queries; entity totals are allow-listed; a failed subsystem is surfaced
 * explicitly rather than as zero; no record PII, file path, token or message body leaves the
 * response; and every successful access appends a `diagnostic.viewed` audit row.
 */
class PlatformDiagnosticsTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/v1/platform/diagnostics';

    public function test_the_diagnostic_endpoint_requires_authentication(): void
    {
        $this->getJson(self::URL)->assertUnauthorized();
    }

    public function test_salesreps_managers_and_application_admins_are_rejected(): void
    {
        foreach ([
            User::factory()->salesrep()->create(),
            User::factory()->manager()->create(),
            User::factory()->admin()->create(),
        ] as $user) {
            $this->actingAs($user, 'api')
                ->getJson(self::URL)
                ->assertForbidden();
        }

        $this->assertDatabaseMissing('audit_logs', ['action' => 'diagnostic.viewed']);
    }

    public function test_a_superadmin_receives_bounded_read_only_diagnostics(): void
    {
        $superadmin = User::factory()->superadmin()->create();
        Lead::factory()->count(3)->create();
        $communication = Communication::factory()->create([
            'subject' => 'Rate sheet follow-up',
            'body' => 'A private message body that diagnostics must not return.',
            'to_address' => 'client@example.test',
        ]);
        Attachment::factory()->create([
            'path' => 'private/attachments/customer-secret.pdf',
            'attachable_id' => $communication->id,
        ]);

        $response = $this->actingAs($superadmin, 'api')
            ->getJson(self::URL)
            ->assertOk()
            ->assertJsonPath('data.read_only', true)
            ->assertJsonPath('meta.read_only', true)
            ->assertJsonPath('meta.platform', true);

        $data = $response->json('data');

        $this->assertSame('testing', $data['application']['environment']);
        $this->assertArrayHasKey('runtime', $data['application']);
        $this->assertArrayHasKey('build', $data['application']);

        $this->assertContains($data['schema']['state'], ['available', 'degraded']);
        $this->assertSame(0, $data['schema']['pending']);
        $this->assertNotNull($data['schema']['latest']);

        $this->assertSame('available', $data['storage']['state']);
        $this->assertTrue($data['storage']['writable']);
        $this->assertSame(1, $data['storage']['attachments']['count']);
        $this->assertGreaterThan(0, $data['storage']['attachments']['bytes']);

        $this->assertSame('available', $data['failed_work']['queue']['state']);
        $this->assertSame(0, $data['failed_work']['queue']['count']);
        $this->assertSame('not_configured', $data['failed_work']['requests']['state']);

        $this->assertSame(Lead::query()->count(), $data['entity_totals']['counts']['leads']['count']);
        $this->assertArrayHasKey('follow_up_tasks', $data['entity_totals']['counts']);
        $this->assertArrayHasKey('attachments', $data['entity_totals']['counts']);
        $this->assertArrayHasKey('audit_logs', $data['entity_totals']['counts']);

        $capabilityKeys = array_column($data['capabilities'], 'key');
        $this->assertSame(
            ['database', 'cache', 'queue', 'session', 'email', 'storage', 'notifications', 'ai_reports', 'debug', 'maintenance'],
            $capabilityKeys,
        );
        $this->assertSame('available', $data['capabilities'][0]['state']);
    }

    public function test_every_diagnostic_access_is_audited(): void
    {
        $superadmin = User::factory()->superadmin()->create();

        $this->actingAs($superadmin, 'api')->getJson(self::URL)->assertOk();
        $this->actingAs($superadmin, 'api')->getJson(self::URL)->assertOk();

        $logs = AuditLog::query()
            ->where('action', 'diagnostic.viewed')
            ->where('actor_id', $superadmin->id)
            ->get();

        $this->assertCount(2, $logs);

        $log = $logs->first();
        $this->assertSame(AuditSeverity::Notice, $log->severity);
        $this->assertNull($log->subject_id);
        $this->assertTrue($log->after['read_only']);
        $this->assertContains('schema', $log->after['sections']);
        $this->assertArrayHasKey('storage', $log->after['states']);
    }

    public function test_the_response_never_returns_record_pii_bodies_or_private_paths(): void
    {
        $superadmin = User::factory()->superadmin()->create();
        $communication = Communication::factory()->create([
            'subject' => 'Rate sheet follow-up',
            'body' => 'Secret customer message body.',
            'to_address' => 'client@example.test',
        ]);
        Attachment::factory()->create([
            'path' => 'private/attachments/customer-secret.pdf',
            'attachable_id' => $communication->id,
        ]);

        $content = $this->actingAs($superadmin, 'api')
            ->getJson(self::URL)
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('Secret customer message body.', $content);
        $this->assertStringNotContainsString('client@example.test', $content);
        $this->assertStringNotContainsString('private/attachments/customer-secret.pdf', $content);
        $this->assertStringNotContainsString('password', $content);
        $this->assertStringNotContainsString('payload', $content);
        $this->assertStringNotContainsString('exception', $content);
    }

    public function test_an_unavailable_subsystem_is_explicit_instead_of_reporting_zero(): void
    {
        Schema::drop('attachments');

        $data = $this->actingAs(User::factory()->superadmin()->create(), 'api')
            ->getJson(self::URL)
            ->assertOk()
            ->json('data');

        $this->assertSame('unavailable', $data['storage']['attachments']['state']);
        $this->assertNull($data['storage']['attachments']['count']);
        $this->assertSame('unavailable', $data['storage']['state']);

        $this->assertSame('unavailable', $data['entity_totals']['counts']['attachments']['state']);
        $this->assertNull($data['entity_totals']['counts']['attachments']['count']);
        $this->assertSame('partial', $data['entity_totals']['state']);
    }
}
