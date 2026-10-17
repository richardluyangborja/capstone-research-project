<?php

namespace Tests\Feature;

use App\Enums\CompanyStatus;
use App\Enums\LostReason;
use App\Enums\OpportunityStage;
use App\Events\OpportunityWon;
use App\Integrations\ServiceHandoff;
use App\Models\Company;
use App\Models\Opportunity;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class OpportunityPipelineTest extends TestCase
{
    use RefreshDatabase;

    private function dealFor(User $owner, OpportunityStage $stage = OpportunityStage::NewInquiry, ?Company $company = null): Opportunity
    {
        $company ??= Company::factory()->ownedBy($owner)->create();

        return Opportunity::factory()->forCompany($company)->ownedBy($owner)->stage($stage)->create();
    }

    public function test_a_deal_can_move_to_any_other_stage_and_records_the_move(): void
    {
        $rep = User::factory()->salesrep()->create();
        $deal = $this->dealFor($rep, OpportunityStage::NewInquiry);

        $this->actingAs($rep, 'api')
            ->postJson("/api/v1/opportunities/{$deal->id}/stage", ['stage' => 'negotiation'])
            ->assertOk()
            ->assertJsonPath('data.stage', 'negotiation')
            ->assertJsonPath('data.probability', 80);

        $deal->refresh();
        $this->assertSame(OpportunityStage::Negotiation, $deal->stage);
        $this->assertNotNull($deal->stage_changed_at);
        $this->assertNotNull($deal->last_activity_at);

        $this->assertDatabaseHas('activities', [
            'subject_type' => Opportunity::class,
            'subject_id' => $deal->id,
            'subject' => 'Stage changed new_inquiry → negotiation',
        ]);
    }

    public function test_a_deal_cannot_stay_in_its_own_stage(): void
    {
        $rep = User::factory()->salesrep()->create();
        $deal = $this->dealFor($rep, OpportunityStage::Discovery);

        $this->actingAs($rep, 'api')
            ->postJson("/api/v1/opportunities/{$deal->id}/stage", ['stage' => 'discovery'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('stage');
    }

    public function test_a_terminal_deal_can_only_be_reopened_to_an_open_stage(): void
    {
        $rep = User::factory()->salesrep()->create();
        $won = $this->dealFor($rep, OpportunityStage::Won);

        // won → lost (terminal → terminal) is not a reopen.
        $this->actingAs($rep, 'api')
            ->postJson("/api/v1/opportunities/{$won->id}/stage", ['stage' => 'lost', 'lost_reason' => 'price'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('stage');

        // won → negotiation reopens the deal.
        $this->actingAs($rep, 'api')
            ->postJson("/api/v1/opportunities/{$won->id}/stage", ['stage' => 'negotiation'])
            ->assertOk()
            ->assertJsonPath('data.stage', 'negotiation');
    }

    public function test_losing_a_deal_requires_a_reason(): void
    {
        $rep = User::factory()->salesrep()->create();
        $deal = $this->dealFor($rep, OpportunityStage::Negotiation);

        $this->actingAs($rep, 'api')
            ->postJson("/api/v1/opportunities/{$deal->id}/stage", ['stage' => 'lost'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('lost_reason');

        $this->actingAs($rep, 'api')
            ->postJson("/api/v1/opportunities/{$deal->id}/stage", ['stage' => 'lost', 'lost_reason' => 'price'])
            ->assertOk()
            ->assertJsonPath('data.lost_reason', 'price')
            ->assertJsonPath('data.is_lost', true);

        $deal->refresh();
        $this->assertSame(LostReason::Price, $deal->lost_reason);
        $this->assertNotNull($deal->actual_close_date);
    }

    public function test_winning_a_deal_stamps_the_close_date_and_full_probability(): void
    {
        $rep = User::factory()->salesrep()->create();
        $deal = $this->dealFor($rep, OpportunityStage::Negotiation);

        $this->actingAs($rep, 'api')
            ->postJson("/api/v1/opportunities/{$deal->id}/stage", ['stage' => 'won'])
            ->assertOk()
            ->assertJsonPath('data.is_won', true)
            ->assertJsonPath('data.probability', 100);

        $deal->refresh();
        $this->assertSame(now()->toDateString(), $deal->actual_close_date?->toDateString());
    }

    public function test_reopening_a_deal_clears_the_close_date_and_lost_reason(): void
    {
        $rep = User::factory()->salesrep()->create();
        $deal = $this->dealFor($rep, OpportunityStage::Lost);

        $this->actingAs($rep, 'api')
            ->postJson("/api/v1/opportunities/{$deal->id}/stage", ['stage' => 'discovery'])
            ->assertOk()
            ->assertJsonPath('data.lost_reason', null)
            ->assertJsonPath('data.actual_close_date', null);

        $deal->refresh();
        $this->assertNull($deal->actual_close_date);
        $this->assertNull($deal->lost_reason);
    }

    public function test_winning_a_deal_dispatches_the_event_but_does_not_run_the_service_handoff(): void
    {
        Event::fake([OpportunityWon::class]);
        $this->spy(ServiceHandoff::class);

        $manager = User::factory()->manager()->create();
        $company = Company::factory()->ownedBy($manager)->create(['status' => CompanyStatus::Prospect]);
        $deal = $this->dealFor($manager, OpportunityStage::Negotiation, $company);

        $this->actingAs($manager, 'api')
            ->postJson("/api/v1/opportunities/{$deal->id}/stage", ['stage' => 'won'])
            ->assertOk();

        Event::assertDispatched(OpportunityWon::class);
        // The Service handoff only fires on mark-signed (ADR-0015 §4).
        app(ServiceHandoff::class)->shouldNotHaveReceived('handoff');
        $this->assertSame(CompanyStatus::Prospect, $company->refresh()->status);
    }

    public function test_the_board_returns_open_stages_as_columns_with_totals(): void
    {
        $rep = User::factory()->salesrep()->create();
        $company = Company::factory()->ownedBy($rep)->create();

        Opportunity::factory()->forCompany($company)->ownedBy($rep)->stage(OpportunityStage::Negotiation)->create(['amount' => 1000000, 'probability' => 80]);
        Opportunity::factory()->forCompany($company)->ownedBy($rep)->stage(OpportunityStage::Qualified)->create(['amount' => 500000, 'probability' => 20]);
        Opportunity::factory()->forCompany($company)->ownedBy($rep)->stage(OpportunityStage::Won)->create(['amount' => 2000000, 'probability' => 100]);

        $response = $this->actingAs($rep, 'api')->getJson('/api/v1/pipeline')->assertOk();

        $response->assertJsonPath('data.columns.0.stage', 'new_inquiry');
        $response->assertJsonPath('meta.open_count', 2);
        $response->assertJsonPath('meta.open_value', 1500000);
        // 1,000,000 × 80% + 500,000 × 20% = 900,000
        $response->assertJsonPath('meta.weighted_value', 900000);
        $response->assertJsonPath('meta.won_count', 1);
        $response->assertJsonPath('meta.win_rate', 100);

        // The won deal is not one of the open columns, but it is in `closed`.
        $columns = collect($response->json('data.columns'))->keyBy('stage');
        $this->assertSame(1, $columns['negotiation']['count']);
        $this->assertSame(1, $columns['qualified']['count']);
        $this->assertSame(0, $columns['discovery']['count']);
        $this->assertCount(1, $response->json('data.closed'));
    }

    public function test_the_board_is_scoped_to_the_callers_visible_deals(): void
    {
        $team = Team::factory()->create();
        $rep = User::factory()->salesrep()->create(['team_id' => $team->id]);
        $teammate = User::factory()->salesrep()->create(['team_id' => $team->id]);
        $manager = User::factory()->manager()->create(['team_id' => $team->id]);

        $company = Company::factory()->ownedBy($rep)->create();
        Opportunity::factory()->forCompany($company)->ownedBy($rep)->stage(OpportunityStage::Discovery)->create();
        Opportunity::factory()->forCompany($company)->ownedBy($teammate)->stage(OpportunityStage::Discovery)->create();

        $this->actingAs($rep, 'api')->getJson('/api/v1/pipeline')->assertOk()->assertJsonPath('meta.open_count', 1);
        $this->actingAs($manager, 'api')->getJson('/api/v1/pipeline')->assertOk()->assertJsonPath('meta.open_count', 2);
    }

    public function test_a_salesrep_can_create_a_deal_on_their_own_company(): void
    {
        $rep = User::factory()->salesrep()->create();
        $company = Company::factory()->ownedBy($rep)->create();

        $response = $this->actingAs($rep, 'api')
            ->postJson('/api/v1/opportunities', [
                'company_id' => $company->id,
                'name' => 'Sunrise Foods Inc — night shift',
                'expected_close_date' => now()->addMonth()->toDateString(),
                'next_step' => 'Send the rate card',
            ])
            ->assertCreated()
            ->assertJsonPath('data.stage', 'new_inquiry')
            ->assertJsonPath('data.owner_id', $rep->id)
            ->assertJsonPath('data.company_id', $company->id);

        $this->assertStringStartsWith('OPP-', $response->json('data.reference'));
        $this->assertSame(1, Opportunity::count());
    }

    public function test_a_deal_cannot_be_created_on_an_out_of_scope_company(): void
    {
        $rep = User::factory()->salesrep()->create();
        $other = User::factory()->salesrep()->create();
        $company = Company::factory()->ownedBy($other)->create();

        $this->actingAs($rep, 'api')
            ->postJson('/api/v1/opportunities', ['company_id' => $company->id, 'name' => 'Shadow deal'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('company_id');

        $this->assertSame(0, Opportunity::count());
    }

    public function test_a_salesrep_cannot_set_or_change_the_deal_amount(): void
    {
        $rep = User::factory()->salesrep()->create();
        $company = Company::factory()->ownedBy($rep)->create();

        $this->actingAs($rep, 'api')
            ->postJson('/api/v1/opportunities', ['company_id' => $company->id, 'name' => 'Priced deal', 'amount' => 500000])
            ->assertStatus(422)
            ->assertJsonValidationErrors('amount');

        $deal = $this->dealFor($rep);

        $this->actingAs($rep, 'api')
            ->patchJson("/api/v1/opportunities/{$deal->id}", ['amount' => 900000])
            ->assertStatus(422)
            ->assertJsonValidationErrors('amount');
    }

    public function test_a_manager_can_set_the_amount_and_reassign_a_deal_within_their_team(): void
    {
        $team = Team::factory()->create();
        $manager = User::factory()->manager()->create(['team_id' => $team->id]);
        $rep = User::factory()->salesrep()->create(['team_id' => $team->id]);
        $teammate = User::factory()->salesrep()->create(['team_id' => $team->id]);

        $company = Company::factory()->ownedBy($rep)->create();
        $deal = Opportunity::factory()->forCompany($company)->ownedBy($rep)->stage(OpportunityStage::ProposalSent)->create();

        $this->actingAs($manager, 'api')
            ->patchJson("/api/v1/opportunities/{$deal->id}", ['amount' => 1500000, 'owner_id' => $teammate->id])
            ->assertOk()
            ->assertJsonPath('data.amount', '1500000.00')
            ->assertJsonPath('data.owner_id', $teammate->id);

        $this->assertTrue($manager->can('setAmount', $deal));
    }

    public function test_a_deal_outside_the_callers_scope_is_forbidden(): void
    {
        $rep = User::factory()->salesrep()->create();
        $other = User::factory()->salesrep()->create();
        $deal = $this->dealFor($other);

        $this->actingAs($rep, 'api')->getJson("/api/v1/opportunities/{$deal->id}")->assertStatus(403);
        $this->actingAs($rep, 'api')->postJson("/api/v1/opportunities/{$deal->id}/stage", ['stage' => 'won'])->assertStatus(403);
        $this->actingAs($rep, 'api')->patchJson("/api/v1/opportunities/{$deal->id}", ['name' => 'Hijack'])->assertStatus(403);
    }

    public function test_a_deal_can_be_soft_deleted_and_only_a_manager_can_restore_it(): void
    {
        $team = Team::factory()->create();
        $manager = User::factory()->manager()->create(['team_id' => $team->id]);
        $rep = User::factory()->salesrep()->create(['team_id' => $team->id]);

        $company = Company::factory()->ownedBy($rep)->create();
        $deal = Opportunity::factory()->forCompany($company)->ownedBy($rep)->stage(OpportunityStage::Won)->create();

        $this->actingAs($rep, 'api')
            ->deleteJson("/api/v1/opportunities/{$deal->id}")
            ->assertNoContent();

        $this->assertSoftDeleted('opportunities', ['id' => $deal->id]);

        $this->actingAs($rep, 'api')
            ->postJson("/api/v1/opportunities/{$deal->id}/restore")
            ->assertStatus(403);

        $this->actingAs($manager, 'api')
            ->postJson("/api/v1/opportunities/{$deal->id}/restore")
            ->assertOk()
            ->assertJsonPath('data.id', $deal->id);

        $this->assertNotSoftDeleted('opportunities', ['id' => $deal->id]);
    }

    public function test_logging_an_activity_refreshes_the_last_activity_and_appears_on_the_timeline(): void
    {
        $rep = User::factory()->salesrep()->create();
        $deal = $this->dealFor($rep, OpportunityStage::ProposalSent);
        $deal->forceFill(['last_activity_at' => now()->subDays(40)])->save();

        $this->actingAs($rep, 'api')
            ->postJson("/api/v1/opportunities/{$deal->id}/activities", [
                'type' => 'call',
                'subject' => 'Follow-up call',
                'body' => 'Client asked for a revised rate.',
            ])
            ->assertOk()
            ->assertJsonPath('activity.subject', 'Follow-up call');

        $this->assertTrue($deal->refresh()->last_activity_at->isToday());

        $this->actingAs($rep, 'api')
            ->getJson("/api/v1/opportunities/{$deal->id}/timeline")
            ->assertOk()
            ->assertJsonPath('data.0.subject', 'Follow-up call')
            ->assertJsonPath('meta.stage', 'proposal_sent');
    }

    public function test_a_stale_deal_is_flagged_by_the_board(): void
    {
        $rep = User::factory()->salesrep()->create();
        $company = Company::factory()->ownedBy($rep)->create();

        $stuck = Opportunity::factory()->forCompany($company)->ownedBy($rep)->stage(OpportunityStage::ProposalSent)->create();
        $stuck->forceFill(['last_activity_at' => now()->subDays(45), 'stage_changed_at' => now()->subDays(45)])->save();

        $fresh = Opportunity::factory()->forCompany($company)->ownedBy($rep)->stage(OpportunityStage::Discovery)->create();
        $fresh->forceFill(['last_activity_at' => now(), 'stage_changed_at' => now()])->save();

        $this->actingAs($rep, 'api')
            ->getJson('/api/v1/pipeline')
            ->assertOk()
            ->assertJsonPath('meta.stale_count', 1);
    }
}
