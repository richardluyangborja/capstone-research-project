<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminDiagnosticsTest extends TestCase
{
    use RefreshDatabase;

    public function test_both_endpoints_require_authentication(): void
    {
        $this->getJson('/api/v1/admin/diagnostics/health')->assertUnauthorized();
        $this->getJson('/api/v1/admin/diagnostics/summary')->assertUnauthorized();
    }

    public function test_salesreps_and_managers_are_rejected_from_both_endpoints(): void
    {
        $salesrep = User::factory()->salesrep()->create();
        $manager = User::factory()->manager()->create();

        $this->actingAs($salesrep, 'api')
            ->getJson('/api/v1/admin/diagnostics/health')
            ->assertForbidden();
        $this->actingAs($salesrep, 'api')
            ->getJson('/api/v1/admin/diagnostics/summary')
            ->assertForbidden();

        $this->actingAs($manager, 'api')
            ->getJson('/api/v1/admin/diagnostics/health')
            ->assertForbidden();
        $this->actingAs($manager, 'api')
            ->getJson('/api/v1/admin/diagnostics/summary')
            ->assertForbidden();
    }

    public function test_an_admin_and_a_superadmin_may_read_both_endpoints(): void
    {
        $admin = User::factory()->admin()->create();
        $superadmin = User::factory()->superadmin()->create();

        $this->actingAs($admin, 'api')
            ->getJson('/api/v1/admin/diagnostics/health')
            ->assertOk()
            ->assertJsonPath('meta.platform', false);

        $this->actingAs($superadmin, 'api')
            ->getJson('/api/v1/admin/diagnostics/health')
            ->assertOk()
            ->assertJsonPath('meta.platform', true);

        $this->actingAs($admin, 'api')
            ->getJson('/api/v1/admin/diagnostics/summary')
            ->assertOk();

        $this->actingAs($superadmin, 'api')
            ->getJson('/api/v1/admin/diagnostics/summary')
            ->assertOk();
    }

    public function test_health_returns_the_application_checks_with_exactly_one_status_each(): void
    {
        $admin = User::factory()->admin()->create();

        $checks = $this->actingAs($admin, 'api')
            ->getJson('/api/v1/admin/diagnostics/health')
            ->assertOk()
            ->json('data.checks');

        $this->assertNotEmpty($checks);
        foreach ($checks as $check) {
            $this->assertArrayHasKey('key', $check);
            $this->assertArrayHasKey('label', $check);
            $this->assertArrayHasKey('status', $check);
            $this->assertArrayHasKey('summary', $check);
            $this->assertArrayHasKey('checked_at', $check);
            $this->assertArrayHasKey('remediation', $check);
            $this->assertArrayHasKey('source', $check);
        }
    }

    public function test_an_admin_gets_no_platform_checks_but_a_superadmin_does(): void
    {
        $admin = User::factory()->admin()->create();
        $superadmin = User::factory()->superadmin()->create();

        $adminKeys = collect(
            $this->actingAs($admin, 'api')
                ->getJson('/api/v1/admin/diagnostics/health')
                ->assertOk()
                ->assertJsonPath('meta.platform', false)
                ->json('data.checks'),
        )->pluck('key')->all();

        $this->assertNotContains('queue', $adminKeys);
        $this->assertNotContains('schema', $adminKeys);

        $superKeys = collect(
            $this->actingAs($superadmin, 'api')
                ->getJson('/api/v1/admin/diagnostics/health')
                ->assertOk()
                ->assertJsonPath('meta.platform', true)
                ->json('data.checks'),
        )->pluck('key')->all();

        $this->assertContains('queue', $superKeys);
        $this->assertContains('schema', $superKeys);
    }

    public function test_checks_have_exactly_one_valid_status(): void
    {
        $admin = User::factory()->admin()->create();

        $checks = $this->actingAs($admin, 'api')
            ->getJson('/api/v1/admin/diagnostics/health')
            ->assertOk()
            ->json('data.checks');

        $valid = ['healthy', 'degraded', 'unavailable', 'not_configured'];
        foreach ($checks as $check) {
            $this->assertContains($check['status'], $valid, "Check {$check['key']} has an invalid status.");
        }
    }
}
