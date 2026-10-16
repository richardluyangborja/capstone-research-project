<?php

namespace Tests\Feature;

use App\Enums\ActivityType;
use App\Models\Activity;
use App\Models\Lead;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LeadAssignmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_salesrep_cannot_assign_a_lead_to_another_user(): void
    {
        $rep = User::factory()->salesrep()->create();
        $other = User::factory()->salesrep()->create();
        $lead = Lead::factory()->create(['owner_id' => null]);

        $this->actingAs($rep, 'api')
            ->postJson("/api/v1/leads/{$lead->id}/assign", ['owner_id' => $other->id])
            ->assertStatus(403);

        $this->assertNull($lead->fresh()->owner_id);
    }

    public function test_a_salesrep_can_claim_a_lead_from_the_queue(): void
    {
        $rep = User::factory()->salesrep()->create();
        $lead = Lead::factory()->create(['owner_id' => null]);

        $this->actingAs($rep, 'api')
            ->postJson("/api/v1/leads/{$lead->id}/claim")
            ->assertOk()
            ->assertJsonPath('data.owner_id', $rep->id);

        $this->assertSame($rep->id, $lead->fresh()->owner_id);
    }

    public function test_the_unassigned_queue_is_visible_to_salesreps(): void
    {
        $rep = User::factory()->salesrep()->create();
        Lead::factory()->create(['owner_id' => null]);

        $this->actingAs($rep, 'api')
            ->getJson('/api/v1/leads?filter[owner]=unassigned')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_a_salesrep_must_claim_before_editing_a_queued_lead(): void
    {
        $rep = User::factory()->salesrep()->create();
        $lead = Lead::factory()->create(['owner_id' => null]);

        $this->actingAs($rep, 'api')
            ->patchJson("/api/v1/leads/{$lead->id}/qualification", ['estimated_headcount' => 5])
            ->assertStatus(403);
    }

    public function test_a_manager_can_assign_within_their_team(): void
    {
        $team = Team::factory()->create();
        $manager = User::factory()->manager()->create(['team_id' => $team->id]);
        $rep = User::factory()->salesrep()->create(['team_id' => $team->id]);
        $lead = Lead::factory()->create(['owner_id' => null]);

        $this->actingAs($manager, 'api')
            ->postJson("/api/v1/leads/{$lead->id}/assign", ['owner_id' => $rep->id])
            ->assertOk()
            ->assertJsonPath('data.owner_id', $rep->id);
    }

    public function test_a_manager_cannot_assign_outside_their_team(): void
    {
        $team = Team::factory()->create();
        $manager = User::factory()->manager()->create(['team_id' => $team->id]);
        $outsider = User::factory()->salesrep()->create();
        $lead = Lead::factory()->create(['owner_id' => null]);

        $this->actingAs($manager, 'api')
            ->postJson("/api/v1/leads/{$lead->id}/assign", ['owner_id' => $outsider->id])
            ->assertStatus(403);
    }

    public function test_an_admin_can_assign_to_any_salesrep(): void
    {
        $admin = User::factory()->admin()->create();
        $rep = User::factory()->salesrep()->create(['team_id' => Team::factory()->create()->id]);
        $lead = Lead::factory()->create(['owner_id' => null]);

        $this->actingAs($admin, 'api')
            ->postJson("/api/v1/leads/{$lead->id}/assign", ['owner_id' => $rep->id])
            ->assertOk();
    }

    public function test_reassignment_records_an_activity_and_notifies_the_new_owner(): void
    {
        $admin = User::factory()->admin()->create();
        $rep = User::factory()->salesrep()->create();
        $lead = Lead::factory()->create(['owner_id' => null]);

        $this->actingAs($admin, 'api')
            ->postJson("/api/v1/leads/{$lead->id}/assign", ['owner_id' => $rep->id])
            ->assertOk();

        $activity = Activity::query()
            ->where('subject_type', Lead::class)
            ->where('subject_id', $lead->id)
            ->where('type', ActivityType::System)
            ->first();

        $this->assertNotNull($activity);
        $this->assertStringContainsString('Owner changed Unassigned', $activity->subject);
        $this->assertStringContainsString($rep->name, $activity->subject);

        $this->assertDatabaseCount('notifications', 1);
        $this->assertSame(1, $rep->fresh()->unreadNotifications()->count());
    }

    public function test_a_manager_can_bulk_assign(): void
    {
        $team = Team::factory()->create();
        $manager = User::factory()->manager()->create(['team_id' => $team->id]);
        $rep = User::factory()->salesrep()->create(['team_id' => $team->id]);
        $leads = Lead::factory()->count(3)->create(['owner_id' => null]);

        $this->actingAs($manager, 'api')
            ->postJson('/api/v1/leads/bulk-assign', [
                'lead_ids' => $leads->pluck('id')->all(),
                'owner_id' => $rep->id,
            ])
            ->assertOk()
            ->assertJsonPath('data.assigned', 3);

        $this->assertSame(3, Lead::where('owner_id', $rep->id)->count());
    }

    public function test_a_manager_can_bulk_transfer_within_their_team(): void
    {
        $team = Team::factory()->create();
        $manager = User::factory()->manager()->create(['team_id' => $team->id]);
        $from = User::factory()->salesrep()->create(['team_id' => $team->id]);
        $to = User::factory()->salesrep()->create(['team_id' => $team->id]);
        Lead::factory()->count(2)->create(['owner_id' => $from->id]);

        $this->actingAs($manager, 'api')
            ->postJson('/api/v1/leads/bulk-transfer', [
                'from_user_id' => $from->id,
                'to_user_id' => $to->id,
            ])
            ->assertOk()
            ->assertJsonPath('data.transferred', 2);

        $this->assertSame(0, Lead::where('owner_id', $from->id)->count());
        $this->assertSame(2, Lead::where('owner_id', $to->id)->count());
    }

    public function test_a_salesrep_cannot_bulk_transfer(): void
    {
        $rep = User::factory()->salesrep()->create();
        $other = User::factory()->salesrep()->create();

        $this->actingAs($rep, 'api')
            ->postJson('/api/v1/leads/bulk-transfer', [
                'from_user_id' => $rep->id,
                'to_user_id' => $other->id,
            ])
            ->assertStatus(403);
    }

    public function test_assignable_users_are_scoped_by_role(): void
    {
        $team = Team::factory()->create();
        $manager = User::factory()->manager()->create(['team_id' => $team->id]);
        $inTeam = User::factory()->salesrep()->create(['team_id' => $team->id]);
        User::factory()->salesrep()->create(); // outside the team

        $this->actingAs($manager, 'api')
            ->getJson('/api/v1/users/assignable')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $inTeam->id);
    }

    public function test_notifications_can_be_listed_and_marked_read(): void
    {
        $admin = User::factory()->admin()->create();
        $rep = User::factory()->salesrep()->create();
        $lead = Lead::factory()->create(['owner_id' => null]);

        $this->actingAs($admin, 'api')
            ->postJson("/api/v1/leads/{$lead->id}/assign", ['owner_id' => $rep->id]);

        $response = $this->actingAs($rep, 'api')->getJson('/api/v1/notifications');

        $response->assertOk()->assertJsonPath('meta.unread', 1);
        $this->assertStringContainsString('assigned you', $response->json('data.0.message'));

        $this->actingAs($rep, 'api')
            ->postJson("/api/v1/notifications/{$response->json('data.0.id')}/read")
            ->assertOk()
            ->assertJsonPath('meta.unread', 0);
    }

    public function test_lead_summary_counts(): void
    {
        $team = Team::factory()->create();
        $manager = User::factory()->manager()->create(['team_id' => $team->id]);
        $rep = User::factory()->salesrep()->create(['team_id' => $team->id]);

        Lead::factory()->create(['owner_id' => $rep->id]);
        Lead::factory()->create(['owner_id' => null]);

        $this->actingAs($manager, 'api')
            ->getJson('/api/v1/leads/summary')
            ->assertOk()
            ->assertJsonPath('data.all', 2)
            ->assertJsonPath('data.unassigned', 1)
            ->assertJsonPath('data.team', 1);
    }

    public function test_timeline_endpoint_lists_assignment_history(): void
    {
        $admin = User::factory()->admin()->create();
        $rep = User::factory()->salesrep()->create();
        $lead = Lead::factory()->create(['owner_id' => null]);

        $this->actingAs($admin, 'api')
            ->postJson("/api/v1/leads/{$lead->id}/assign", ['owner_id' => $rep->id]);

        $this->actingAs($admin, 'api')
            ->getJson("/api/v1/leads/{$lead->id}/timeline")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.type', ActivityType::System->value)
            ->assertJsonPath('data.0.category', 'system');
    }
}
