<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\UserSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PersonalSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_role_can_read_and_update_their_own_preferences(): void
    {
        foreach (['salesrep', 'manager', 'admin', 'superadmin'] as $role) {
            $user = User::factory()->create(['role' => $role]);
            $this->actingAs($user, 'api')
                ->patchJson('/api/v1/me/preferences', ['table_density' => 'compact', 'notification_database' => false])
                ->assertOk()
                ->assertJsonPath('data.table_density', 'compact')
                ->assertJsonPath('data.notification_database', false);
        }
    }

    public function test_profile_rejects_privilege_fields_and_preferences_are_allow_listed(): void
    {
        $user = User::factory()->salesrep()->create();
        $this->actingAs($user, 'api')->patchJson('/api/v1/me/profile', ['role' => 'admin'])->assertUnprocessable();
        $this->actingAs($user, 'api')->patchJson('/api/v1/me/preferences', ['theme' => 'anything'])->assertUnprocessable();
        $this->assertSame('salesrep', $user->fresh()->role->value);
    }

    public function test_password_change_requires_current_password_and_revokes_other_sessions(): void
    {
        $user = User::factory()->salesrep()->create(['password' => 'original-pass1']);
        $current = UserSession::query()->create([
            'user_id' => $user->id,
            'jti' => 'current-token-jti',
            'last_seen_at' => now(),
            'expires_at' => now()->addDay(),
        ]);
        $other = UserSession::query()->create([
            'user_id' => $user->id,
            'jti' => 'other-token-jti',
            'last_seen_at' => now(),
            'expires_at' => now()->addDay(),
        ]);

        $this->actingAs($user, 'api')->putJson('/api/v1/me/password', [
            'current_password' => 'wrong-pass1',
            'password' => 'changed-pass2',
            'password_confirmation' => 'changed-pass2',
        ])->assertUnprocessable();

        $this->actingAs($user, 'api')->putJson('/api/v1/me/password', [
            'current_password' => 'original-pass1',
            'password' => 'changed-pass2',
            'password_confirmation' => 'changed-pass2',
        ])->assertOk();

        $this->assertTrue(Hash::check('changed-pass2', $user->fresh()->password));
        $this->assertNull($current->fresh()->revoked_at);
        $this->assertNotNull($other->fresh()->revoked_at);
    }

    public function test_login_records_a_redacted_session_and_token_can_read_its_own_sessions(): void
    {
        $user = User::factory()->salesrep()->create(['password' => 'secret-pass']);
        $login = $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'secret-pass'])->assertOk();
        $token = $login->json('access_token');

        $this->withToken($token)->getJson('/api/v1/me/sessions')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.current', true)
            ->assertJsonMissingPath('data.0.jti');
        $this->assertDatabaseHas(UserSession::class, ['user_id' => $user->id]);
    }

    public function test_session_revocation_is_limited_to_the_current_user(): void
    {
        $owner = User::factory()->salesrep()->create();
        $other = User::factory()->salesrep()->create();
        $session = UserSession::query()->create([
            'user_id' => $other->id,
            'jti' => 'other-owner-jti',
            'last_seen_at' => now(),
            'expires_at' => now()->addDay(),
        ]);

        $this->actingAs($owner, 'api')->deleteJson('/api/v1/me/sessions/'.$session->id)->assertForbidden();
        $this->assertNull($session->fresh()->revoked_at);
    }
}
