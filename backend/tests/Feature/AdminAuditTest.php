<?php

namespace Tests\Feature;

use App\Enums\AuditSeverity;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\TestCase;

/**
 * The immutable audit book (module 08 slice S5, spec 19, ADR-0030).
 *
 * The contract under test: admin+ reads and exports, an application admin never sees a
 * security-sensitive event, filters are bounded, the detail masks PII and never returns a
 * secret, the export is bounded/audited/formula-safe, rows are append-only, and one
 * multi-step mutation shares a correlation id.
 */
class AdminAuditTest extends TestCase
{
    use RefreshDatabase;

    private function audit(
        string $action,
        AuditSeverity $severity = AuditSeverity::Info,
        ?User $actor = null,
        ?User $subject = null,
        ?array $before = null,
        ?array $after = null,
    ): AuditLog {
        return AuditLog::record(
            $action,
            $severity,
            $actor?->id,
            $subject === null ? null : User::class,
            $subject?->id,
            $before,
            $after,
        );
    }

    public function test_only_admin_roles_may_read_the_audit_book(): void
    {
        foreach ([User::factory()->salesrep()->create(), User::factory()->manager()->create()] as $actor) {
            $this->actingAs($actor, 'api')
                ->getJson('/api/v1/admin/audit-logs')
                ->assertForbidden()
                ->assertJsonPath('message', 'This action is unauthorized.');
        }

        $this->actingAs(User::factory()->admin()->create(), 'api')
            ->getJson('/api/v1/admin/audit-logs')
            ->assertOk();
    }

    public function test_an_admin_does_not_see_security_events_but_a_superadmin_does(): void
    {
        $admin = User::factory()->admin()->create();
        $this->audit('user.updated', AuditSeverity::Info, $admin, $admin);
        $this->audit('security.access_denied', AuditSeverity::Warning, $admin);
        $this->audit('auth.login_failed', AuditSeverity::Notice);

        $adminRows = $this->actingAs($admin, 'api')
            ->getJson('/api/v1/admin/audit-logs')
            ->assertOk()
            ->json('data');

        $this->assertSame(['user.updated'], collect($adminRows)->pluck('action')->all());

        $superRows = $this->actingAs(User::factory()->superadmin()->create(), 'api')
            ->getJson('/api/v1/admin/audit-logs')
            ->assertOk()
            ->json('data');

        $this->assertEqualsCanonicalizing(
            ['user.updated', 'security.access_denied', 'auth.login_failed'],
            collect($superRows)->pluck('action')->all(),
        );
    }

    public function test_an_admin_cannot_open_a_security_event_detail(): void
    {
        $admin = User::factory()->admin()->create();
        $log = $this->audit('security.access_denied', AuditSeverity::Warning, $admin);

        $this->actingAs($admin, 'api')
            ->getJson('/api/v1/admin/audit-logs/'.$log->id)
            ->assertForbidden();

        $this->actingAs(User::factory()->superadmin()->create(), 'api')
            ->getJson('/api/v1/admin/audit-logs/'.$log->id)
            ->assertOk()
            ->assertJsonPath('data.action', 'security.access_denied');
    }

    public function test_the_list_filters_by_action_severity_actor_resource_and_date(): void
    {
        $admin = User::factory()->admin()->create();
        $other = User::factory()->manager()->create();

        $this->audit('user.updated', AuditSeverity::Info, $admin, $other);
        $this->audit('user.suspended', AuditSeverity::Warning, $admin, $other);
        $this->audit('team.created', AuditSeverity::Info, $other);

        $this->actingAs($admin, 'api')
            ->getJson('/api/v1/admin/audit-logs?action=user.suspended')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.action', 'user.suspended');

        $this->actingAs($admin, 'api')
            ->getJson('/api/v1/admin/audit-logs?severity=warning')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->actingAs($admin, 'api')
            ->getJson('/api/v1/admin/audit-logs?actor='.$other->public_id)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.action', 'team.created');

        $this->actingAs($admin, 'api')
            ->getJson('/api/v1/admin/audit-logs?resource=User')
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $this->actingAs($admin, 'api')
            ->getJson('/api/v1/admin/audit-logs?from='.now()->addDay()->toDateString())
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_the_list_filters_by_correlation_id(): void
    {
        $admin = User::factory()->admin()->create();
        $log = $this->audit('user.updated', AuditSeverity::Info, $admin, $admin);

        $this->actingAs($admin, 'api')
            ->getJson('/api/v1/admin/audit-logs?request_id='.$log->request_id)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $log->id);
    }

