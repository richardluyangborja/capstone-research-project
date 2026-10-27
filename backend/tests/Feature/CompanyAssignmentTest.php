<?php

namespace Tests\Feature;

use App\Enums\ActivityType;
use App\Models\Activity;
use App\Models\Company;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CompanyAssignmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_manager_can_assign_a_team_account_and_the_new_owner_is_told(): void
    {
        $team = Team::factory()->create();
        $manager = User::factory()->manager()->create(['team_id' => $team->id]);
        $rep = User::factory()->salesrep()->create(['team_id' => $team->id]);
        $company = Company::factory()->ownedBy($manager)->create();

        $this->actingAs($manager, 'api')
            ->postJson("/api/v1/companies/{$company->id}/assign", ['owner_id' => $rep->id])
            ->assertOk()
            ->assertJsonPath('data.owner_id', $rep->id)
            ->assertJsonPath('data.owner.name', $rep->name);

        $this->assertSame($rep->id, $company->fresh()->owner_id);

        $activity = Activity::query()
            ->where('subject_type', Company::class)
            ->where('subject_id', $company->id)
            ->where('type', ActivityType::System)
            ->first();

        $this->assertNotNull($activity);
        $this->assertStringContainsString('Account owner changed', $activity->subject);
        $this->assertStringContainsString($manager->name, $activity->subject);
        $this->assertStringContainsString($rep->name, $activity->subject);
        $this->assertSame($manager->id, $activity->owner_id);

        $this->assertSame(1, $rep->fresh()->unreadNotifications()->count());
        $this->assertSame(0, $manager->fresh()->notifications()->count());
    }

    public function test_the_assignment_notification_opens_the_account(): void
    {
        $team = Team::factory()->create();
        $manager = User::factory()->manager()->create(['team_id' => $team->id]);
        $rep = User::factory()->salesrep()->create(['team_id' => $team->id]);
        $company = Company::factory()->ownedBy($manager)->create();

        $this->actingAs($manager, 'api')
            ->postJson("/api/v1/companies/{$company->id}/assign", ['owner_id' => $rep->id])
            ->assertOk();

        $this->actingAs($rep, 'api')
            ->getJson('/api/v1/notifications')
            ->assertOk()
            ->assertJsonPath('meta.unread', 1)
            ->assertJsonPath('data.0.type', 'company_assigned')
            ->assertJsonPath('data.0.url', '/accounts/'.$company->id)
            ->assertJsonPath('data.0.reference', $company->reference);
    }

    public function test_a_manager_cannot_assign_an_account_to_someone_outside_their_team(): void
    {
        $team = Team::factory()->create();
        $manager = User::factory()->manager()->create(['team_id' => $team->id]);
        $outsider = User::factory()->salesrep()->create();
        $company = Company::factory()->ownedBy($manager)->create();

        $this->actingAs($manager, 'api')
            ->postJson("/api/v1/companies/{$company->id}/assign", ['owner_id' => $outsider->id])
            ->assertStatus(403);

        $this->assertSame($manager->id, $company->fresh()->owner_id);
        $this->assertSame(0, $outsider->fresh()->notifications()->count());
    }

    public function test_a_manager_cannot_assign_an_account_they_cannot_see(): void
    {
        $team = Team::factory()->create();
        $manager = User::factory()->manager()->create(['team_id' => $team->id]);
        $rep = User::factory()->salesrep()->create(['team_id' => $team->id]);
        $otherTeam = Team::factory()->create();
        $otherRep = User::factory()->salesrep()->create(['team_id' => $otherTeam->id]);
        $company = Company::factory()->ownedBy($otherRep)->create();

        $this->actingAs($manager, 'api')
            ->postJson("/api/v1/companies/{$company->id}/assign", ['owner_id' => $rep->id])
            ->assertStatus(403);

        $this->assertSame($otherRep->id, $company->fresh()->owner_id);
    }

    public function test_an_admin_can_assign_any_account_to_any_user(): void
    {
        $admin = User::factory()->admin()->create();
        $owner = User::factory()->salesrep()->create(['team_id' => Team::factory()->create()->id]);
        $rep = User::factory()->salesrep()->create(['team_id' => Team::factory()->create()->id]);
        $company = Company::factory()->ownedBy($owner)->create();

        $this->actingAs($admin, 'api')
            ->postJson("/api/v1/companies/{$company->id}/assign", ['owner_id' => $rep->id])
            ->assertOk()
            ->assertJsonPath('data.owner_id', $rep->id);
    }

    public function test_a_salesrep_can_only_assign_themselves(): void
    {
        $rep = User::factory()->salesrep()->create();
        $other = User::factory()->salesrep()->create();
        $company = Company::factory()->ownedBy($rep)->create();

        $this->actingAs($rep, 'api')
            ->postJson("/api/v1/companies/{$company->id}/assign", ['owner_id' => $other->id])
            ->assertStatus(403);

        $this->actingAs($rep, 'api')
            ->postJson("/api/v1/companies/{$company->id}/assign", ['owner_id' => $rep->id])
            ->assertOk();

        // Assigning an account to its existing owner changes nothing — no activity, no bell.
        $this->assertSame(0, $company->activities()->where('type', ActivityType::System)->count());
        $this->assertSame(0, $rep->fresh()->notifications()->count());
    }

    public function test_a_manager_can_bulk_assign_and_a_repeat_run_is_a_no_op(): void
    {
        $team = Team::factory()->create();
        $manager = User::factory()->manager()->create(['team_id' => $team->id]);
        $rep = User::factory()->salesrep()->create(['team_id' => $team->id]);
        $companies = Company::factory()->count(3)->ownedBy($manager)->create();

        $this->actingAs($manager, 'api')
            ->postJson('/api/v1/companies/bulk-assign', [
                'company_ids' => $companies->pluck('id')->all(),
                'owner_id' => $rep->id,
            ])
            ->assertOk()
            ->assertJsonPath('data.assigned', 3)
            ->assertJsonPath('data.unchanged', 0);

        $this->assertSame(3, Company::where('owner_id', $rep->id)->count());
        $this->assertSame(3, $rep->fresh()->unreadNotifications()->count());

        $this->actingAs($manager, 'api')
            ->postJson('/api/v1/companies/bulk-assign', [
                'company_ids' => $companies->pluck('id')->all(),
                'owner_id' => $rep->id,
            ])
            ->assertOk()
            ->assertJsonPath('data.assigned', 0)
            ->assertJsonPath('data.unchanged', 3);

        $this->assertSame(3, $rep->fresh()->unreadNotifications()->count());
    }

    public function test_a_salesrep_cannot_bulk_assign(): void
    {
        $rep = User::factory()->salesrep()->create();
        $company = Company::factory()->ownedBy($rep)->create();

        $this->actingAs($rep, 'api')
            ->postJson('/api/v1/companies/bulk-assign', [
                'company_ids' => [$company->id],
                'owner_id' => $rep->id,
            ])
            ->assertStatus(403);
    }

    public function test_bulk_assign_rejects_a_company_outside_the_callers_scope(): void
    {
        $team = Team::factory()->create();
        $manager = User::factory()->manager()->create(['team_id' => $team->id]);
        $rep = User::factory()->salesrep()->create(['team_id' => $team->id]);
        $mine = Company::factory()->ownedBy($manager)->create();
        $theirs = Company::factory()->ownedBy(User::factory()->salesrep()->create())->create();

        $this->actingAs($manager, 'api')
            ->postJson('/api/v1/companies/bulk-assign', [
                'company_ids' => [$mine->id, $theirs->id],
                'owner_id' => $rep->id,
            ])
            ->assertStatus(403);

        // The batch is authorized before it moves, so the allowed half is untouched too.
        $this->assertSame($manager->id, $mine->fresh()->owner_id);
        $this->assertSame(0, $rep->fresh()->notifications()->count());
    }

    public function test_bulk_assign_validates_its_payload(): void
    {
        $manager = User::factory()->manager()->create();

        $this->actingAs($manager, 'api')
            ->postJson('/api/v1/companies/bulk-assign', ['company_ids' => [], 'owner_id' => null])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['company_ids', 'owner_id']);
    }

    public function test_updating_owner_through_the_company_patch_runs_the_delegation_path(): void
    {
        $team = Team::factory()->create();
        $manager = User::factory()->manager()->create(['team_id' => $team->id]);
        $rep = User::factory()->salesrep()->create(['team_id' => $team->id]);
        $company = Company::factory()->ownedBy($manager)->create();

        $this->actingAs($manager, 'api')
            ->patchJson("/api/v1/companies/{$company->id}", [
                'name' => 'Renamed Account',
                'owner_id' => $rep->id,
            ])
            ->assertOk()
            ->assertJsonPath('data.name', 'Renamed Account')
            ->assertJsonPath('data.owner_id', $rep->id);

        $this->assertSame(1, $company->activities()->where('type', ActivityType::System)->count());
        $this->assertSame(1, $rep->fresh()->unreadNotifications()->count());
    }
}
