<?php

namespace Tests\Feature;

use App\Enums\AuditSeverity;
use App\Models\AuditLog;
use App\Models\Lead;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The admin user directory and account lifecycle (module 08 slice S2, spec 19, ADR-0030).
 *
 * The role matrix, the final-active-superadmin guard, suspension enforcement and the audit
 * trail are the contract here: admins manage accounts below superadmin, only a superadmin
 * touches admin accounts, no route creates or edits a superadmin, and every mutation appends
 * an audit row with secrets redacted.
 */
class AdminUserTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_admins_may_open_the_directory(): void
    {
        foreach ([User::factory()->salesrep()->create(), User::factory()->manager()->create()] as $actor) {
            $this->actingAs($actor, 'api')
                ->getJson('/api/v1/admin/users')
                ->assertForbidden()
                ->assertJsonPath('message', 'This action is unauthorized.');
        }

        $this->actingAs(User::factory()->admin()->create(), 'api')
            ->getJson('/api/v1/admin/users')
            ->assertOk();
    }

    public function test_an_admin_never_sees_superadmin_accounts_in_the_directory(): void
    {
        User::factory()->superadmin()->create();
        User::factory()->manager()->create();

        $this->actingAs(User::factory()->admin()->create(), 'api')
            ->getJson('/api/v1/admin/users')
            ->assertOk()
            ->assertJsonMissing(['role' => 'superadmin']);
    }

    public function test_a_superadmin_sees_admin_accounts(): void
    {
        User::factory()->admin()->create();

        $this->actingAs(User::factory()->superadmin()->create(), 'api')
            ->getJson('/api/v1/admin/users')
            ->assertOk()
            ->assertJsonFragment(['role' => 'admin']);
    }

    public function test_the_team_options_lookup_is_admin_only_and_counts_members(): void
    {
        $team = Team::create(['name' => 'Business Development']);
        User::factory()->count(2)->create(['team_id' => $team->id]);

        $this->actingAs(User::factory()->salesrep()->create(), 'api')
            ->getJson('/api/v1/admin/users/team-options')
            ->assertForbidden();

        $this->actingAs(User::factory()->admin()->create(), 'api')
            ->getJson('/api/v1/admin/users/team-options')
            ->assertOk()
            ->assertJsonPath('data.0.id', $team->id)
            ->assertJsonPath('data.0.name', 'Business Development')
            ->assertJsonPath('data.0.users_count', 2);
    }

    public function test_the_team_options_path_is_not_read_as_a_user_public_id(): void
    {
        $this->actingAs(User::factory()->admin()->create(), 'api')
            ->getJson('/api/v1/admin/users/team-options')
            ->assertOk()
            ->assertJsonStructure(['data']);
    }

    public function test_the_directory_filters_by_search_role_team_and_status(): void
    {
        $team = Team::create(['name' => 'Business Development']);
        $other = Team::create(['name' => 'Support']);
        $rep = User::factory()->create(['name' => 'Jordan Sales', 'team_id' => $team->id]);
        User::factory()->manager()->create(['name' => 'Alex Manager', 'team_id' => $other->id]);

        $response = $this->actingAs(User::factory()->admin()->create(), 'api')
            ->getJson('/api/v1/admin/users?search=jordan')
            ->assertOk();

        $this->assertSame(1, count($response->json('data')));
        $this->assertSame('Jordan Sales', $response->json('data.0.name'));

        $this->actingAs(User::factory()->admin()->create(), 'api')
            ->getJson('/api/v1/admin/users?role=manager')
            ->assertOk()
            ->assertJsonMissing(['name' => 'Jordan Sales']);
    }

    public function test_directory_ids_are_ulid_public_ids_never_internal_keys(): void
    {
        $manager = User::factory()->manager()->create();

        $rows = $this->actingAs(User::factory()->admin()->create(), 'api')
            ->getJson('/api/v1/admin/users')
            ->assertOk()
            ->json('data');

        $row = collect($rows)->firstWhere('id', $manager->public_id);

        $this->assertNotNull($row, 'The directory keys rows by the ULID public id.');
        $this->assertNotSame((string) $manager->id, $row['id']);
        $this->assertDatabaseHas(User::class, ['public_id' => $row['id'], 'id' => $manager->id]);
    }

    public function test_an_admin_creates_a_manager_and_receives_no_password(): void
    {
        $admin = User::factory()->admin()->create();
        $team = Team::create(['name' => 'Business Development']);

        $response = $this->actingAs($admin, 'api')
            ->postJson('/api/v1/admin/users', [
                'name' => 'Nina Newcomer',
                'email' => 'nina@primepower.test',
                'role' => 'manager',
                'team_id' => $team->id,
                'password' => 'a-plaintext-password',
            ])
            ->assertCreated();

        $this->assertStringNotContainsString('a-plaintext-password', $response->getContent());
        $this->assertDatabaseHas(User::class, ['email' => 'nina@primepower.test', 'role' => 'manager']);
        $this->assertDatabaseHas(AuditLog::class, ['action' => 'user.created', 'actor_id' => $admin->id, 'subject_id' => User::where('email', 'nina@primepower.test')->value('id')]);
    }

    public function test_an_admin_cannot_create_an_admin_or_superadmin(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin, 'api')
            ->postJson('/api/v1/admin/users', ['name' => 'X', 'email' => 'x@primepower.test', 'role' => 'admin'])
            ->assertUnprocessable();

        $this->actingAs($admin, 'api')
            ->postJson('/api/v1/admin/users', ['name' => 'X', 'email' => 'x@primepower.test', 'role' => 'superadmin'])
            ->assertUnprocessable();

        $this->assertDatabaseMissing(User::class, ['email' => 'x@primepower.test']);
    }

    public function test_only_a_superadmin_creates_an_admin(): void
    {
        $this->actingAs(User::factory()->superadmin()->create(), 'api')
            ->postJson('/api/v1/admin/users', ['name' => 'Second Admin', 'email' => 'admin2@primepower.test', 'role' => 'admin'])
            ->assertCreated()
            ->assertJsonPath('data.role', 'admin');
    }

    public function test_a_superadmin_account_is_never_assignable(): void
    {
        $this->actingAs(User::factory()->superadmin()->create(), 'api')
            ->postJson('/api/v1/admin/users', ['name' => 'X', 'email' => 'x@primepower.test', 'role' => 'superadmin'])
            ->assertUnprocessable();
    }

    public function test_an_admin_cannot_view_or_edit_a_superadmin(): void
    {
        $admin = User::factory()->admin()->create();
        $superadmin = User::factory()->superadmin()->create();

        $this->actingAs($admin, 'api')
            ->getJson("/api/v1/admin/users/{$superadmin->public_id}")
            ->assertForbidden();

        $this->actingAs($admin, 'api')
            ->patchJson("/api/v1/admin/users/{$superadmin->public_id}", ['name' => 'Renamed'])
            ->assertForbidden();

        $this->assertDatabaseHas(AuditLog::class, ['action' => 'security.access_denied', 'subject_id' => $superadmin->id]);
    }

    public function test_safe_profile_edits_are_accepted_and_sensitive_fields_rejected(): void
    {
        $admin = User::factory()->admin()->create();
        $manager = User::factory()->manager()->create();

        $this->actingAs($admin, 'api')
            ->patchJson("/api/v1/admin/users/{$manager->public_id}", ['name' => 'Renamed Manager'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Renamed Manager');

        $this->assertDatabaseHas(AuditLog::class, ['action' => 'user.updated', 'subject_id' => $manager->id]);

        foreach ([['role' => 'salesrep'], ['team_id' => (string) Str::ulid()], ['status' => 'suspended'], ['password' => 'new-password-123']] as $payload) {
            $this->actingAs($admin, 'api')
                ->patchJson("/api/v1/admin/users/{$manager->public_id}", $payload)
                ->assertUnprocessable();
        }
    }

    public function test_role_changes_are_audited_and_role_scoped(): void
    {
        $admin = User::factory()->admin()->create();
        $manager = User::factory()->manager()->create();

        $this->actingAs($admin, 'api')
            ->patchJson("/api/v1/admin/users/{$manager->public_id}/role", ['role' => 'salesrep'])
            ->assertOk()
            ->assertJsonPath('data.role', 'salesrep');

        $this->assertDatabaseHas(AuditLog::class, [
            'action' => 'user.role_changed',
            'severity' => AuditSeverity::Warning->value,
            'subject_id' => $manager->id,
        ]);

        // An admin may not promote anyone into admin.
        $this->actingAs($admin, 'api')
            ->patchJson("/api/v1/admin/users/{$manager->public_id}/role", ['role' => 'admin'])
            ->assertUnprocessable();

        // …but a superadmin may.
        $this->actingAs(User::factory()->superadmin()->create(), 'api')
            ->patchJson("/api/v1/admin/users/{$manager->public_id}/role", ['role' => 'admin'])
            ->assertOk()
            ->assertJsonPath('data.role', 'admin');
    }

    public function test_the_final_active_superadmin_cannot_be_demoted(): void
    {
        $superadmin = User::factory()->superadmin()->create();

        $this->actingAs($superadmin, 'api')
            ->patchJson("/api/v1/admin/users/{$superadmin->public_id}/role", ['role' => 'admin'])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'The final active superadmin cannot be removed.');

        $this->assertDatabaseHas(AuditLog::class, [
            'action' => 'security.final_superadmin_guard',
            'severity' => AuditSeverity::Critical->value,
        ]);
    }

    public function test_a_second_superadmin_may_be_demoted(): void
    {
        $first = User::factory()->superadmin()->create();
        $second = User::factory()->superadmin()->create();

        $this->actingAs($first, 'api')
            ->patchJson("/api/v1/admin/users/{$second->public_id}/role", ['role' => 'admin'])
            ->assertOk();
    }

    public function test_a_team_change_is_audited_and_never_touches_ownership(): void
    {
        $admin = User::factory()->admin()->create();
        $team = Team::create(['name' => 'Business Development']);
        $rep = User::factory()->salesrep()->create();
        $lead = Lead::factory()->for($rep, 'owner')->create();

        $this->actingAs($admin, 'api')
            ->patchJson("/api/v1/admin/users/{$rep->public_id}/team", ['team_id' => $team->id])
            ->assertOk()
            ->assertJsonPath('data.team.id', $team->id);

        $this->assertDatabaseHas(AuditLog::class, ['action' => 'user.team_changed', 'subject_id' => $rep->id]);
        $this->assertDatabaseHas('leads', ['id' => $lead->id, 'owner_id' => $rep->id]);
    }

    public function test_a_user_cannot_suspend_themselves(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin, 'api')
            ->postJson("/api/v1/admin/users/{$admin->public_id}/suspend", ['status_reason' => 'self service'])
            ->assertForbidden();

        $this->assertDatabaseHas(AuditLog::class, ['action' => 'security.access_denied', 'subject_id' => $admin->id]);
    }

    public function test_an_admin_suspends_and_restores_a_salesrep(): void
    {
        $admin = User::factory()->admin()->create();
        $rep = User::factory()->salesrep()->create();

        $this->actingAs($admin, 'api')
            ->postJson("/api/v1/admin/users/{$rep->public_id}/suspend", ['status_reason' => 'Investigating access.'])
            ->assertOk()
            ->assertJsonPath('data.status', 'suspended')
            ->assertJsonPath('data.status_reason', 'Investigating access.');

        $this->assertDatabaseHas(User::class, ['id' => $rep->id, 'status' => 'suspended', 'suspended_by' => $admin->id]);
        $this->assertDatabaseHas(AuditLog::class, ['action' => 'user.suspended', 'severity' => AuditSeverity::Warning->value]);

        $this->actingAs($admin, 'api')
            ->postJson("/api/v1/admin/users/{$rep->public_id}/restore")
            ->assertOk()
            ->assertJsonPath('data.status', 'active');

        $this->assertDatabaseHas(User::class, ['id' => $rep->id, 'status' => 'active', 'suspended_at' => null]);
        $this->assertDatabaseHas(AuditLog::class, ['action' => 'user.restored']);
    }

    public function test_restoring_an_active_user_is_rejected(): void
    {
        $admin = User::factory()->admin()->create();
        $rep = User::factory()->salesrep()->create();

        $this->actingAs($admin, 'api')
            ->postJson("/api/v1/admin/users/{$rep->public_id}/restore")
            ->assertForbidden();
    }

    public function test_an_admin_cannot_suspend_or_restore_a_superadmin(): void
    {
        $admin = User::factory()->admin()->create();
        $superadmin = User::factory()->superadmin()->create();

        $this->actingAs($admin, 'api')
            ->postJson("/api/v1/admin/users/{$superadmin->public_id}/suspend", ['status_reason' => 'nope'])
            ->assertForbidden();

        $this->assertDatabaseHas(AuditLog::class, ['action' => 'security.access_denied', 'subject_id' => $superadmin->id]);
    }

    public function test_the_final_active_superadmin_cannot_be_suspended(): void
    {
        $first = User::factory()->superadmin()->create();
        $last = User::factory()->superadmin()->create(['status' => 'suspended']);

        $this->actingAs($first, 'api')
            ->postJson("/api/v1/admin/users/{$last->public_id}/suspend", ['status_reason' => 'nope'])
            ->assertUnprocessable();

        $this->assertDatabaseHas(AuditLog::class, [
            'action' => 'security.final_superadmin_guard',
            'severity' => AuditSeverity::Critical->value,
        ]);
        $this->assertDatabaseHas(User::class, ['id' => $last->id, 'status' => 'suspended']);
    }

    public function test_a_suspended_user_cannot_authenticate(): void
    {
        $rep = User::factory()->salesrep()->create(['password' => 'password', 'status' => 'suspended']);

        $this->postJson('/api/v1/auth/login', ['email' => $rep->email, 'password' => 'password'])
            ->assertUnauthorized()
            ->assertJsonPath('message', 'Account suspended.');
    }

    public function test_an_existing_token_stops_working_when_the_account_is_suspended(): void
    {
        $rep = User::factory()->salesrep()->create();

        $this->actingAs($rep, 'api')->getJson('/api/v1/auth/me')->assertOk();

        $rep->forceFill(['status' => 'suspended', 'suspended_at' => now(), 'status_reason' => 'test'])->save();

        $this->actingAs($rep, 'api')
            ->getJson('/api/v1/auth/me')
            ->assertUnauthorized()
            ->assertJsonPath('message', 'Account suspended.');
    }

    public function test_resending_an_invitation_uses_the_mocked_gateway_and_leaks_no_credential(): void
    {
        $admin = User::factory()->admin()->create();
        $rep = User::factory()->salesrep()->create();

        $this->actingAs($admin, 'api')
            ->postJson("/api/v1/admin/users/{$rep->public_id}/resend-invitation")
            ->assertOk()
            ->assertJsonPath('data.sent', true)
            ->assertJsonPath('data.source', 'Email (mocked)');

        $this->assertDatabaseHas(AuditLog::class, ['action' => 'user.invitation_resent', 'subject_id' => $rep->id]);
    }

    public function test_a_suspended_user_receives_no_invitation(): void
    {
        $admin = User::factory()->admin()->create();
        $rep = User::factory()->salesrep()->create(['status' => 'suspended']);

        $this->actingAs($admin, 'api')
            ->postJson("/api/v1/admin/users/{$rep->public_id}/resend-invitation")
            ->assertUnprocessable();
    }

    public function test_the_ownership_impact_is_a_read_only_preview(): void
    {
        $admin = User::factory()->admin()->create();
        $rep = User::factory()->salesrep()->create();
        Lead::factory()->count(2)->for($rep, 'owner')->create();

        $data = $this->actingAs($admin, 'api')
            ->getJson("/api/v1/admin/users/{$rep->public_id}/ownership-impact")
            ->assertOk()
            ->json('data');

        $this->assertSame(2, $data['counts']['leads']);
        $this->assertArrayHasKey('note', $data);
    }

    public function test_audit_snapshots_never_persist_passwords(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin, 'api')
            ->postJson('/api/v1/admin/users', [
                'name' => 'Nina Newcomer',
                'email' => 'nina@primepower.test',
                'role' => 'manager',
                'password' => 'super-secret-value',
            ])
            ->assertCreated();

        AuditLog::all()->each(function (AuditLog $row): void {
            $this->assertStringNotContainsString('super-secret-value', (string) json_encode([$row->before, $row->after]));
        });
    }
}