    public function test_the_list_is_paginated_and_bounded(): void
    {
        $admin = User::factory()->admin()->create();

        foreach (range(1, 3) as $ignored) {
            $this->audit('user.updated', AuditSeverity::Info, $admin, $admin);
        }

        $this->actingAs($admin, 'api')
            ->getJson('/api/v1/admin/audit-logs?per_page=2')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.per_page', 2)
            ->assertJsonPath('meta.total', 3);

        $this->actingAs($admin, 'api')
            ->getJson('/api/v1/admin/audit-logs?per_page=500')
            ->assertOk()
            ->assertJsonPath('meta.per_page', 100);
    }

    public function test_the_detail_redacts_secrets_and_masks_pii(): void
    {
        $admin = User::factory()->admin()->create(['email' => 'ada@primepower.test']);
        $log = $this->audit('user.updated', AuditSeverity::Info, $admin, $admin, null, [
            'name' => 'Ada Admin',
            'email' => 'ada@primepower.test',
            'phone' => '+63 917 123 4567',
            'password' => 'secret',
            'token' => 'abc',
        ]);

        $response = $this->actingAs($admin, 'api')
            ->getJson('/api/v1/admin/audit-logs/'.$log->id)
            ->assertOk();

        $after = $response->json('data.after');
        $this->assertSame('a***@primepower.test', $after['email']);
        $this->assertSame('***4567', $after['phone']);
        $this->assertArrayNotHasKey('password', $after);
        $this->assertArrayNotHasKey('token', $after);
        $this->assertSame('a***@primepower.test', $response->json('data.actor.email'));
        $this->assertSame($admin->public_id, $response->json('data.subject.id'));
    }

    public function test_the_export_is_bounded_audited_and_formula_safe(): void
    {
        $actor = User::factory()->admin()->create(['name' => '=cmd|calc']);
        $this->audit('user.updated', AuditSeverity::Info, $actor, $actor, null, [
            'email' => 'ada@primepower.test',
        ]);
        $this->audit('security.access_denied', AuditSeverity::Warning, $actor);

        $response = $this->actingAs($actor, 'api')
            ->get('/api/v1/admin/audit-logs/export')
            ->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8');

        $csv = $response->streamedContent();
        $this->assertStringContainsString("'=cmd|calc", $csv);
        $this->assertStringContainsString('a***@primepower.test', $csv);
        $this->assertStringNotContainsString('security.access_denied', $csv);

        $this->assertDatabaseHas(AuditLog::class, [
            'action' => 'audit.exported',
            'severity' => AuditSeverity::Notice->value,
        ]);
    }

    public function test_the_export_rejects_an_unbounded_date_range(): void
    {
        $this->actingAs(User::factory()->admin()->create(), 'api')
            ->getJson('/api/v1/admin/audit-logs/export?from=2020-01-01&to=2026-01-01')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('from');
    }

    public function test_the_export_requires_an_admin(): void
    {
        $this->actingAs(User::factory()->salesrep()->create(), 'api')
            ->getJson('/api/v1/admin/audit-logs/export')
            ->assertForbidden();
    }

    public function test_audit_rows_are_append_only(): void
    {
        $log = $this->audit('user.updated', AuditSeverity::Info, User::factory()->admin()->create());

        try {
            $log->update(['action' => 'tampered']);
            $this->fail('An audit row must not be updated.');
        } catch (LogicException $exception) {
            $this->assertSame('Audit rows are append-only.', $exception->getMessage());
        }

        try {
            $log->delete();
            $this->fail('An audit row must not be deleted.');
        } catch (LogicException $exception) {
            $this->assertSame('Audit rows are append-only.', $exception->getMessage());
        }
    }

    public function test_a_multi_step_mutation_shares_one_correlation_id(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin, 'api')
            ->patchJson('/api/v1/admin/settings', [
                'settings' => [
                    'crm.pipeline.stuck_days' => 40,
                    'crm.aging.stale_days' => 7,
                ],
            ])
            ->assertOk();

        $rows = AuditLog::query()->where('action', 'setting.updated')->get();

        $this->assertCount(2, $rows);
        $this->assertCount(1, $rows->pluck('request_id')->unique());
    }

    public function test_authentication_events_are_recorded(): void
    {
        $user = User::factory()->salesrep()->create();

        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'wrong'])
            ->assertUnauthorized();

        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'password'])
            ->assertOk();

        $this->assertDatabaseHas(AuditLog::class, ['action' => 'auth.login_failed']);
        $this->assertDatabaseHas(AuditLog::class, ['action' => 'auth.login_succeeded']);
    }
}
