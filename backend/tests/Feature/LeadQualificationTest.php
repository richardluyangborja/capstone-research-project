<?php

namespace Tests\Feature;

use App\Enums\DisqualificationReason;
use App\Enums\LeadRating;
use App\Enums\LeadStatus;
use App\Models\Lead;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LeadQualificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_qualify_fails_when_minimum_criteria_are_missing(): void
    {
        $user = User::factory()->salesrep()->create();
        $lead = Lead::factory()->create([
            'owner_id' => $user->id,
            'status' => LeadStatus::Contacted,
        ]);

        $this->actingAs($user, 'api')
            ->postJson("/api/v1/leads/{$lead->id}/qualify")
            ->assertStatus(422)
            ->assertJsonValidationErrors('criteria');

        $this->assertSame(LeadStatus::Contacted, $lead->fresh()->status);
    }

    public function test_qualify_succeeds_when_criteria_are_met(): void
    {
        $user = User::factory()->salesrep()->create();
        $lead = Lead::factory()->create([
            'owner_id' => $user->id,
            'status' => LeadStatus::Contacted,
            'estimated_headcount' => 8,
            'needed_roles' => 'Warehouse staff, forklift operators',
            'contact_email' => 'hr@acme.test',
        ]);

        $response = $this->actingAs($user, 'api')
            ->postJson("/api/v1/leads/{$lead->id}/qualify");

        $response->assertOk()
            ->assertJsonPath('data.status', LeadStatus::Qualified->value);

        $this->assertNotNull($response->json('data.rating'));
        $this->assertGreaterThan(0, $response->json('data.score'));
    }

    public function test_disqualify_requires_a_reason(): void
    {
        $user = User::factory()->salesrep()->create();
        $lead = Lead::factory()->create(['owner_id' => $user->id]);

        $this->actingAs($user, 'api')
            ->postJson("/api/v1/leads/{$lead->id}/disqualify", [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('reason');
    }

    public function test_disqualify_sets_status_and_reason(): void
    {
        $user = User::factory()->salesrep()->create();
        $lead = Lead::factory()->create(['owner_id' => $user->id]);

        $this->actingAs($user, 'api')
            ->postJson("/api/v1/leads/{$lead->id}/disqualify", [
                'reason' => DisqualificationReason::NoBudget->value,
            ])
            ->assertOk()
            ->assertJsonPath('data.status', LeadStatus::Disqualified->value)
            ->assertJsonPath('data.disqualification_reason', DisqualificationReason::NoBudget->value);
    }

    public function test_nurture_sets_status_without_a_reason(): void
    {
        $user = User::factory()->salesrep()->create();
        $lead = Lead::factory()->create([
            'owner_id' => $user->id,
            'status' => LeadStatus::Contacted,
        ]);

        $this->actingAs($user, 'api')
            ->postJson("/api/v1/leads/{$lead->id}/nurture")
            ->assertOk()
            ->assertJsonPath('data.status', LeadStatus::Nurture->value);
    }

    public function test_rating_persists_and_is_filterable(): void
    {
        $user = User::factory()->salesrep()->create();
        $hot = Lead::factory()->create(['owner_id' => $user->id]);
        $cold = Lead::factory()->create(['owner_id' => $user->id]);

        $this->actingAs($user, 'api')
            ->postJson("/api/v1/leads/{$hot->id}/rate", ['rating' => LeadRating::Hot->value])
            ->assertOk()
            ->assertJsonPath('data.rating', LeadRating::Hot->value);

        $response = $this->actingAs($user, 'api')
            ->getJson('/api/v1/leads?filter[rating]=hot');

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
        $this->assertSame($hot->id, $response->json('data.0.id'));
        $this->assertNull($cold->fresh()->rating);
    }

    public function test_updating_qualification_recomputes_the_score(): void
    {
        $user = User::factory()->salesrep()->create();
        $lead = Lead::factory()->create(['owner_id' => $user->id]);

        $response = $this->actingAs($user, 'api')
            ->patchJson("/api/v1/leads/{$lead->id}/qualification", [
                'estimated_headcount' => 10,
                'needed_roles' => 'Nurses',
                'authority_confirmed' => true,
                'work_site' => 'Makati CBD',
            ]);

        $response->assertOk();
        // headcount 20 + roles 20 + contact 10 + authority 15 + site 10 = 75
        $this->assertSame(75, $response->json('data.score'));
    }

    public function test_qualification_edits_are_blocked_once_disqualified(): void
    {
        $user = User::factory()->salesrep()->create();
        $lead = Lead::factory()->create([
            'owner_id' => $user->id,
            'status' => LeadStatus::Disqualified,
        ]);

        $this->actingAs($user, 'api')
            ->postJson("/api/v1/leads/{$lead->id}/qualify")
            ->assertStatus(422)
            ->assertJsonValidationErrors('status');
    }

    public function test_a_salesrep_cannot_qualify_another_users_lead(): void
    {
        $rep = User::factory()->salesrep()->create();
        $other = User::factory()->salesrep()->create();
        $lead = Lead::factory()->create(['owner_id' => $other->id]);

        $this->actingAs($rep, 'api')
            ->postJson("/api/v1/leads/{$lead->id}/rate", ['rating' => LeadRating::Warm->value])
            ->assertStatus(403);
    }

    public function test_budget_rate_is_hidden_from_salesrep_but_visible_to_manager(): void
    {
        $team = Team::factory()->create();
        $rep = User::factory()->salesrep()->create(['team_id' => $team->id]);
        $manager = User::factory()->manager()->create(['team_id' => $team->id]);
        $lead = Lead::factory()->create(['owner_id' => $rep->id, 'budget_rate' => 500]);

        $this->actingAs($rep, 'api')
            ->getJson("/api/v1/leads/{$lead->id}")
            ->assertOk()
            ->assertJsonMissingPath('data.budget_rate');

        $this->actingAs($manager, 'api')
            ->getJson("/api/v1/leads/{$lead->id}")
            ->assertOk()
            ->assertJsonPath('data.budget_rate', '500.00');
    }
}
