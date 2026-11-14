<?php

namespace Tests\Feature;

use App\Enums\AuditSeverity;
use App\Models\Setting;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EmployeeQuotaTest extends TestCase
{
    use RefreshDatabase;

    public function test_employee_directory_and_profile_resolve_user_quota_overrides_and_defaults(): void
    {
        $team = Team::create(['name' => 'Sales']);
        $manager = User::factory()->manager()->create(['team_id' => $team->id]);
        $rep = User::factory()->salesrep()->create(['team_id' => $team->id]);
        Setting::query()->create([
            'scope' => 'user', 'scope_id' => $rep->id, 'key' => 'crm.quota.monthly_php',
            'value' => 750000, 'updated_by' => $manager->id,
        ]);

        $response = $this->actingAs($manager, 'api')->getJson('/api/v1/employees')->assertOk();
        $repRow = collect($response->json('data'))->firstWhere('id', $rep->public_id);
        $this->assertSame(750000.0, (float) $repRow['quota']['monthly']);
        $this->assertSame(500000.0, (float) $repRow['quota']['quarterly']);
        $this->assertSame(0.0, (float) $repRow['quota_attainment']);
        $this->getJson("/api/v1/employees/{$rep->public_id}")
            ->assertOk()->assertJsonPath('data.quota.monthly', 750000);
    }

    public function test_admin_can_update_any_employee_quota_and_change_is_audited(): void
    {
        $employee = User::factory()->salesrep()->create();
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin, 'api')->patchJson("/api/v1/employees/{$employee->public_id}/quota", [
            'monthly_quota' => 750000, 'quarterly_quota' => 2250000,
        ])->assertOk()->assertJsonPath('data.monthly', 750000)->assertJsonPath('data.quarterly', 2250000);

        $this->assertDatabaseHas('settings', ['scope' => 'user', 'scope_id' => $employee->id, 'key' => 'crm.quota.monthly_php']);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'employee.quota_updated', 'severity' => AuditSeverity::Info->value,
            'actor_id' => $admin->id, 'subject_id' => $employee->id,
        ]);
    }

    public function test_manager_can_update_only_direct_salesrep_team_member_quota(): void
    {
        $team = Team::create(['name' => 'Sales']);
        $otherTeam = Team::create(['name' => 'Other']);
        $manager = User::factory()->manager()->create(['team_id' => $team->id]);
        $rep = User::factory()->salesrep()->create(['team_id' => $team->id]);
        $outsider = User::factory()->salesrep()->create(['team_id' => $otherTeam->id]);

        $this->actingAs($manager, 'api')->patchJson("/api/v1/employees/{$rep->public_id}/quota", ['monthly_quota' => 800000])->assertOk();
        $this->patchJson("/api/v1/employees/{$outsider->public_id}/quota", ['monthly_quota' => 800000])->assertForbidden();
        $this->assertDatabaseMissing('settings', ['scope_id' => $outsider->id, 'key' => 'crm.quota.monthly_php']);
    }

    public function test_salesrep_cannot_update_quota_and_values_are_validated(): void
    {
        $rep = User::factory()->salesrep()->create();
        $other = User::factory()->salesrep()->create();
        $this->actingAs($rep, 'api')->patchJson("/api/v1/employees/{$other->public_id}/quota", ['monthly_quota' => 500000])->assertForbidden();
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin, 'api')->patchJson("/api/v1/employees/{$rep->public_id}/quota", ['monthly_quota' => -1])->assertUnprocessable();
        $this->patchJson("/api/v1/employees/{$rep->public_id}/quota", ['notes' => 'unexpected'])->assertUnprocessable();
    }

    public function test_resetting_quota_to_default_removes_user_override(): void
    {
        $employee = User::factory()->salesrep()->create();
        $admin = User::factory()->admin()->create();
        Setting::query()->create(['scope' => 'user', 'scope_id' => $employee->id, 'key' => 'crm.quota.monthly_php', 'value' => 700000, 'updated_by' => $admin->id]);

        $this->actingAs($admin, 'api')->patchJson("/api/v1/employees/{$employee->public_id}/quota", ['monthly_quota' => 500000])
            ->assertOk()->assertJsonPath('data.monthly', 500000);
        $this->assertDatabaseMissing('settings', ['scope' => 'user', 'scope_id' => $employee->id, 'key' => 'crm.quota.monthly_php']);
    }
}
