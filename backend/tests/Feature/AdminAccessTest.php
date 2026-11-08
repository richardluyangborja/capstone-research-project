<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_admin_gets_the_application_plane_but_not_the_platform_plane(): void
    {
        $this->actingAs(User::factory()->admin()->create(), 'api')
            ->getJson('/api/v1/admin/access')
            ->assertOk()
            ->assertJsonPath('data.role', 'admin')
            ->assertJsonPath('data.application', true)
            ->assertJsonPath('data.platform', false)
            ->assertJsonPath('data.personal_settings', true)
            ->assertJsonPath('data.areas', ['application']);
    }

    public function test_a_superadmin_gets_both_administration_planes(): void
    {
        $this->actingAs(User::factory()->superadmin()->create(), 'api')
            ->getJson('/api/v1/admin/access')
            ->assertOk()
            ->assertJsonPath('data.role', 'superadmin')
            ->assertJsonPath('data.application', true)
            ->assertJsonPath('data.platform', true)
            ->assertJsonPath('data.areas', ['application', 'platform']);
    }

    public function test_salesreps_and_managers_get_the_existing_authenticated_403_shape(): void
    {
        foreach ([User::factory()->salesrep()->create(), User::factory()->manager()->create()] as $user) {
            $this->actingAs($user, 'api')
                ->getJson('/api/v1/admin/access')
                ->assertForbidden()
                ->assertJsonPath('message', 'This action is unauthorized.');
        }
    }

    public function test_the_capability_probe_requires_authentication(): void
    {
        $this->getJson('/api/v1/admin/access')->assertUnauthorized();
    }
}
