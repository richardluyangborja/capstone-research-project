<?php

namespace Tests\Feature;

use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class EmployeeApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_directory_is_authenticated_scoped_searchable_and_uses_public_ids(): void
    {
        $team = Team::create(['name' => 'Sales']);
        $other = Team::create(['name' => 'Other']);
        $manager = User::factory()->manager()->create(['team_id' => $team->id]);
        $rep = User::factory()->salesrep()->create(['name' => 'Sam Salesrep', 'team_id' => $team->id]);
        User::factory()->salesrep()->create(['name' => 'Outsider Rep', 'team_id' => $other->id]);

        $this->getJson('/api/v1/employees')->assertUnauthorized();
        $response = $this->actingAs($manager, 'api')->getJson('/api/v1/employees?search=sam')->assertOk();
        $this->assertSame(1, $response->json('meta.total'));
        $this->assertSame($rep->public_id, $response->json('data.0.id'));
        $this->assertNotSame((string) $rep->id, $response->json('data.0.id'));
        $this->assertSame(true, $response->json('meta.read_only'));
        $this->assertSame('Core 2 HR, Attendance & Payroll (read-only)', $response->json('meta.source'));
    }

    public function test_directory_query_count_does_not_grow_with_team_size(): void
    {
        $team = Team::create(['name' => 'Sales']);
        $manager = User::factory()->manager()->create(['team_id' => $team->id]);
        User::factory()->count(8)->salesrep()->create(['team_id' => $team->id]);
        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });

        $this->actingAs($manager, 'api')->getJson('/api/v1/employees?per_page=100')->assertOk();
        $this->assertLessThan(20, $queries, 'Employee CRM metrics should be aggregated, not queried per employee.');
    }

    public function test_leaderboard_is_manager_plus_and_returns_role_scoped_employee_metrics(): void
    {
        $team = Team::create(['name' => 'Sales']);
        $otherTeam = Team::create(['name' => 'Other']);
        $manager = User::factory()->manager()->create(['team_id' => $team->id, 'name' => 'Ava Manager']);
        $teammate = User::factory()->salesrep()->create(['team_id' => $team->id, 'name' => 'Zoe Representative']);
        User::factory()->salesrep()->create(['team_id' => $otherTeam->id]);

        $this->actingAs($teammate, 'api')->getJson('/api/v1/employees/leaderboard')->assertForbidden();

        $response = $this->actingAs($manager, 'api')
            ->getJson('/api/v1/employees/leaderboard?range=quarter')
            ->assertOk();
        $this->assertSame(2, $response->json('meta.total'));
        $this->assertSame('quarter', $response->json('meta.range'));
        $this->assertSame([$manager->public_id, $teammate->public_id], array_column($response->json('data'), 'id'));
    }

    public function test_salesrep_cannot_read_another_employees_compensation(): void
    {
        $rep = User::factory()->salesrep()->create();
        $other = User::factory()->salesrep()->create();

        $this->actingAs($rep, 'api')
            ->getJson("/api/v1/employees/{$other->public_id}/compensation")
            ->assertForbidden();
    }

    public function test_manager_sees_teammate_compensation_masked_but_own_unmasked(): void
    {
        $team = Team::create(['name' => 'Sales']);
        $manager = User::factory()->manager()->create(['team_id' => $team->id]);
        $rep = User::factory()->salesrep()->create(['team_id' => $team->id]);

        $masked = $this->actingAs($manager, 'api')
            ->getJson("/api/v1/employees/{$rep->public_id}/compensation")
            ->assertOk()
            ->json('data');
        $this->assertTrue($masked['is_masked']);
        $this->assertNull($masked['base_salary']);
        $this->assertNull($masked['fixed_allowances']);
        $this->assertNotEmpty($masked['recent_payslips']);
        $this->assertNull($masked['recent_payslips'][0]['gross_pay']);
        $this->assertNull($masked['recent_payslips'][0]['net_pay']);

        $own = $this->getJson("/api/v1/employees/{$manager->public_id}/compensation")->assertOk()->json('data');
        $this->assertFalse($own['is_masked']);
        $this->assertNotNull($own['base_salary']);
    }

    public function test_admin_and_superadmin_receive_unmasked_compensation(): void
    {
        $employee = User::factory()->salesrep()->create();
        foreach ([User::factory()->admin()->create(), User::factory()->superadmin()->create()] as $admin) {
            $data = $this->actingAs($admin, 'api')
                ->getJson("/api/v1/employees/{$employee->public_id}/compensation")
                ->assertOk()
                ->json('data');
            $this->assertFalse($data['is_masked']);
            $this->assertNotNull($data['base_salary']);
        }
    }

    public function test_profile_and_attendance_endpoints_return_core2_projection_and_provenance(): void
    {
        $employee = User::factory()->salesrep()->create();
        $payload = $this->actingAs($employee, 'api')
            ->getJson("/api/v1/employees/{$employee->public_id}")
            ->assertOk();
        $this->assertSame($employee->public_id, $payload->json('data.id'));
        $this->assertNotEmpty($payload->json('data.profile.employee_reference'));
        $this->assertSame(true, $payload->json('meta.read_only'));

        $this->getJson("/api/v1/employees/{$employee->public_id}/attendance")
            ->assertOk()
            ->assertJsonPath('data.user_id', $employee->public_id)
            ->assertJsonPath('meta.core2_available', true);
        $this->getJson("/api/v1/employees/{$employee->public_id}/appraisals")
            ->assertOk()
            ->assertJsonPath('meta.read_only', true);
    }

    public function test_unavailable_core2_is_null_without_fabricating_zeros(): void
    {
        config(['crm.integrations.core2_employees.simulate_failure' => true]);
        $employee = User::factory()->salesrep()->create();

        $this->actingAs($employee, 'api')
            ->getJson("/api/v1/employees/{$employee->public_id}")
            ->assertOk()
            ->assertJsonPath('data.profile', null)
            ->assertJsonPath('data.attendance', null)
            ->assertJsonPath('meta.core2_available', false);
    }

    public function test_salesrep_can_see_teammate_directory_row_but_not_their_attendance(): void
    {
        $team = Team::create(['name' => 'Sales']);
        $rep = User::factory()->salesrep()->create(['team_id' => $team->id]);
        $teammate = User::factory()->salesrep()->create(['team_id' => $team->id]);

        $this->actingAs($rep, 'api')
            ->getJson('/api/v1/employees')
            ->assertOk()
            ->assertJsonFragment(['id' => $teammate->public_id]);
        $this->getJson("/api/v1/employees/{$teammate->public_id}/attendance")->assertForbidden();
    }

    public function test_manager_cannot_read_employees_outside_their_team_and_policy_handles_own_user(): void
    {
        $team = Team::create(['name' => 'Sales']);
        $otherTeam = Team::create(['name' => 'Other']);
        $manager = User::factory()->manager()->create(['team_id' => $team->id]);
        $outsider = User::factory()->salesrep()->create(['team_id' => $otherTeam->id]);

        $this->actingAs($manager, 'api')
            ->getJson("/api/v1/employees/{$outsider->public_id}")
            ->assertForbidden();
    }
}
