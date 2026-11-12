<?php

namespace Tests\Feature;

use App\Enums\CommunicationStatus;
use App\Enums\UserStatus;
use App\Models\AuditLog;
use App\Models\Communication;
use App\Models\Setting;
use App\Models\Team;
use App\Models\User;
use Database\Seeders\DemoAdministrationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class DemoAdministrationSeedTest extends TestCase
{
    use RefreshDatabase;

    public function test_demo_seed_supports_repeatable_admin_and_platform_flows(): void
    {
        $this->seed();
        $seededAuditCount = AuditLog::query()->count();
        $this->seed(DemoAdministrationSeeder::class);

        $this->assertSame($seededAuditCount, AuditLog::query()->count());
        $this->assertGreaterThanOrEqual(2, Team::query()->count());
        $this->assertTrue(Communication::query()
            ->where('channel', 'email')
            ->where('status', CommunicationStatus::Failed)
            ->exists());

        $this->assertSame('EMP-2026-0042', User::query()->where('email', 'manager@primepower.test')->value('core2_employee_id'));
        $this->assertSame('EMP-2026-0018', User::query()->where('email', 'salesrep@primepower.test')->value('core2_employee_id'));
        $this->assertSame('EMP-2026-0027', User::query()->where('email', 'salesrep2@primepower.test')->value('core2_employee_id'));

        foreach ([
            'manager@primepower.test',
            'salesrep@primepower.test',
            'salesrep2@primepower.test',
            'admin@primepower.test',
            'superadmin@primepower.test',
        ] as $email) {
            $user = User::query()->where('email', $email)->firstOrFail();
            $this->assertTrue(Hash::check('password', $user->password), "{$email} should retain the demo password.");
            $this->postJson('/api/v1/auth/login', ['email' => $email, 'password' => 'password'])->assertOk();
        }

        $suspended = User::query()->where('email', 'suspended@primepower.test')->firstOrFail();
        $this->assertSame(UserStatus::Suspended, $suspended->status);
        $this->assertTrue(Hash::check('password', $suspended->password));
        $this->postJson('/api/v1/auth/login', [
            'email' => $suspended->email,
            'password' => 'password',
        ])->assertUnauthorized();

        foreach (['crm.pipeline.stuck_days', 'notifications.database_enabled', 'ai.reports.enabled'] as $key) {
            $this->assertTrue(Setting::query()->where('key', $key)->exists(), "Expected seeded setting {$key}.");
        }

        $this->assertDatabaseHas(AuditLog::class, [
            'action' => 'user.suspended',
            'actor_id' => User::query()->where('email', 'admin@primepower.test')->value('id'),
            'subject_id' => $suspended->id,
        ]);
        $this->assertDatabaseHas(AuditLog::class, ['action' => 'setting.updated']);

        $admin = User::query()->where('email', 'admin@primepower.test')->firstOrFail();
        $adminAudit = $this->actingAs($admin, 'api')->getJson('/api/v1/admin/audit-logs')->assertOk();
        $this->assertContains('user.suspended', collect($adminAudit->json('data'))->pluck('action')->all());
        $settingsResponse = $this->actingAs($admin, 'api')->getJson('/api/v1/admin/settings')->assertOk();
        $pipelineSettings = collect($settingsResponse->json('data'))->firstWhere('category', 'crm')['settings'];
        $this->assertSame(28, collect($pipelineSettings)->firstWhere('key', 'crm.pipeline.stuck_days')['value']);

        $superadmin = User::query()->where('email', 'superadmin@primepower.test')->firstOrFail();
        $this->actingAs($superadmin, 'api')->getJson('/api/v1/platform/diagnostics')->assertOk();
    }
}
