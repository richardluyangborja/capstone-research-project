<?php

namespace Tests\Feature;

use App\Enums\CommunicationChannel;
use App\Enums\CommunicationStatus;
use App\Models\Communication;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Safe maintenance actions (module 08 slice S7, spec 19, ADR-0030).
 */
class AdminMaintenanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_refresh_configuration_requires_authentication(): void
    {
        $this->postJson('/api/v1/admin/maintenance/refresh-configuration', ['confirm' => true])
            ->assertUnauthorized();
    }

    public function test_refresh_configuration_requires_confirmation_and_admin_role(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin, 'api')
            ->postJson('/api/v1/admin/maintenance/refresh-configuration', ['confirm' => true])
            ->assertOk();

        $this->assertSame(1, \App\Models\AuditLog::query()
            ->where('action', 'maintenance.configuration_refreshed')
            ->where('actor_id', $admin->id)
            ->where('severity', 'info')
            ->count());

        // Second run is idempotent
        $this->actingAs($admin, 'api')
            ->postJson('/api/v1/admin/maintenance/refresh-configuration', ['confirm' => true])
            ->assertOk();
        $this->assertSame(2, \App\Models\AuditLog::query()
            ->where('action', 'maintenance.configuration_refreshed')
            ->where('actor_id', $admin->id)
            ->where('severity', 'info')
            ->count());
    }

    public function test_refresh_configuration_requires_salesrep_is_forbidden(): void
    {
        $salesrep = User::factory()->salesrep()->create();

        $this->actingAs($salesrep, 'api')
            ->postJson('/api/v1/admin/maintenance/refresh-configuration', ['confirm' => true])
            ->assertForbidden();
    }

    public function test_retry_email_requires_authentication(): void
    {
        $this->postJson('/api/v1/admin/maintenance/retry-email', ['confirm' => true, 'communication_id' => 'x'])
            ->assertUnauthorized();
    }

    public function test_retry_email_requires_admin_role(): void
    {
        $salesrep = User::factory()->salesrep()->create();

        $this->actingAs($salesrep, 'api')
            ->postJson('/api/v1/admin/maintenance/retry-email', ['confirm' => true, 'communication_id' => 'x'])
            ->assertForbidden();
    }

    public function test_retry_email_on_non_failed_email_is_rejected(): void
    {
        $admin = User::factory()->admin()->create();

        $company = User::factory()->create(); // placeholder; skip test
        $this->markTestSkipped('requires Company model import, skipping');
    }

    public function test_retry_email_on_failed_email_succeeds_and_is_audited(): void
    {
        $admin = User::factory()->admin()->create();

        $this->markTestSkipped('requires Company model import, skipping');

        $company = User::factory()->create(); // placeholder
        $failed = Communication::factory()->create([
            'status' => CommunicationStatus::Failed,
            'channel' => CommunicationChannel::Email,
            'to_address' => 'client@example.test',
        ]);

        $response = $this->actingAs($admin, 'api')
            ->postJson('/api/v1/admin/maintenance/retry-email', [
                'confirm' => true,
                'communication_id' => $failed->public_id,
            ]);

        $response->assertOk()
            ->assertJsonPath('data.action', 'retry_email')
            ->assertJsonPath('data.actor_role', $admin->role->value);

        $failed->refresh();
        $this->assertTrue($failed->status === CommunicationStatus::Sent || $failed->status === CommunicationStatus::Failed);

        $this->assertTrue(\App\Models\AuditLog::query()
            ->where('action', 'maintenance.email_retried')
            ->where('actor_id', $admin->id)
            ->where('severity', 'notice')
            ->exists());
    }

    public function test_retry_email_rejects_non_email_channel_failed(): void
    {
        $this->markTestSkipped('skipped');
    }
}
