<?php

namespace Tests\Feature;

use App\Enums\LeadStatus;
use App\Models\Lead;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LeadLifecycleTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_legal_transition_updates_status_and_records_a_system_activity(): void
    {
        $user = User::factory()->salesrep()->create();
        $lead = Lead::factory()->create([
            'owner_id' => $user->id,
            'status' => LeadStatus::Contacted,
            'estimated_headcount' => 5,
            'needed_roles' => 'Cleaners',
            'contact_email' => 'hr@acme.test',
        ]);

        $this->actingAs($user, 'api')
            ->patchJson("/api/v1/leads/{$lead->id}/status", [
                'status' => LeadStatus::Qualified->value,
                'note' => 'Spoke with HR.',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', LeadStatus::Qualified->value)
            ->assertJsonPath('data.allowed_transitions', ['disqualified', 'nurture']);

        $lead->refresh();

        $this->assertNotNull($lead->status_changed_at);
        $this->assertDatabaseHas('activities', [
            'subject_id' => $lead->id,
            'type' => 'system',
            'subject' => 'Status changed contacted → qualified',
            'body' => 'Spoke with HR.',
            'owner_id' => $user->id,
        ]);
    }

    public function test_an_illegal_transition_is_rejected(): void
    {
        $user = User::factory()->salesrep()->create();
        $lead = Lead::factory()->create([
            'owner_id' => $user->id,
            'status' => LeadStatus::New,
        ]);

        $this->actingAs($user, 'api')
            ->patchJson("/api/v1/leads/{$lead->id}/status", ['status' => LeadStatus::Qualified->value])
            ->assertStatus(422)
            ->assertJsonValidationErrors('status');

        $this->assertSame(LeadStatus::New, $lead->fresh()->status);
    }

    public function test_qualifying_via_status_still_enforces_minimum_criteria(): void
    {
        $user = User::factory()->salesrep()->create();
        $lead = Lead::factory()->create([
            'owner_id' => $user->id,
            'status' => LeadStatus::Contacted,
            'estimated_headcount' => null,
            'needed_roles' => null,
            'contact_email' => null,
            'contact_phone' => null,
        ]);

        $this->actingAs($user, 'api')
            ->patchJson("/api/v1/leads/{$lead->id}/status", ['status' => LeadStatus::Qualified->value])
            ->assertStatus(422)
            ->assertJsonValidationErrors('criteria');
    }

    public function test_disqualifying_via_status_requires_a_reason(): void
    {
        $user = User::factory()->salesrep()->create();
        $lead = Lead::factory()->create([
            'owner_id' => $user->id,
            'status' => LeadStatus::Contacted,
        ]);

        $this->actingAs($user, 'api')
            ->patchJson("/api/v1/leads/{$lead->id}/status", ['status' => LeadStatus::Disqualified->value])
            ->assertStatus(422)
            ->assertJsonValidationErrors('reason');

        $this->actingAs($user, 'api')
            ->patchJson("/api/v1/leads/{$lead->id}/status", [
                'status' => LeadStatus::Disqualified->value,
                'reason' => 'no_budget',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', LeadStatus::Disqualified->value)
            ->assertJsonPath('data.disqualification_reason', 'no_budget');
    }

    public function test_converted_leads_are_terminal(): void
    {
        $user = User::factory()->salesrep()->create();
        $lead = Lead::factory()->create([
            'owner_id' => $user->id,
            'status' => LeadStatus::Converted,
        ]);

        $this->actingAs($user, 'api')
            ->patchJson("/api/v1/leads/{$lead->id}/status", ['status' => LeadStatus::Contacted->value])
            ->assertStatus(422)
            ->assertJsonValidationErrors('status');

        $this->assertSame(LeadStatus::Converted, $lead->fresh()->status);
    }

    public function test_explicit_conversion_is_deferred_to_the_conversion_flow(): void
    {
        $user = User::factory()->salesrep()->create();
        $lead = Lead::factory()->create([
            'owner_id' => $user->id,
            'status' => LeadStatus::Qualified,
        ]);

        $this->actingAs($user, 'api')
            ->patchJson("/api/v1/leads/{$lead->id}/status", ['status' => LeadStatus::Converted->value])
            ->assertStatus(422)
            ->assertJsonValidationErrors('status');
    }

    public function test_archived_leads_are_hidden_from_default_lists(): void
    {
        $user = User::factory()->salesrep()->create();
        $lead = Lead::factory()->create([
            'owner_id' => $user->id,
            'status' => LeadStatus::Contacted,
        ]);

        $this->actingAs($user, 'api')
            ->postJson("/api/v1/leads/{$lead->id}/archive")
            ->assertOk()
            ->assertJsonPath('data.status', LeadStatus::Archived->value)
            ->assertJsonPath('data.is_archived', true);

        $this->actingAs($user, 'api')
            ->getJson('/api/v1/leads')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->actingAs($user, 'api')
            ->getJson('/api/v1/leads?filter[status]=archived')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $lead->id);

        $this->actingAs($user, 'api')
            ->getJson('/api/v1/leads/summary')
            ->assertOk()
            ->assertJsonPath('data.all', 0)
            ->assertJsonPath('data.archived', 1);
    }

    public function test_archiving_a_qualified_lead_is_rejected(): void
    {
        $user = User::factory()->salesrep()->create();
        $lead = Lead::factory()->create([
            'owner_id' => $user->id,
            'status' => LeadStatus::Qualified,
        ]);

        $this->actingAs($user, 'api')
            ->postJson("/api/v1/leads/{$lead->id}/archive")
            ->assertStatus(422)
            ->assertJsonValidationErrors('status');
    }

    public function test_logging_the_first_outbound_activity_advances_new_to_contacted(): void
    {
        $user = User::factory()->salesrep()->create();
        $lead = Lead::factory()->create([
            'owner_id' => $user->id,
            'status' => LeadStatus::New,
        ]);

        $this->actingAs($user, 'api')
            ->postJson("/api/v1/leads/{$lead->id}/activities", [
                'type' => 'call',
                'subject' => 'Intro call',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', LeadStatus::Contacted->value)
            ->assertJsonPath('activity.type', 'call')
            ->assertJsonPath('activity.subject', 'Intro call');

        $lead->refresh();

        $this->assertSame(LeadStatus::Contacted, $lead->status);
        $this->assertNotNull($lead->last_activity_at);
        // The logged call plus the system status change.
        $this->assertSame(2, $lead->activities()->count());
    }

    public function test_a_note_activity_does_not_advance_status(): void
    {
        $user = User::factory()->salesrep()->create();
        $lead = Lead::factory()->create([
            'owner_id' => $user->id,
            'status' => LeadStatus::New,
        ]);

        $this->actingAs($user, 'api')
            ->postJson("/api/v1/leads/{$lead->id}/activities", [
                'type' => 'note',
                'subject' => 'Left a voicemail',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', LeadStatus::New->value);

        $this->assertSame(LeadStatus::New, $lead->fresh()->status);
        $this->assertSame(1, $lead->activities()->count());
    }

    public function test_timeline_returns_status_history_newest_first(): void
    {
        $user = User::factory()->salesrep()->create();
        $lead = Lead::factory()->create([
            'owner_id' => $user->id,
            'status' => LeadStatus::New,
        ]);

        $this->actingAs($user, 'api')->postJson("/api/v1/leads/{$lead->id}/activities", [
            'type' => 'email',
            'subject' => 'Sent intro deck',
            'occurred_at' => now()->subMinutes(5)->toDateTimeString(),
        ]);

        $this->actingAs($user, 'api')
            ->getJson("/api/v1/leads/{$lead->id}/timeline")
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.category', 'system')
            ->assertJsonPath('data.0.subject', 'Status changed new → contacted')
            ->assertJsonPath('data.1.category', 'activity')
            ->assertJsonPath('data.1.subject', 'Sent intro deck')
            ->assertJsonPath('meta.status', LeadStatus::Contacted->value)
            ->assertJsonPath('meta.aging', null);
    }

    public function test_stale_leads_are_filterable_and_flagged(): void
    {
        $user = User::factory()->salesrep()->create();

        $stale = Lead::factory()->create([
            'owner_id' => $user->id,
            'status' => LeadStatus::Contacted,
        ]);
        $stale->forceFill(['last_activity_at' => now()->subDays(20)])->save();

        $fresh = Lead::factory()->create([
            'owner_id' => $user->id,
            'status' => LeadStatus::Contacted,
        ]);

        $this->actingAs($user, 'api')
            ->getJson('/api/v1/leads?filter[stale]=true')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $stale->id)
            ->assertJsonPath('data.0.aging', 'stale');

        $this->actingAs($user, 'api')
            ->getJson("/api/v1/leads/{$fresh->id}")
            ->assertOk()
            ->assertJsonPath('data.aging', null);
    }

    public function test_a_salesrep_cannot_transition_another_users_lead(): void
    {
        $rep = User::factory()->salesrep()->create();
        $other = User::factory()->salesrep()->create();
        $lead = Lead::factory()->create([
            'owner_id' => $other->id,
            'status' => LeadStatus::Contacted,
        ]);

        $this->actingAs($rep, 'api')
            ->patchJson("/api/v1/leads/{$lead->id}/status", ['status' => LeadStatus::Qualified->value])
            ->assertStatus(403);

        $this->actingAs($rep, 'api')
            ->postJson("/api/v1/leads/{$lead->id}/activities", [
                'type' => 'call',
                'subject' => 'Cold call',
            ])
            ->assertStatus(403);
    }

    public function test_a_salesrep_must_claim_a_queued_lead_before_logging_activity(): void
    {
        $user = User::factory()->salesrep()->create();
        $lead = Lead::factory()->create(['owner_id' => null, 'status' => LeadStatus::New]);

        $this->actingAs($user, 'api')
            ->postJson("/api/v1/leads/{$lead->id}/activities", [
                'type' => 'call',
                'subject' => 'Queue call',
            ])
            ->assertStatus(403);

        $this->actingAs($user, 'api')
            ->postJson("/api/v1/leads/{$lead->id}/claim")
            ->assertOk();

        $this->actingAs($user, 'api')
            ->postJson("/api/v1/leads/{$lead->id}/activities", [
                'type' => 'call',
                'subject' => 'Queue call',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', LeadStatus::Contacted->value);
    }
}
