<?php

namespace Tests\Feature;

use App\Enums\CompanyStatus;
use App\Models\Company;
use App\Models\CompanySite;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CompanyTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_salesrep_can_create_a_company_and_owns_it(): void
    {
        $user = User::factory()->salesrep()->create();

        $response = $this->actingAs($user, 'api')
            ->postJson('/api/v1/companies', [
                'name' => 'Northwind Traders',
                'industry' => 'Logistics',
            ])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Northwind Traders')
            ->assertJsonPath('data.status', CompanyStatus::Prospect->value)
            ->assertJsonPath('data.is_client', false)
            ->assertJsonPath('data.owner_id', $user->id)
            ->assertJsonPath('duplicates', []);

        $this->assertMatchesRegularExpression(
            '/^CO-\d{4}-\d{4}$/',
            $response->json('data.reference'),
        );
    }

    public function test_a_company_name_collision_is_reported_but_allowed(): void
    {
        $user = User::factory()->salesrep()->create();
        $existing = Company::factory()->ownedBy($user)->create(['name' => 'Metro Builders Corp']);

        $this->actingAs($user, 'api')
            ->postJson('/api/v1/companies', ['name' => 'metro builders corp'])
            ->assertCreated()
            ->assertJsonPath('duplicates.0.id', $existing->id)
            ->assertJsonPath('duplicates.0.reference', $existing->reference);
    }

    public function test_only_manager_and_above_may_open_a_company_in_a_client_state(): void
    {
        $rep = User::factory()->salesrep()->create();
        $manager = User::factory()->manager()->create();

        $this->actingAs($rep, 'api')
            ->postJson('/api/v1/companies', [
                'name' => 'Rep Import Co',
                'status' => CompanyStatus::Active->value,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('status');

        $this->actingAs($manager, 'api')
            ->postJson('/api/v1/companies', [
                'name' => 'Manager Import Co',
                'status' => CompanyStatus::Active->value,
                'client_since' => now()->subMonths(3)->toDateString(),
            ])
            ->assertCreated()
            ->assertJsonPath('data.status', CompanyStatus::Active->value)
            ->assertJsonPath('data.is_client', true);
    }

    public function test_a_salesrep_may_only_create_what_they_will_own(): void
    {
        $rep = User::factory()->salesrep()->create();
        $other = User::factory()->salesrep()->create();

        $this->actingAs($rep, 'api')
            ->postJson('/api/v1/companies', [
                'name' => 'Not Mine Inc',
                'owner_id' => $other->id,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('owner_id');

        $this->actingAs($rep, 'api')
            ->postJson('/api/v1/companies', [
                'name' => 'Mine Inc',
                'owner_id' => $rep->id,
            ])
            ->assertCreated()
            ->assertJsonPath('data.owner_id', $rep->id);
    }

    public function test_a_company_cannot_be_its_own_parent(): void
    {
        $user = User::factory()->manager()->create();
        $company = Company::factory()->ownedBy($user)->create();

        $this->actingAs($user, 'api')
            ->patchJson("/api/v1/companies/{$company->id}", [
                'parent_company_id' => $company->id,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('parent_company_id');
    }

    public function test_a_branch_cycle_is_rejected(): void
    {
        $user = User::factory()->manager()->create();
        $head = Company::factory()->ownedBy($user)->create();
        $branch = Company::factory()->ownedBy($user)->create(['parent_company_id' => $head->id]);

        $this->actingAs($user, 'api')
            ->patchJson("/api/v1/companies/{$head->id}", ['parent_company_id' => $branch->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors('parent_company_id');

        $this->assertNull($head->fresh()->parent_company_id);
    }

    public function test_branch_links_are_exposed_on_both_sides(): void
    {
        $user = User::factory()->manager()->create();
        $head = Company::factory()->ownedBy($user)->create();
        $branch = Company::factory()->ownedBy($user)->create(['parent_company_id' => $head->id]);

        $this->actingAs($user, 'api')
            ->getJson("/api/v1/companies/{$head->id}")
            ->assertOk()
            ->assertJsonPath('data.counts.branches', 1)
            ->assertJsonPath('data.parent', null);

        $this->actingAs($user, 'api')
            ->getJson("/api/v1/companies/{$branch->id}")
            ->assertOk()
            ->assertJsonPath('data.parent.id', $head->id);
    }

    public function test_visibility_follows_ownership_and_team(): void
    {
        $team = Team::factory()->create();
        $otherTeam = Team::factory()->create();

        $rep = User::factory()->salesrep()->create(['team_id' => $team->id]);
        $teammate = User::factory()->salesrep()->create(['team_id' => $team->id]);
        $manager = User::factory()->manager()->create(['team_id' => $team->id]);
        $admin = User::factory()->admin()->create();
        $outsider = User::factory()->salesrep()->create(['team_id' => $otherTeam->id]);

        Company::factory()->ownedBy($rep)->create();
        Company::factory()->ownedBy($teammate)->create();
        Company::factory()->ownedBy($outsider)->create();

        $this->actingAs($rep, 'api')->getJson('/api/v1/companies')->assertJsonCount(1, 'data');
        $this->actingAs($manager, 'api')->getJson('/api/v1/companies')->assertJsonCount(2, 'data');
        $this->actingAs($admin, 'api')->getJson('/api/v1/companies')->assertJsonCount(3, 'data');

        $outsiderCompany = Company::factory()->ownedBy($outsider)->create();

        $this->actingAs($rep, 'api')
            ->getJson("/api/v1/companies/{$outsiderCompany->id}")
            ->assertStatus(403);
    }

    public function test_the_detail_rolls_up_sites_branches_and_activities(): void
    {
        $user = User::factory()->manager()->create();
        $company = Company::factory()->ownedBy($user)->create();

        CompanySite::factory()->create(['company_id' => $company->id]);
        CompanySite::factory()->primary()->create(['company_id' => $company->id]);
        Company::factory()->ownedBy($user)->create(['parent_company_id' => $company->id]);

        $this->actingAs($user, 'api')
            ->postJson("/api/v1/companies/{$company->id}/activities", [
                'type' => 'call',
                'subject' => 'Intro call',
            ])
            ->assertOk();

        $this->actingAs($user, 'api')
            ->getJson("/api/v1/companies/{$company->id}")
            ->assertOk()
            ->assertJsonPath('data.counts.sites', 2)
            ->assertJsonPath('data.counts.branches', 1)
            ->assertJsonPath('data.counts.activities', 1)
            ->assertJsonPath('data.can_update', true)
            ->assertJsonPath('data.can_manage_sites', true);
    }

    public function test_update_changes_the_company_fields(): void
    {
        $user = User::factory()->manager()->create();
        $company = Company::factory()->ownedBy($user)->create(['name' => 'Old Name']);

        $this->actingAs($user, 'api')
            ->patchJson("/api/v1/companies/{$company->id}", [
                'name' => 'New Name',
                'trade_name' => 'New T/A',
                'tier' => 'a',
                'tags' => ['priority', 'referral'],
            ])
            ->assertOk()
            ->assertJsonPath('data.name', 'New Name')
            ->assertJsonPath('data.trade_name', 'New T/A')
            ->assertJsonPath('data.tier', 'a')
            ->assertJsonPath('data.tags', ['priority', 'referral']);
    }

    public function test_only_an_admin_may_change_the_status_directly(): void
    {
        $manager = User::factory()->manager()->create();
        $admin = User::factory()->admin()->create();
        $company = Company::factory()->ownedBy($manager)->create();

        $this->actingAs($manager, 'api')
            ->patchJson("/api/v1/companies/{$company->id}", ['status' => CompanyStatus::Active->value])
            ->assertStatus(422)
            ->assertJsonValidationErrors('status');

        $this->actingAs($admin, 'api')
            ->patchJson("/api/v1/companies/{$company->id}", ['status' => CompanyStatus::Active->value])
            ->assertOk()
            ->assertJsonPath('data.status', CompanyStatus::Active->value)
            ->assertJsonPath('data.is_client', true);
    }

    public function test_deleting_is_a_guarded_soft_delete_that_can_be_restored(): void
    {
        $user = User::factory()->manager()->create();
        $company = Company::factory()->ownedBy($user)->create();

        $this->actingAs($user, 'api')
            ->deleteJson("/api/v1/companies/{$company->id}")
            ->assertNoContent();

        $this->assertSoftDeleted('companies', ['id' => $company->id]);

        $this->actingAs($user, 'api')
            ->getJson("/api/v1/companies/{$company->id}")
            ->assertNotFound();

        $this->actingAs($user, 'api')
            ->getJson('/api/v1/companies')
            ->assertJsonCount(0, 'data');

        $this->actingAs($user, 'api')
            ->postJson("/api/v1/companies/{$company->id}/restore")
            ->assertOk()
            ->assertJsonPath('data.id', $company->id);

        $this->actingAs($user, 'api')->getJson('/api/v1/companies')->assertJsonCount(1, 'data');
    }

    public function test_a_salesrep_cannot_delete_another_users_company(): void
    {
        $rep = User::factory()->salesrep()->create();
        $other = User::factory()->salesrep()->create();
        $company = Company::factory()->ownedBy($other)->create();

        $this->actingAs($rep, 'api')
            ->deleteJson("/api/v1/companies/{$company->id}")
            ->assertStatus(403);

        $this->assertNotSoftDeleted('companies', ['id' => $company->id]);
    }

    public function test_a_salesrep_cannot_delete_even_their_own_company(): void
    {
        $rep = User::factory()->salesrep()->create();
        $company = Company::factory()->ownedBy($rep)->create();

        // Spec 06 permissions: Delete / restore is manager+ (salesrep `—`).
        $this->actingAs($rep, 'api')
            ->deleteJson("/api/v1/companies/{$company->id}")
            ->assertStatus(403);

        $this->assertNotSoftDeleted('companies', ['id' => $company->id]);
    }

    public function test_a_salesrep_cannot_manage_sites_but_a_manager_can(): void
    {
        $team = Team::factory()->create();
        $rep = User::factory()->salesrep()->create(['team_id' => $team->id]);
        $manager = User::factory()->manager()->create(['team_id' => $team->id]);
        $company = Company::factory()->ownedBy($rep)->create();

        $this->actingAs($rep, 'api')
            ->postJson("/api/v1/companies/{$company->id}/sites", ['label' => 'Head office'])
            ->assertStatus(403);

        $this->assertSame(0, $company->sites()->count());

        $this->actingAs($manager, 'api')
            ->postJson("/api/v1/companies/{$company->id}/sites", ['label' => 'Head office'])
            ->assertCreated();
    }

    public function test_a_salesrep_cannot_relink_a_branch(): void
    {
        $rep = User::factory()->salesrep()->create();
        $parent = Company::factory()->ownedBy($rep)->create();
        $company = Company::factory()->ownedBy($rep)->create();

        $this->actingAs($rep, 'api')
            ->patchJson("/api/v1/companies/{$company->id}", ['parent_company_id' => $parent->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors('parent_company_id');

        $this->assertNull($company->fresh()->parent_company_id);
    }

    public function test_the_first_site_becomes_primary(): void
    {
        $user = User::factory()->manager()->create();
        $company = Company::factory()->ownedBy($user)->create();

        $this->actingAs($user, 'api')
            ->postJson("/api/v1/companies/{$company->id}/sites", ['label' => 'Head office'])
            ->assertCreated()
            ->assertJsonPath('data.is_primary', true)
            ->assertJsonPath('data.full_address', '');
    }

    public function test_marking_a_new_primary_unsets_the_previous_one(): void
    {
        $user = User::factory()->manager()->create();
        $company = Company::factory()->ownedBy($user)->create();
        $first = CompanySite::factory()->primary()->create(['company_id' => $company->id]);

        $this->actingAs($user, 'api')
            ->postJson("/api/v1/companies/{$company->id}/sites", [
                'label' => 'Warehouse',
                'barangay' => 'Barangay San Roque',
                'city_municipality' => 'Pasig',
                'is_primary' => true,
            ])
            ->assertCreated()
            ->assertJsonPath('data.is_primary', true)
            ->assertJsonPath('data.full_address', 'Barangay San Roque, Pasig');

        $this->assertFalse($first->fresh()->is_primary);
        $this->assertSame(1, $company->sites()->where('is_primary', true)->count());
    }

    public function test_deleting_the_primary_site_promotes_another(): void
    {
        $user = User::factory()->manager()->create();
        $company = Company::factory()->ownedBy($user)->create();
        $primary = CompanySite::factory()->primary()->create(['company_id' => $company->id]);
        $other = CompanySite::factory()->create(['company_id' => $company->id]);

        $this->actingAs($user, 'api')
            ->deleteJson("/api/v1/companies/{$company->id}/sites/{$primary->id}")
            ->assertNoContent();

        $this->assertTrue($other->fresh()->is_primary);
    }

    public function test_a_site_from_another_company_is_not_found(): void
    {
        $user = User::factory()->manager()->create();
        $company = Company::factory()->ownedBy($user)->create();
        $otherCompany = Company::factory()->ownedBy($user)->create();
        $foreignSite = CompanySite::factory()->create(['company_id' => $otherCompany->id]);

        $this->actingAs($user, 'api')
            ->patchJson("/api/v1/companies/{$company->id}/sites/{$foreignSite->id}", ['label' => 'Hijacked'])
            ->assertNotFound();
    }

    public function test_updating_a_site_keeps_one_primary(): void
    {
        $user = User::factory()->manager()->create();
        $company = Company::factory()->ownedBy($user)->create();
        $primary = CompanySite::factory()->primary()->create(['company_id' => $company->id]);
        $other = CompanySite::factory()->create(['company_id' => $company->id]);

        $this->actingAs($user, 'api')
            ->patchJson("/api/v1/companies/{$company->id}/sites/{$other->id}", ['is_primary' => true])
            ->assertOk()
            ->assertJsonPath('data.is_primary', true);

        $this->assertFalse($primary->fresh()->is_primary);
    }

    public function test_company_activities_are_logged_and_listed_newest_first(): void
    {
        $user = User::factory()->manager()->create();
        $company = Company::factory()->ownedBy($user)->create();

        $this->actingAs($user, 'api')
            ->postJson("/api/v1/companies/{$company->id}/activities", [
                'type' => 'email',
                'subject' => 'Sent the rate card',
                'occurred_at' => now()->subMinutes(5)->toDateTimeString(),
            ])
            ->assertOk()
            ->assertJsonPath('activity.type', 'email')
            ->assertJsonPath('activity.subject', 'Sent the rate card')
            ->assertJsonPath('data.counts.activities', 1);

        $this->actingAs($user, 'api')
            ->postJson("/api/v1/companies/{$company->id}/activities", [
                'type' => 'meeting',
                'subject' => 'Site visit',
            ])
            ->assertOk();

        $this->actingAs($user, 'api')
            ->getJson("/api/v1/companies/{$company->id}/activities")
            ->assertOk()
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('data.0.subject', 'Site visit')
            ->assertJsonPath('data.1.subject', 'Sent the rate card')
            ->assertJsonPath('data.1.category', 'activity');
    }

    public function test_a_salesrep_cannot_log_activity_on_another_users_company(): void
    {
        $rep = User::factory()->salesrep()->create();
        $other = User::factory()->salesrep()->create();
        $company = Company::factory()->ownedBy($other)->create();

        $this->actingAs($rep, 'api')
            ->postJson("/api/v1/companies/{$company->id}/activities", [
                'type' => 'call',
                'subject' => 'Cold call',
            ])
            ->assertStatus(403);
    }

    public function test_the_company_list_supports_search_and_filters(): void
    {
        $user = User::factory()->manager()->create();
        Company::factory()->ownedBy($user)->client()->create([
            'name' => 'Active Client Co',
            'industry' => 'Food Manufacturing',
        ]);
        Company::factory()->ownedBy($user)->create([
            'name' => 'Prospect Co',
            'industry' => 'Logistics',
        ]);
        $head = Company::factory()->ownedBy($user)->create([
            'name' => 'Head Co',
            'industry' => 'Construction',
        ]);
        Company::factory()->ownedBy($user)->create([
            'name' => 'Branch Co',
            'industry' => 'Hospitality',
            'parent_company_id' => $head->id,
        ]);

        $this->actingAs($user, 'api')
            ->getJson('/api/v1/companies?filter[status]='.CompanyStatus::Active->value)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Active Client Co');

        $this->actingAs($user, 'api')
            ->getJson('/api/v1/companies?search=prospect')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Prospect Co');

        $this->actingAs($user, 'api')
            ->getJson('/api/v1/companies?filter[industry]=food')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Active Client Co');

        $this->actingAs($user, 'api')
            ->getJson("/api/v1/companies?filter[parent_company_id]={$head->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Branch Co');
    }
}
