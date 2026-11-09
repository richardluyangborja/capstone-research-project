<?php

namespace Tests\Feature;

use App\Enums\AuditSeverity;
use App\Models\AuditLog;
use App\Models\Lead;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Team administration (module 08 slice S3, spec 19, ADR-0030).
 *
 * The role boundary, manager eligibility, membership constraints and the no-ownership-change
 * rule are the contract here. Every mutation must leave an audit row; the team id is a ULID, so
 * the snapshots carry it while `subject_id` stays null.
 */
class AdminTeamTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_admins_may_open_the_team_directory(): void
    {
        foreach ([User::factory()->salesrep()->create(), User::factory()->manager()->create()] as $actor) {
            $this->actingAs($actor, 'api')
                ->getJson('/api/v1/admin/teams')
                ->assertForbidden()
                ->assertJsonPath('message', 'This action is unauthorized.');
        }

        $this->actingAs(User::factory()->admin()->create(), 'api')
            ->getJson('/api/v1/admin/teams')
            ->assertOk();
    }

    public function test_the_directory_lists_teams_with_counts_manager_and_ulids(): void
    {
        $manager = User::factory()->manager()->create(['name' => 'Mara Manager']);
        $team = Team::create(['name' => 'Business Development', 'manager_id' => $manager->id]);
        $rep = User::factory()->salesrep()->create(['team_id' => $team->id]);
        $manager->update(['team_id' => $team->id]);

        $response = $this->actingAs(User::factory()->admin()->create(), 'api')
            ->getJson('/api/v1/admin/teams?search=business')
            ->assertOk()
            ->assertJsonPath('data.0.id', $team->id)
            ->assertJsonPath('data.0.name', 'Business Development')
            ->assertJsonPath('data.0.manager.id', $manager->public_id)
            ->assertJsonPath('data.0.members_count', 2)
            ->assertJsonPath('data.0.can.update', true);

        $this->assertDatabaseHas('teams', ['id' => $team->id]);
        $this->assertDatabaseHas('users', ['id' => $rep->id, 'team_id' => $team->id]);
    }

    public function test_an_admin_creates_a_team_and_it_is_audited(): void
    {
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin, 'api')
            ->postJson('/api/v1/admin/teams', ['name' => '  Enterprise Accounts  '])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Enterprise Accounts')
            ->assertJsonPath('data.members_count', 0);

        $this->assertDatabaseHas('teams', ['name' => 'Enterprise Accounts']);
        $this->assertDatabaseHas(AuditLog::class, ['action' => 'team.created', 'severity' => AuditSeverity::Info->value]);

        $row = AuditLog::where('action', 'team.created')->firstOrFail();
        $this->assertSame($response->json('data.id'), $row->after['team_id']);
    }

    public function test_a_team_name_is_required_and_unique(): void
    {
        $admin = User::factory()->admin()->create();
        Team::create(['name' => 'Existing Team']);

        $this->actingAs($admin, 'api')
            ->postJson('/api/v1/admin/teams', ['name' => ''])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('name');

        $this->actingAs($admin, 'api')
            ->postJson('/api/v1/admin/teams', ['name' => 'Existing Team'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('name');
    }

    public function test_a_manager_must_be_an_active_manager_account(): void
    {
        $admin = User::factory()->admin()->create();
        $team = Team::create(['name' => 'Business Development']);

        $salesrep = User::factory()->salesrep()->create();
        $suspended = User::factory()->manager()->create(['status' => 'suspended']);
        $applicationAdmin = User::factory()->admin()->create();

        foreach ([$salesrep, $suspended, $applicationAdmin] as $candidate) {
            $this->actingAs($admin, 'api')
                ->patchJson("/api/v1/admin/teams/{$team->id}/manager", ['manager_id' => $candidate->public_id])
                ->assertUnprocessable()
                ->assertJsonValidationErrors('manager_id');
        }

        $manager = User::factory()->manager()->create();
        $this->actingAs($admin, 'api')
            ->patchJson("/api/v1/admin/teams/{$team->id}/manager", ['manager_id' => $manager->public_id])
            ->assertOk()
            ->assertJsonPath('data.manager.id', $manager->public_id);

        $this->assertDatabaseHas('teams', ['id' => $team->id, 'manager_id' => $manager->id]);
        $this->assertDatabaseHas(AuditLog::class, ['action' => 'team.updated']);
    }

    public function test_a_team_can_be_renamed_and_the_change_is_audited(): void
    {
        $admin = User::factory()->admin()->create();
        $team = Team::create(['name' => 'Old Name']);

        $this->actingAs($admin, 'api')
            ->patchJson("/api/v1/admin/teams/{$team->id}", ['name' => 'New Name'])
            ->assertOk()
            ->assertJsonPath('data.name', 'New Name');

        $this->assertDatabaseHas('teams', ['id' => $team->id, 'name' => 'New Name']);
        $this->assertDatabaseHas(AuditLog::class, ['action' => 'team.updated', 'severity' => AuditSeverity::Info->value]);
    }

    public function test_adding_and_removing_a_member_never_reassigns_their_records(): void
    {
        $admin = User::factory()->admin()->create();
        $team = Team::create(['name' => 'Business Development']);
        $rep = User::factory()->salesrep()->create();
        $lead = Lead::factory()->for($rep, 'owner')->create();

        $this->actingAs($admin, 'api')
            ->postJson("/api/v1/admin/teams/{$team->id}/members", ['user_id' => $rep->public_id])
            ->assertOk()
            ->assertJsonPath('data.members_count', 1);

        $this->assertDatabaseHas('users', ['id' => $rep->id, 'team_id' => $team->id]);
        $this->assertDatabaseHas('leads', ['id' => $lead->id, 'owner_id' => $rep->id]);
        $this->assertDatabaseHas(AuditLog::class, ['action' => 'team.member_added', 'severity' => AuditSeverity::Info->value]);

        $this->actingAs($admin, 'api')
            ->deleteJson("/api/v1/admin/teams/{$team->id}/members/{$rep->public_id}")
            ->assertOk()
            ->assertJsonPath('data.members_count', 0);

        $this->assertDatabaseHas('users', ['id' => $rep->id, 'team_id' => null]);
        $this->assertDatabaseHas('leads', ['id' => $lead->id, 'owner_id' => $rep->id]);
        $this->assertDatabaseHas(AuditLog::class, ['action' => 'team.member_removed', 'severity' => AuditSeverity::Notice->value]);
    }

    public function test_adding_a_member_moves_them_from_their_previous_team(): void
    {
        $admin = User::factory()->admin()->create();
        $oldTeam = Team::create(['name' => 'Old Team']);
        $newTeam = Team::create(['name' => 'New Team']);
        $rep = User::factory()->salesrep()->create(['team_id' => $oldTeam->id]);

        $this->actingAs($admin, 'api')
            ->postJson("/api/v1/admin/teams/{$newTeam->id}/members", ['user_id' => $rep->public_id])
            ->assertOk();

        $this->assertDatabaseHas('users', ['id' => $rep->id, 'team_id' => $newTeam->id]);

        $row = AuditLog::where('action', 'team.member_added')->firstOrFail();
        $this->assertSame($oldTeam->id, $row->before['team_id']);
        $this->assertSame($newTeam->id, $row->after['team_id']);
    }

    public function test_an_admin_cannot_move_an_admin_or_superadmin_into_a_team(): void
    {
        $admin = User::factory()->admin()->create();
        $team = Team::create(['name' => 'Business Development']);

        $this->actingAs($admin, 'api')
            ->postJson("/api/v1/admin/teams/{$team->id}/members", ['user_id' => User::factory()->admin()->create()->public_id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('user_id');

        $this->actingAs($admin, 'api')
            ->postJson("/api/v1/admin/teams/{$team->id}/members", ['user_id' => User::factory()->superadmin()->create()->public_id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('user_id');
    }

    public function test_only_a_superadmin_may_move_an_admin_into_a_team(): void
    {
        $superadmin = User::factory()->superadmin()->create();
        $admin = User::factory()->admin()->create();
        $team = Team::create(['name' => 'Business Development']);

        $this->actingAs($superadmin, 'api')
            ->postJson("/api/v1/admin/teams/{$team->id}/members", ['user_id' => $admin->public_id])
            ->assertOk();

        $this->assertDatabaseHas('users', ['id' => $admin->id, 'team_id' => $team->id]);
    }

    public function test_a_suspended_account_cannot_join_a_team(): void
    {
        $admin = User::factory()->admin()->create();
        $team = Team::create(['name' => 'Business Development']);
        $rep = User::factory()->salesrep()->create(['status' => 'suspended']);

        $this->actingAs($admin, 'api')
            ->postJson("/api/v1/admin/teams/{$team->id}/members", ['user_id' => $rep->public_id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('user_id');
    }

    public function test_an_account_cannot_join_the_same_team_twice(): void
    {
        $admin = User::factory()->admin()->create();
        $team = Team::create(['name' => 'Business Development']);
        $rep = User::factory()->salesrep()->create(['team_id' => $team->id]);

        $this->actingAs($admin, 'api')
            ->postJson("/api/v1/admin/teams/{$team->id}/members", ['user_id' => $rep->public_id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('user_id');
    }

    public function test_the_current_manager_cannot_be_removed_from_the_team(): void
    {
        $admin = User::factory()->admin()->create();
        $manager = User::factory()->manager()->create();
        $team = Team::create(['name' => 'Business Development', 'manager_id' => $manager->id]);
        $manager->update(['team_id' => $team->id]);

        $this->actingAs($admin, 'api')
            ->deleteJson("/api/v1/admin/teams/{$team->id}/members/{$manager->public_id}")
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Assign another manager before removing this member.');

        $this->assertDatabaseHas('users', ['id' => $manager->id, 'team_id' => $team->id]);
    }

    public function test_removing_a_non_member_is_rejected(): void
    {
        $admin = User::factory()->admin()->create();
        $team = Team::create(['name' => 'Business Development']);
        $rep = User::factory()->salesrep()->create();

        $this->actingAs($admin, 'api')
            ->deleteJson("/api/v1/admin/teams/{$team->id}/members/{$rep->public_id}")
            ->assertUnprocessable()
            ->assertJsonPath('message', 'This account does not belong to the team.');
    }

    public function test_a_team_manager_cannot_be_demoted_or_suspended(): void
    {
        $admin = User::factory()->admin()->create();
        $manager = User::factory()->manager()->create();
        Team::create(['name' => 'Business Development', 'manager_id' => $manager->id]);

        $this->actingAs($admin, 'api')
            ->patchJson("/api/v1/admin/users/{$manager->public_id}/role", ['role' => 'salesrep'])
            ->assertUnprocessable()
            ->assertJsonPath('message', "Reassign this user's teams before changing their role.");

        $this->actingAs($admin, 'api')
            ->postJson("/api/v1/admin/users/{$manager->public_id}/suspend", ['status_reason' => 'Team still assigned'])
            ->assertUnprocessable()
            ->assertJsonPath('message', "Reassign this user's teams before suspending the account.");

        $this->assertDatabaseHas('users', ['id' => $manager->id, 'role' => 'manager', 'status' => 'active']);
    }

    public function test_the_detail_payload_marks_the_manager_and_lists_members(): void
    {
        $admin = User::factory()->admin()->create();
        $manager = User::factory()->manager()->create(['name' => 'Mara Manager']);
        $rep = User::factory()->salesrep()->create(['name' => 'Sam Rep']);
        $team = Team::create(['name' => 'Business Development', 'manager_id' => $manager->id]);
        $manager->update(['team_id' => $team->id]);
        $rep->update(['team_id' => $team->id]);

        $this->actingAs($admin, 'api')
            ->getJson("/api/v1/admin/teams/{$team->id}")
            ->assertOk()
            ->assertJsonPath('data.members_count', 2)
            ->assertJsonCount(2, 'data.members')
            ->assertJsonPath('data.members.0.id', $manager->public_id)
            ->assertJsonPath('data.members.0.is_manager', true)
            ->assertJsonPath('data.can.assign_manager', true);
    }

    public function test_non_admins_cannot_mutate_a_team_and_the_refusal_is_audited(): void
    {
        $team = Team::create(['name' => 'Business Development']);
        $manager = User::factory()->manager()->create();

        $this->actingAs($manager, 'api')
            ->patchJson("/api/v1/admin/teams/{$team->id}", ['name' => 'Hijacked'])
            ->assertForbidden();

        $this->assertDatabaseHas('teams', ['id' => $team->id, 'name' => 'Business Development']);
        $this->assertDatabaseHas(AuditLog::class, ['action' => 'security.access_denied', 'severity' => AuditSeverity::Warning->value]);
    }
}
