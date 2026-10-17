<?php

namespace Tests\Feature;

use App\Enums\OpportunityStage;
use App\Models\Company;
use App\Models\Opportunity;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OpportunityTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_opportunities_tab_lists_the_deals_of_a_company(): void
    {
        $rep = User::factory()->salesrep()->create();
        $company = Company::factory()->ownedBy($rep)->create();

        $deal = Opportunity::factory()->forCompany($company)->ownedBy($rep)->stage(OpportunityStage::ProposalSent)->create([
            'name' => 'Sunrise Foods Inc — warehouse staffing',
            'amount' => 450000,
        ]);

        $this->actingAs($rep, 'api')
            ->getJson("/api/v1/companies/{$company->id}/opportunities")
            ->assertOk()
            ->assertJsonPath('data.0.id', $deal->id)
            ->assertJsonPath('data.0.reference', $deal->reference)
            ->assertJsonPath('data.0.name', 'Sunrise Foods Inc — warehouse staffing')
            ->assertJsonPath('data.0.stage', 'proposal_sent')
            ->assertJsonPath('data.0.amount', '450000.00')
            ->assertJsonPath('data.0.currency', 'PHP')
            ->assertJsonPath('data.0.probability', 60)
            ->assertJsonPath('data.0.owner.id', $rep->id)
            ->assertJsonPath('data.0.is_open', true)
            ->assertJsonPath('data.0.is_won', false)
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('meta.open', 1);
    }

    public function test_the_opportunity_list_can_be_filtered_by_stage(): void
    {
        $rep = User::factory()->salesrep()->create();
        $company = Company::factory()->ownedBy($rep)->create();

        Opportunity::factory()->forCompany($company)->ownedBy($rep)->stage(OpportunityStage::Negotiation)->create();
        Opportunity::factory()->forCompany($company)->ownedBy($rep)->stage(OpportunityStage::Won)->create();

        $this->actingAs($rep, 'api')
            ->getJson("/api/v1/companies/{$company->id}/opportunities?filter[stage]=won,lost")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.stage', 'won')
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('meta.open', 0);
    }

    public function test_deals_are_scoped_to_their_owner(): void
    {
        $team = Team::factory()->create();
        $otherTeam = Team::factory()->create();

        $rep = User::factory()->salesrep()->create(['team_id' => $team->id]);
        $teammate = User::factory()->salesrep()->create(['team_id' => $team->id]);
        $manager = User::factory()->manager()->create(['team_id' => $team->id]);
        $outsider = User::factory()->salesrep()->create(['team_id' => $otherTeam->id]);
        $admin = User::factory()->admin()->create();

        // A manager can see the account, but only their team's deals on it.
        $company = Company::factory()->ownedBy($rep)->create();

        Opportunity::factory()->forCompany($company)->ownedBy($rep)->create();
        Opportunity::factory()->forCompany($company)->ownedBy($teammate)->create();
        Opportunity::factory()->forCompany($company)->ownedBy($outsider)->create();

        $this->actingAs($rep, 'api')
            ->getJson("/api/v1/companies/{$company->id}/opportunities")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.owner_id', $rep->id);

        $this->actingAs($manager, 'api')
            ->getJson("/api/v1/companies/{$company->id}/opportunities")
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $this->actingAs($admin, 'api')
            ->getJson("/api/v1/companies/{$company->id}/opportunities")
            ->assertOk()
            ->assertJsonCount(3, 'data');
    }

    public function test_a_company_tab_is_not_visible_to_another_salesreps_account(): void
    {
        $rep = User::factory()->salesrep()->create();
        $other = User::factory()->salesrep()->create();
        $company = Company::factory()->ownedBy($other)->create();

        $this->actingAs($rep, 'api')
            ->getJson("/api/v1/companies/{$company->id}/opportunities")
            ->assertStatus(403);
    }

    public function test_a_company_with_an_open_deal_cannot_be_deleted(): void
    {
        $manager = User::factory()->manager()->create();
        $company = Company::factory()->ownedBy($manager)->create();

        Opportunity::factory()->forCompany($company)->ownedBy($manager)->stage(OpportunityStage::Negotiation)->create();

        $this->actingAs($manager, 'api')
            ->deleteJson("/api/v1/companies/{$company->id}")
            ->assertStatus(422)
            ->assertJsonValidationErrors('company');

        $this->assertNotSoftDeleted('companies', ['id' => $company->id]);
    }

    public function test_a_company_whose_deals_are_closed_can_be_deleted(): void
    {
        $manager = User::factory()->manager()->create();
        $company = Company::factory()->ownedBy($manager)->create();

        Opportunity::factory()->forCompany($company)->ownedBy($manager)->stage(OpportunityStage::Won)->create();
        Opportunity::factory()->forCompany($company)->ownedBy($manager)->stage(OpportunityStage::Lost)->create();

        $this->actingAs($manager, 'api')
            ->deleteJson("/api/v1/companies/{$company->id}")
            ->assertNoContent();

        $this->assertSoftDeleted('companies', ['id' => $company->id]);
    }

    public function test_company_counts_include_opportunities(): void
    {
        $rep = User::factory()->salesrep()->create();
        $company = Company::factory()->ownedBy($rep)->create();

        Opportunity::factory()->count(2)->forCompany($company)->ownedBy($rep)->create();

        $this->actingAs($rep, 'api')
            ->getJson("/api/v1/companies/{$company->id}")
            ->assertOk()
            ->assertJsonPath('data.counts.opportunities', 2);
    }

    public function test_references_are_sequential_display_values(): void
    {
        $rep = User::factory()->salesrep()->create();
        $company = Company::factory()->ownedBy($rep)->create();

        $first = Opportunity::factory()->forCompany($company)->ownedBy($rep)->create();
        $second = Opportunity::factory()->forCompany($company)->ownedBy($rep)->create();

        $this->assertMatchesRegularExpression('/^OPP-\d{4}-\d{4}$/', $first->reference);

        $prefix = sprintf('OPP-%d-', now()->year);
        $this->assertSame(
            (int) substr($second->reference, strlen($prefix)),
            (int) substr($first->reference, strlen($prefix)) + 1,
        );
    }

    public function test_probability_defaults_from_the_stage(): void
    {
        $rep = User::factory()->salesrep()->create();
        $company = Company::factory()->ownedBy($rep)->create();

        $deal = Opportunity::factory()->forCompany($company)->ownedBy($rep)->create([
            'stage' => OpportunityStage::ProposalSent,
            'probability' => null,
        ]);

        $this->assertSame(60, $deal->probability);

        // A deal with no stage at all opens as `new_inquiry`.
        $fresh = Opportunity::factory()->forCompany($company)->ownedBy($rep)->create([
            'stage' => null,
            'probability' => null,
        ]);

        $this->assertSame(OpportunityStage::NewInquiry, $fresh->stage);
        $this->assertSame(10, $fresh->probability);
    }

    public function test_a_salesrep_can_read_their_own_deal_but_not_another_salesreps(): void
    {
        $rep = User::factory()->salesrep()->create();
        $other = User::factory()->salesrep()->create();
        $company = Company::factory()->ownedBy($rep)->create();

        $own = Opportunity::factory()->forCompany($company)->ownedBy($rep)->create();

        $this->assertTrue($rep->can('view', $own));
        $this->assertFalse($other->can('view', $own));
    }
}
