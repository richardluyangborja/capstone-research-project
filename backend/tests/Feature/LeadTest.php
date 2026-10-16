<?php

namespace Tests\Feature;

use App\Enums\LeadSource;
use App\Enums\LeadStatus;
use App\Models\Lead;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LeadTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'company_name' => 'Acme Manufacturing',
            'contact_name' => 'Juan Dela Cruz',
            'contact_email' => 'juan@acme.test',
            'source' => LeadSource::Referral->value,
        ], $overrides);
    }

    public function test_guests_cannot_list_leads(): void
    {
        $this->getJson('/api/v1/leads')->assertStatus(401);
    }

    public function test_it_creates_a_lead_with_a_generated_reference(): void
    {
        $user = User::factory()->salesrep()->create();

        $response = $this->actingAs($user, 'api')
            ->postJson('/api/v1/leads', $this->payload());

        $response->assertCreated()
            ->assertJsonPath('data.company_name', 'Acme Manufacturing')
            ->assertJsonPath('data.status', LeadStatus::New->value);

        $this->assertStringStartsWith('LD-', $response->json('data.reference'));
        $this->assertDatabaseHas('leads', ['company_name' => 'Acme Manufacturing']);
    }

    public function test_source_is_required(): void
    {
        $user = User::factory()->salesrep()->create();

        $this->actingAs($user, 'api')
            ->postJson('/api/v1/leads', $this->payload(['source' => null]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('source');
    }

    public function test_at_least_one_contact_channel_is_required(): void
    {
        $user = User::factory()->salesrep()->create();

        $this->actingAs($user, 'api')
            ->postJson('/api/v1/leads', $this->payload(['contact_email' => null, 'contact_phone' => null]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['contact_email', 'contact_phone']);
    }

    public function test_it_returns_advisory_duplicates_without_blocking(): void
    {
        $user = User::factory()->salesrep()->create();
        Lead::factory()->create([
            'company_name' => 'Acme Manufacturing',
            'contact_email' => 'old@acme.test',
        ]);

        $response = $this->actingAs($user, 'api')
            ->postJson('/api/v1/leads', $this->payload());

        $response->assertCreated();
        $this->assertNotEmpty($response->json('duplicates'));
    }

    public function test_a_salesrep_only_sees_their_own_leads(): void
    {
        $team = Team::factory()->create();
        $rep = User::factory()->salesrep()->create(['team_id' => $team->id]);
        $other = User::factory()->salesrep()->create(['team_id' => $team->id]);

        Lead::factory()->create(['owner_id' => $rep->id]);
        Lead::factory()->create(['owner_id' => $other->id]);

        $response = $this->actingAs($rep, 'api')->getJson('/api/v1/leads');

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
    }

    public function test_a_manager_sees_their_team_leads(): void
    {
        $team = Team::factory()->create();
        $manager = User::factory()->manager()->create(['team_id' => $team->id]);
        $rep = User::factory()->salesrep()->create(['team_id' => $team->id]);
        $outsider = User::factory()->salesrep()->create();

        Lead::factory()->create(['owner_id' => $rep->id]);
        Lead::factory()->create(['owner_id' => $outsider->id]);

        $response = $this->actingAs($manager, 'api')->getJson('/api/v1/leads');

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
    }

    public function test_a_salesrep_cannot_view_another_users_lead(): void
    {
        $rep = User::factory()->salesrep()->create();
        $other = User::factory()->salesrep()->create();
        $lead = Lead::factory()->create(['owner_id' => $other->id]);

        $this->actingAs($rep, 'api')
            ->getJson("/api/v1/leads/{$lead->id}")
            ->assertStatus(403);
    }

    public function test_deleting_soft_deletes_and_can_be_restored(): void
    {
        $user = User::factory()->admin()->create();
        $lead = Lead::factory()->create(['owner_id' => $user->id]);

        $this->actingAs($user, 'api')
            ->deleteJson("/api/v1/leads/{$lead->id}")
            ->assertNoContent();

        $this->assertSoftDeleted('leads', ['id' => $lead->id]);

        $this->actingAs($user, 'api')
            ->postJson("/api/v1/leads/{$lead->id}/restore")
            ->assertOk();

        $this->assertDatabaseHas('leads', ['id' => $lead->id, 'deleted_at' => null]);
    }

    public function test_budget_rate_is_hidden_from_salesrep(): void
    {
        $rep = User::factory()->salesrep()->create();
        $lead = Lead::factory()->create(['owner_id' => $rep->id, 'budget_rate' => 500]);

        $this->actingAs($rep, 'api')
            ->getJson("/api/v1/leads/{$lead->id}")
            ->assertOk()
            ->assertJsonMissingPath('data.budget_rate');
    }
}
