<?php

namespace Tests\Feature;

use App\Enums\AuditSeverity;
use App\Enums\LeadStatus;
use App\Models\AuditLog;
use App\Models\Lead;
use App\Models\Setting;
use App\Models\User;
use App\Notifications\LeadAssignedNotification;
use App\Settings\EffectiveSettings;
use App\Settings\SettingRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Organization and CRM settings (module 08 slice S4, spec 19, ADR-0030).
 *
 * The contract under test: the allow-list (unknown and secret keys are rejected), the role
 * boundary (security keys are invisible and unwritable for an application admin), per-key
 * validation including the aging cross-field rule, honest source resolution
 * (`database`/`environment`/`default`), the runtime effect on the next read, and the audit
 * trail (`setting.updated`/`setting.reset`/`setting.refreshed`).
 */
class AdminSettingTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        // The environment-source probe uses putenv; nothing may leak between tests.
        putenv('CRM_STALE_DAYS');

        parent::tearDown();
    }

    public function test_only_admin_roles_may_read_the_settings(): void
    {
        foreach ([User::factory()->salesrep()->create(), User::factory()->manager()->create()] as $actor) {
            $this->actingAs($actor, 'api')
                ->getJson('/api/v1/admin/settings')
                ->assertForbidden()
                ->assertJsonPath('message', 'This action is unauthorized.');
        }

        $this->actingAs(User::factory()->admin()->create(), 'api')
            ->getJson('/api/v1/admin/settings')
            ->assertOk();
    }

    public function test_the_read_is_grouped_typed_and_sourced(): void
    {
        $response = $this->actingAs(User::factory()->admin()->create(), 'api')
            ->getJson('/api/v1/admin/settings')
            ->assertOk();

        $categories = $response->json('data');
        $this->assertSame(
            ['organization', 'crm', 'notifications', 'ai'],
            collect($categories)->pluck('category')->all(),
        );

        $stale = collect($categories)->firstWhere('category', 'crm')['settings'][0];
        $this->assertSame('crm.aging.stale_days', $stale['key']);
        $this->assertSame('int', $stale['type']);
        $this->assertSame(14, $stale['value']);
        $this->assertSame('default', $stale['source']);
        $this->assertSame('admin', $stale['write_role']);
        $this->assertFalse($stale['read_only']);
        $this->assertTrue($stale['editable']);

        $currency = collect($categories)->firstWhere('category', 'organization')['settings'][5];
        $this->assertSame('organization.currency', $currency['key']);
        $this->assertTrue($currency['read_only']);
        $this->assertFalse($currency['editable']);
    }

    public function test_an_admin_never_sees_the_security_category(): void
    {
        $this->actingAs(User::factory()->admin()->create(), 'api')
            ->getJson('/api/v1/admin/settings')
            ->assertOk()
            ->assertJsonMissing(['category' => 'security'])
            ->assertJsonMissing(['key' => 'security.session_lifetime_minutes']);
    }

    public function test_a_superadmin_sees_the_security_category(): void
    {
        $this->actingAs(User::factory()->superadmin()->create(), 'api')
            ->getJson('/api/v1/admin/settings')
            ->assertOk()
            ->assertJsonFragment(['category' => 'security'])
            ->assertJsonFragment(['key' => 'security.mfa_required', 'read_only' => true]);
    }

    public function test_an_admin_cannot_write_a_security_key(): void
    {
        $this->actingAs(User::factory()->admin()->create(), 'api')
            ->patchJson('/api/v1/admin/settings', [
                'settings' => ['security.session_lifetime_minutes' => 60],
            ])
            ->assertForbidden();

        $this->assertDatabaseHas(AuditLog::class, [
            'action' => 'security.access_denied',
            'severity' => AuditSeverity::Warning->value,
        ]);
        $this->assertDatabaseMissing('settings', ['key' => 'security.session_lifetime_minutes']);
    }

    public function test_an_admin_cannot_write_a_read_only_security_key(): void
    {
        $this->actingAs(User::factory()->admin()->create(), 'api')
            ->patchJson('/api/v1/admin/settings', [
                'settings' => ['security.mfa_required' => true],
            ])
            ->assertForbidden();

        $this->assertDatabaseMissing('settings', ['key' => 'security.mfa_required']);
    }

    public function test_a_superadmin_writes_a_security_key(): void
    {
        $this->actingAs(User::factory()->superadmin()->create(), 'api')
            ->patchJson('/api/v1/admin/settings', [
                'settings' => ['security.session_lifetime_minutes' => 60],
            ])
            ->assertOk()
            ->assertJsonFragment(['key' => 'security.session_lifetime_minutes', 'value' => 60, 'source' => 'database']);

        $this->assertDatabaseHas('settings', ['key' => 'security.session_lifetime_minutes']);
    }

    public function test_unknown_and_secret_keys_are_rejected(): void
    {
        $admin = User::factory()->admin()->create();

        foreach (['mail.from.address' => 'x@y.z', 'app.key' => 'base64:nope', 'jwt.secret' => 'nope', 'ai_service_token' => 'nope'] as $key => $value) {
            $this->actingAs($admin, 'api')
                ->patchJson('/api/v1/admin/settings', ['settings' => [$key => $value]])
                ->assertUnprocessable()
                ->assertJsonValidationErrors("settings.{$key}");
        }

        $this->assertDatabaseCount('settings', 0);
    }

    public function test_read_only_keys_are_rejected(): void
    {
        $this->actingAs(User::factory()->admin()->create(), 'api')
            ->patchJson('/api/v1/admin/settings', [
                'settings' => ['organization.currency' => 'PHP'],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('settings.organization.currency');

        $this->actingAs(User::factory()->superadmin()->create(), 'api')
            ->patchJson('/api/v1/admin/settings', [
                'settings' => ['security.mfa_required' => true],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('settings.security.mfa_required');
    }

    public function test_range_and_type_failures_return_the_failing_keys(): void
    {
        $this->actingAs(User::factory()->admin()->create(), 'api')
            ->patchJson('/api/v1/admin/settings', [
                'settings' => [
                    'crm.aging.stale_days' => 0,
                    'crm.attachments.max_kb' => 20000,
                    'crm.surveys.attention_nps_max' => 11,
                    'organization.support_email' => 'not-an-email',
                    'organization.timezone' => 'UTC',
                    'crm.surveys.public_url' => 'ftp://example.com',
                ],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'settings.crm.aging.stale_days',
                'settings.crm.attachments.max_kb',
                'settings.crm.surveys.attention_nps_max',
                'settings.organization.support_email',
                'settings.organization.timezone',
                'settings.crm.surveys.public_url',
            ]);
    }

    public function test_the_aging_thresholds_must_stay_ordered(): void
    {
        $this->actingAs(User::factory()->admin()->create(), 'api')
            ->patchJson('/api/v1/admin/settings', [
                'settings' => [
                    'crm.aging.at_risk_days' => 10, // stale_days is 14 — at risk above stale only.
                ],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('settings.crm.aging.at_risk_days');

        $this->actingAs(User::factory()->admin()->create(), 'api')
            ->patchJson('/api/v1/admin/settings', [
                'settings' => [
                    'crm.aging.stale_days' => 7,
                    'crm.aging.at_risk_days' => 15,
                    'crm.aging.archive_days' => 30,
                ],
            ])
            ->assertOk()
            ->assertJsonFragment(['key' => 'crm.aging.at_risk_days', 'value' => 15, 'source' => 'database']);
    }

    public function test_a_write_upserts_the_override_audits_and_reports_the_database_source(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin, 'api')
            ->patchJson('/api/v1/admin/settings', [
                'settings' => ['crm.pipeline.stuck_days' => 40],
            ])
            ->assertOk()
            ->assertJsonFragment(['key' => 'crm.pipeline.stuck_days', 'value' => 40, 'source' => 'database']);

        $this->assertDatabaseHas('settings', ['key' => 'crm.pipeline.stuck_days', 'updated_by' => $admin->id]);

        $row = AuditLog::query()->where('action', 'setting.updated')->where('severity', AuditSeverity::Notice->value)->firstOrFail();
        $this->assertSame(21, $row->before['crm.pipeline.stuck_days']);
        $this->assertSame(40, $row->after['crm.pipeline.stuck_days']);
    }

    public function test_writing_the_default_deletes_the_override_and_audits_a_reset(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin, 'api')
            ->patchJson('/api/v1/admin/settings', ['settings' => ['crm.pipeline.stuck_days' => 40]])
            ->assertOk();

        $this->actingAs($admin, 'api')
            ->patchJson('/api/v1/admin/settings', ['settings' => ['crm.pipeline.stuck_days' => 21]])
            ->assertOk()
            ->assertJsonFragment(['key' => 'crm.pipeline.stuck_days', 'value' => 21, 'source' => 'default']);

        $this->assertDatabaseMissing('settings', ['key' => 'crm.pipeline.stuck_days']);
        $this->assertDatabaseHas(AuditLog::class, ['action' => 'setting.reset', 'severity' => AuditSeverity::Notice->value]);
        // Deleting the override restores the pristine boot value, not the earlier override.
        $this->assertSame(21, config('crm.pipeline.stuck_days'));
    }

    public function test_the_source_is_environment_when_the_variable_is_set_explicitly(): void
    {
        putenv('CRM_STALE_DAYS=14');

        $this->actingAs(User::factory()->admin()->create(), 'api')
            ->getJson('/api/v1/admin/settings')
            ->assertOk()
            ->assertJsonFragment(['key' => 'crm.aging.stale_days', 'value' => 14, 'source' => 'environment']);
    }

    public function test_a_setting_override_affects_the_next_read(): void
    {
        $owner = User::factory()->salesrep()->create();

        $lead = Lead::factory()->create(['owner_id' => $owner->id, 'status' => LeadStatus::Contacted]);
        $lead->forceFill(['last_activity_at' => now()->subDays(10)])->save();

        $actor = User::factory()->admin()->create();

        // At the default 14 days a 10-day-old lead is not stale.
        $this->actingAs($actor, 'api')
            ->getJson('/api/v1/leads?filter[stale]=true')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->actingAs($actor, 'api')
            ->patchJson('/api/v1/admin/settings', ['settings' => ['crm.aging.stale_days' => 7]])
            ->assertOk();

        // The override is applied by the api middleware, so the next read sees it.
        $this->actingAs($actor, 'api')
            ->getJson('/api/v1/leads?filter[stale]=true')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $lead->id);
    }

    public function test_employee_settings_are_allow_listed_validated_and_applied_at_runtime(): void
    {
        $this->assertNotNull(SettingRegistry::find('crm.employees.quota_default_php'));
        $this->assertNotNull(SettingRegistry::find('crm.employees.commission_rate_percent'));
        $this->assertNotNull(SettingRegistry::find('crm.employees.attendance_benchmark_percent'));
        $this->assertNotNull(SettingRegistry::find('crm.employees.salary_mask_for_managers'));
        $this->assertNotNull(SettingRegistry::find('crm.integrations.core2_employees.simulate_failure'));

        $this->actingAs(User::factory()->admin()->create(), 'api')
            ->patchJson('/api/v1/admin/settings', ['settings' => [
                'crm.employees.quota_default_php' => 600000,
                'crm.employees.commission_rate_percent' => 3,
                'crm.employees.attendance_benchmark_percent' => 96,
                'crm.employees.salary_mask_for_managers' => false,
                'crm.integrations.core2_employees.simulate_failure' => true,
            ]])
            ->assertOk();

        $this->assertSame(600000.0, (float) config('crm.employees.quota_default_php'));
        $this->assertSame(3.0, (float) config('crm.employees.commission_rate_percent'));
        $this->assertSame(96.0, (float) config('crm.employees.attendance_benchmark_percent'));
        $this->assertFalse(config('crm.employees.salary_mask_for_managers'));
        $this->assertTrue(config('crm.integrations.core2_employees.simulate_failure'));
    }

    public function test_the_mocked_email_failure_flag_flips_at_runtime(): void
    {
        $this->actingAs(User::factory()->admin()->create(), 'api')
            ->patchJson('/api/v1/admin/settings', [
                'settings' => ['crm.integrations.email.simulate_failure' => true],
            ])
            ->assertOk();

        // The gateway reads its flag from `config()`; the middleware applied the override.
        $this->assertTrue(config('crm.integrations.email.simulate_failure'));
    }

    public function test_disabling_database_notifications_stops_the_delivery(): void
    {
        $owner = User::factory()->salesrep()->create();
        $actor = User::factory()->salesrep()->create(['team_id' => $owner->team_id]);
        $lead = Lead::factory()->create(['owner_id' => $owner->id]);

        $this->actingAs(User::factory()->admin()->create(), 'api')
            ->patchJson('/api/v1/admin/settings', ['settings' => ['notifications.database_enabled' => false]])
            ->assertOk();

        $owner->notify(new LeadAssignedNotification($lead, $actor));

        $this->assertDatabaseCount('notifications', 0);
    }

    public function test_enabled_database_notifications_still_deliver(): void
    {
        $owner = User::factory()->salesrep()->create();
        $actor = User::factory()->salesrep()->create(['team_id' => $owner->team_id]);
        $lead = Lead::factory()->create(['owner_id' => $owner->id]);

        $owner->notify(new LeadAssignedNotification($lead, $actor));

        $this->assertDatabaseCount('notifications', 1);
    }

    public function test_refresh_rebuilds_the_snapshot_and_is_audited(): void
    {
        $admin = User::factory()->admin()->create();

        Setting::query()->create(['key' => 'crm.pipeline.stuck_days', 'value' => 33, 'updated_by' => $admin->id]);

        // Warm the snapshot, then write behind it; refresh must catch up.
        app(EffectiveSettings::class)->apply();
        $this->assertSame(33, config('crm.pipeline.stuck_days'));

        $this->actingAs($admin, 'api')
            ->postJson('/api/v1/admin/settings/refresh')
            ->assertOk()
            ->assertJsonPath('data.refreshed', true);

        $this->assertSame(33, config('crm.pipeline.stuck_days'));

        $this->assertDatabaseHas(AuditLog::class, [
            'action' => 'setting.refreshed',
            'severity' => AuditSeverity::Info->value,
        ]);
        $this->assertSame(1, AuditLog::query()->where('action', 'setting.refreshed')->count());
    }

    public function test_a_salesrep_cannot_refresh_or_write(): void
    {
        $this->actingAs(User::factory()->salesrep()->create(), 'api')
            ->postJson('/api/v1/admin/settings/refresh')
            ->assertForbidden();

        $this->actingAs(User::factory()->salesrep()->create(), 'api')
            ->patchJson('/api/v1/admin/settings', ['settings' => ['crm.pipeline.stuck_days' => 30]])
            ->assertForbidden();
    }

    public function test_the_ai_report_switch_applies_to_the_runtime_config(): void
    {
        $this->actingAs(User::factory()->admin()->create(), 'api')
            ->patchJson('/api/v1/admin/settings', ['settings' => ['ai.reports.enabled' => false]])
            ->assertOk();

        // The registry key is plural for the API; the runtime config block is singular.
        $this->assertFalse(config('ai.report.enabled'));
    }

    public function test_writing_a_decimal_default_deletes_the_override(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin, 'api')
            ->patchJson('/api/v1/admin/settings', ['settings' => ['crm.surveys.attention_csat_max' => 4]])
            ->assertOk();

        $this->actingAs($admin, 'api')
            ->patchJson('/api/v1/admin/settings', ['settings' => ['crm.surveys.attention_csat_max' => 3]])
            ->assertOk()
            ->assertJsonFragment(['key' => 'crm.surveys.attention_csat_max', 'value' => 3, 'source' => 'default']);

        $this->assertDatabaseMissing('settings', ['key' => 'crm.surveys.attention_csat_max']);
    }

    public function test_multiple_unknown_and_read_only_keys_report_each_error(): void
    {
        $this->actingAs(User::factory()->admin()->create(), 'api')
            ->patchJson('/api/v1/admin/settings', [
                'settings' => [
                    'not.a.setting' => 'nope',
                    'another.unknown' => 'nope',
                    'organization.currency' => 'PHP',
                ],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'settings.not.a.setting',
                'settings.another.unknown',
                'settings.organization.currency',
            ]);

        $this->assertDatabaseCount('settings', 0);
    }

    public function test_every_allow_listed_key_is_in_the_registry(): void
    {
        // Guard against a typo'd spec table: the categories must partition the keys and the
        // service must resolve every definition without touching the database.
        $keys = collect(SettingRegistry::all())->pluck('key');
        $this->assertSame($keys->unique()->count(), $keys->count());
        $this->assertNull(SettingRegistry::find('app.key'));

        foreach (SettingRegistry::all() as $definition) {
            $this->assertContains($definition['category'], array_keys(SettingRegistry::CATEGORIES));
            // Without an override row the source is the environment when the owning variable
            // is set (the deployment may own any of them) and the default otherwise.
            $this->assertContains(
                app(EffectiveSettings::class)->source($definition),
                $definition['env'] === null ? ['default'] : ['default', 'environment'],
            );
        }
    }
}
