<?php

namespace Tests\Feature;

use App\Enums\CompanyStatus;
use App\Enums\ContactRoleType;
use App\Enums\LeadStatus;
use App\Enums\OpportunityStage;
use App\Events\ContactCreated;
use App\Events\LeadConverted;
use App\Models\Activity;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Lead;
use App\Models\Opportunity;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use RuntimeException;
use Tests\TestCase;

class LeadConversionTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function qualifiedLead(User $owner, array $attributes = []): Lead
    {
        return Lead::factory()->create(array_merge([
            'company_name' => 'Sunrise Foods Inc',
            'contact_name' => 'Ana Reyes',
            'contact_position' => 'HR Manager',
            'contact_email' => 'ana@sunrisefoods.ph',
            'contact_phone' => '0917 555 0101',
            'industry' => 'Food Manufacturing',
            'service_interest' => 'Warehouse staffing',
            'estimated_headcount' => 25,
            'needed_roles' => 'Warehouse staff',
            'budget_rate' => 750.00,
            'target_start_date' => now()->addWeeks(2)->toDateString(),
            'status' => LeadStatus::Qualified,
            'owner_id' => $owner->id,
        ], $attributes));
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'company' => [
                'name' => 'Sunrise Foods Inc',
                'industry' => 'Food Manufacturing',
            ],
            'contacts' => [[
                'name' => 'Ana Reyes',
                'position' => 'HR Manager',
                'role_type' => ContactRoleType::Hr->value,
                'email' => 'ana@sunrisefoods.ph',
                'phone' => '0917 555 0101',
                'is_primary' => true,
            ]],
            'opportunity' => [
                'name' => 'Sunrise Foods Inc — Warehouse staffing',
                'amount' => 500000,
                'expected_close_date' => now()->addMonth()->toDateString(),
            ],
        ], $overrides);
    }

    public function test_a_qualified_lead_becomes_a_company_contact_and_opportunity(): void
    {
        Event::fake([LeadConverted::class]);

        $rep = User::factory()->salesrep()->create();
        $lead = $this->qualifiedLead($rep);

        $response = $this->actingAs($rep, 'api')
            ->postJson("/api/v1/leads/{$lead->id}/convert", $this->payload())
            ->assertCreated()
            ->assertJsonPath('data.company.name', 'Sunrise Foods Inc')
            ->assertJsonPath('data.company.status', CompanyStatus::Prospect->value)
            ->assertJsonPath('data.company.owner_id', $rep->id)
            ->assertJsonPath('data.company.counts.opportunities', 1)
            ->assertJsonPath('data.company.counts.contacts', 1)
            ->assertJsonPath('data.contacts.0.name', 'Ana Reyes')
            ->assertJsonPath('data.contacts.0.is_primary', true)
            ->assertJsonPath('data.opportunity.stage', OpportunityStage::Qualified->value)
            ->assertJsonPath('data.opportunity.name', 'Sunrise Foods Inc — Warehouse staffing')
            ->assertJsonPath('data.lead.status', LeadStatus::Converted->value)
            ->assertJsonPath('data.lead.can_convert', false)
            ->assertJsonPath('duplicates', []);

        $company = Company::where('name', 'Sunrise Foods Inc')->firstOrFail();
        $contact = Contact::where('company_id', $company->id)->firstOrFail();
        $opportunity = Opportunity::where('company_id', $company->id)->firstOrFail();

        // Source lead is recorded and ownership carried over (spec 08 §1).
        $this->assertSame($lead->id, $company->source_lead_id);
        $this->assertSame($lead->id, $opportunity->lead_id);
        $this->assertSame($contact->id, $opportunity->primary_contact_id);
        $this->assertSame($lead->source, $opportunity->source);
        $this->assertSame('PHP', $opportunity->currency);

        // `converted` is terminal and links both records (spec 08 §4).
        $lead->refresh();
        $this->assertSame(LeadStatus::Converted, $lead->status);
        $this->assertSame($company->id, $lead->converted_company_id);
        $this->assertSame($opportunity->id, $lead->converted_opportunity_id);
        $this->assertSame($company->id, $response->json('data.lead.converted_company_id'));
        $this->assertSame($opportunity->id, $response->json('data.lead.converted_opportunity_id'));

        Event::assertDispatched(LeadConverted::class);
    }

    public function test_conversion_records_a_system_activity_on_the_lead_timeline(): void
    {
        $rep = User::factory()->salesrep()->create();
        $lead = $this->qualifiedLead($rep);

        $this->actingAs($rep, 'api')
            ->postJson("/api/v1/leads/{$lead->id}/convert", $this->payload())
            ->assertCreated();

        $this->assertDatabaseHas('activities', [
            'subject_type' => Lead::class,
            'subject_id' => $lead->id,
            'type' => 'system',
        ]);
    }

    public function test_a_salesrep_opens_the_deal_without_an_amount(): void
    {
        $rep = User::factory()->salesrep()->create();
        $lead = $this->qualifiedLead($rep);

        // Sensitive values: the lead's rate and the wizard amount are ignored for a salesrep.
        $this->actingAs($rep, 'api')
            ->postJson("/api/v1/leads/{$lead->id}/convert", $this->payload())
            ->assertCreated()
            ->assertJsonPath('data.opportunity.amount', null);

        $this->assertNull(Opportunity::firstOrFail()->amount);
    }

    public function test_a_manager_conversion_carries_the_leads_rate_onto_the_deal(): void
    {
        $team = Team::factory()->create();
        $manager = User::factory()->manager()->create(['team_id' => $team->id]);
        $rep = User::factory()->salesrep()->create(['team_id' => $team->id]);
        $lead = $this->qualifiedLead($rep);

        // No explicit amount → the sensitive budget rate becomes the deal value.
        $payload = $this->payload();
        unset($payload['opportunity']['amount']);

        $this->actingAs($manager, 'api')
            ->postJson("/api/v1/leads/{$lead->id}/convert", $payload)
            ->assertCreated()
            ->assertJsonPath('data.opportunity.amount', '750.00');

        // An explicit amount wins over the carried-over rate.
        $second = $this->qualifiedLead($rep, ['company_name' => 'BPO Solutions PH']);
        $payload['company']['name'] = 'BPO Solutions PH';
        $payload['opportunity']['amount'] = 1250000;

        $this->actingAs($manager, 'api')
            ->postJson("/api/v1/leads/{$second->id}/convert", $payload)
            ->assertCreated()
            ->assertJsonPath('data.opportunity.amount', '1250000.00');
    }

    public function test_the_lead_owner_keeps_the_new_records(): void
    {
        $team = Team::factory()->create();
        $manager = User::factory()->manager()->create(['team_id' => $team->id]);
        $owner = User::factory()->salesrep()->create(['team_id' => $team->id]);
        $lead = $this->qualifiedLead($owner);

        $this->actingAs($manager, 'api')
            ->postJson("/api/v1/leads/{$lead->id}/convert", $this->payload())
            ->assertCreated()
            ->assertJsonPath('data.company.owner_id', $owner->id)
            ->assertJsonPath('data.opportunity.owner_id', $owner->id);
    }

    public function test_an_unassigned_lead_converted_by_a_manager_is_owned_by_the_converter(): void
    {
        $manager = User::factory()->manager()->create();
        $lead = $this->qualifiedLead($manager, ['owner_id' => null]);

        $this->actingAs($manager, 'api')
            ->postJson("/api/v1/leads/{$lead->id}/convert", $this->payload())
            ->assertCreated()
            ->assertJsonPath('data.company.owner_id', $manager->id)
            ->assertJsonPath('data.opportunity.owner_id', $manager->id);
    }

    public function test_conversion_can_link_an_existing_company_instead_of_creating_one(): void
    {
        $rep = User::factory()->salesrep()->create();
        $existing = Company::factory()->ownedBy($rep)->create(['name' => 'Sunrise Foods Group']);
        $lead = $this->qualifiedLead($rep);

        $payload = $this->payload();
        unset($payload['company']);
        $payload['link_company_id'] = $existing->id;
        $payload['contacts'][0]['role_type'] = ContactRoleType::HiringManager->value;

        $this->actingAs($rep, 'api')
            ->postJson("/api/v1/leads/{$lead->id}/convert", $payload)
            ->assertCreated()
            ->assertJsonPath('data.company.id', $existing->id)
            ->assertJsonPath('data.company.status', $existing->status->value)
            ->assertJsonPath('data.contacts.0.role_type', ContactRoleType::HiringManager->value)
            ->assertJsonPath('duplicates', []);

        // No second company, but the lead is linked to the existing account.
        $this->assertSame(1, Company::count());
        $this->assertSame($existing->id, $lead->refresh()->converted_company_id);
        $this->assertSame(1, $existing->contacts()->count());
        $this->assertSame(1, $existing->opportunities()->count());
    }

    public function test_a_lead_cannot_be_converted_into_a_company_outside_the_callers_scope(): void
    {
        $rep = User::factory()->salesrep()->create();
        $otherTeam = Team::factory()->create();
        $foreign = Company::factory()->create([
            'name' => 'Someone Elses Account',
            'owner_id' => User::factory()->salesrep()->create(['team_id' => $otherTeam->id])->id,
        ]);
        $lead = $this->qualifiedLead($rep);

        $payload = $this->payload();
        unset($payload['company']);
        $payload['link_company_id'] = $foreign->id;

        $this->actingAs($rep, 'api')
            ->postJson("/api/v1/leads/{$lead->id}/convert", $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors('link_company_id');

        $this->assertSame(0, Company::where('name', 'Sunrise Foods Inc')->count());
        $this->assertSame(LeadStatus::Qualified, $lead->refresh()->status);
    }

    public function test_only_qualified_leads_can_be_converted(): void
    {
        $rep = User::factory()->salesrep()->create();
        $lead = $this->qualifiedLead($rep, ['status' => LeadStatus::Contacted]);

        $this->actingAs($rep, 'api')
            ->postJson("/api/v1/leads/{$lead->id}/convert", $this->payload())
            ->assertStatus(422)
            ->assertJsonValidationErrors('status');

        $this->assertSame(0, Company::count());
        $this->assertSame(0, Opportunity::count());
    }

    public function test_an_already_converted_lead_cannot_be_converted_again(): void
    {
        $rep = User::factory()->salesrep()->create();
        $lead = $this->qualifiedLead($rep);

        $this->actingAs($rep, 'api')
            ->postJson("/api/v1/leads/{$lead->id}/convert", $this->payload())
            ->assertCreated();

        $this->actingAs($rep, 'api')
            ->postJson("/api/v1/leads/{$lead->id}/convert", $this->payload())
            ->assertStatus(409);

        $this->assertSame(1, Company::count());
        $this->assertSame(1, Opportunity::count());
    }

    public function test_a_salesrep_cannot_convert_another_salesreps_lead(): void
    {
        $rep = User::factory()->salesrep()->create();
        $other = User::factory()->salesrep()->create();
        $lead = $this->qualifiedLead($other);

        $this->actingAs($rep, 'api')
            ->postJson("/api/v1/leads/{$lead->id}/convert", $this->payload())
            ->assertStatus(403);

        $this->assertSame(0, Company::count());
    }

    public function test_a_flagged_later_contact_can_be_the_primary(): void
    {
        $rep = User::factory()->salesrep()->create();
        $lead = $this->qualifiedLead($rep);

        $payload = $this->payload([
            'contacts' => [
                ['name' => 'Ana Reyes', 'role_type' => ContactRoleType::Hr->value, 'is_primary' => false],
                ['name' => 'Ramon Villanueva', 'role_type' => ContactRoleType::HiringManager->value, 'is_primary' => true],
            ],
        ]);

        $this->actingAs($rep, 'api')
            ->postJson("/api/v1/leads/{$lead->id}/convert", $payload)
            ->assertCreated()
            ->assertJsonPath('data.contacts.0.name', 'Ramon Villanueva')
            ->assertJsonPath('data.contacts.1.is_primary', false);

        // Exactly one primary among the new contacts (ADR-0011 §3).
        $company = Company::firstOrFail();
        $this->assertSame(1, $company->contacts()->where('is_primary', true)->count());
        $this->assertSame(
            Contact::where('name', 'Ramon Villanueva')->value('id'),
            Opportunity::firstOrFail()->primary_contact_id,
        );
    }

    public function test_a_conversion_needs_a_company_or_a_link(): void
    {
        $rep = User::factory()->salesrep()->create();
        $lead = $this->qualifiedLead($rep);

        $payload = $this->payload();
        unset($payload['company']);

        $this->actingAs($rep, 'api')
            ->postJson("/api/v1/leads/{$lead->id}/convert", $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors('company');

        $this->assertSame(0, Company::count());
    }

    public function test_a_conversion_needs_at_least_one_contact(): void
    {
        $rep = User::factory()->salesrep()->create();
        $lead = $this->qualifiedLead($rep);

        $this->actingAs($rep, 'api')
            ->postJson("/api/v1/leads/{$lead->id}/convert", $this->payload(['contacts' => []]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('contacts');

        $this->assertSame(0, Company::count());
    }

    public function test_conversion_rolls_back_every_record_when_a_step_fails(): void
    {
        $rep = User::factory()->salesrep()->create();
        $lead = $this->qualifiedLead($rep);

        Event::listen(ContactCreated::class, function (): void {
            throw new RuntimeException('boom');
        });

        $this->withoutExceptionHandling();

        try {
            $this->actingAs($rep, 'api')
                ->postJson("/api/v1/leads/{$lead->id}/convert", $this->payload());

            $this->fail('The conversion was expected to fail.');
        } catch (RuntimeException) {
            // expected
        }

        // Nothing survives a failed step — company, contacts, deal and the lead status.
        $this->assertSame(0, Company::count());
        $this->assertSame(0, Contact::count());
        $this->assertSame(0, Opportunity::count());
        $this->assertSame(LeadStatus::Qualified, $lead->refresh()->status);
        $this->assertNull($lead->converted_company_id);
    }

    public function test_the_preview_suggests_the_mapping_and_flags_possible_duplicate_companies(): void
    {
        $rep = User::factory()->salesrep()->create();
        $lead = $this->qualifiedLead($rep);

        $byName = Company::factory()->ownedBy($rep)->create(['name' => 'Sunrise Foods Inc']);
        $byEmail = Company::factory()->ownedBy($rep)->create(['name' => 'Sunrise Foods Manila']);
        Contact::factory()->forCompany($byEmail)->create(['email' => 'ana@sunrisefoods.ph']);

        $response = $this->actingAs($rep, 'api')
            ->getJson("/api/v1/leads/{$lead->id}/conversion-preview")
            ->assertOk()
            ->assertJsonPath('data.lead.id', $lead->id)
            ->assertJsonPath('data.company.name', 'Sunrise Foods Inc')
            ->assertJsonPath('data.contacts.0.name', 'Ana Reyes')
            ->assertJsonPath('data.contacts.0.email', 'ana@sunrisefoods.ph')
            ->assertJsonPath('data.contacts.0.is_primary', true)
            ->assertJsonPath('data.opportunity.stage', OpportunityStage::Qualified->value)
            ->assertJsonPath('data.opportunity.owner_id', $rep->id)
            ->assertJsonPath('data.can_convert', true)
            ->assertJsonPath('data.blocked_reason', null);

        $duplicates = collect($response->json('data.duplicates'));

        $this->assertCount(2, $duplicates);
        $this->assertSame(['name'], $duplicates->firstWhere('id', $byName->id)['matched_on']);
        $this->assertSame(['email'], $duplicates->firstWhere('id', $byEmail->id)['matched_on']);
    }

    public function test_the_preview_hides_the_sensitive_amount_from_a_salesrep(): void
    {
        $rep = User::factory()->salesrep()->create();
        $lead = $this->qualifiedLead($rep);

        $this->actingAs($rep, 'api')
            ->getJson("/api/v1/leads/{$lead->id}/conversion-preview")
            ->assertOk()
            ->assertJsonPath('data.can_set_amount', false)
            ->assertJsonPath('data.opportunity.amount', null);

        $team = Team::factory()->create();
        $manager = User::factory()->manager()->create(['team_id' => $team->id]);
        $lead->update(['owner_id' => User::factory()->salesrep()->create(['team_id' => $team->id])->id]);

        $this->actingAs($manager, 'api')
            ->getJson("/api/v1/leads/{$lead->id}/conversion-preview")
            ->assertOk()
            ->assertJsonPath('data.can_set_amount', true)
            ->assertJsonPath('data.opportunity.amount', '750.00');
    }

    public function test_the_preview_blocks_leads_that_cannot_be_converted(): void
    {
        $rep = User::factory()->salesrep()->create();
        $new = $this->qualifiedLead($rep, ['status' => LeadStatus::New]);
        $converted = $this->qualifiedLead($rep, ['status' => LeadStatus::Converted]);

        $this->actingAs($rep, 'api')
            ->getJson("/api/v1/leads/{$new->id}/conversion-preview")
            ->assertOk()
            ->assertJsonPath('data.can_convert', false)
            ->assertJsonPath('data.blocked_reason', 'Only qualified leads can be converted — this one is new.');

        $this->actingAs($rep, 'api')
            ->getJson("/api/v1/leads/{$converted->id}/conversion-preview")
            ->assertOk()
            ->assertJsonPath('data.can_convert', false)
            ->assertJsonPath('data.blocked_reason', 'This lead has already been converted.');
    }

    public function test_the_convert_response_reports_a_remaining_name_collision(): void
    {
        $rep = User::factory()->salesrep()->create();
        $otherTeam = Team::factory()->create();
        $owner = User::factory()->salesrep()->create(['team_id' => $otherTeam->id]);

        // Another team already has a Sunrise Foods Inc — the caller cannot see it, so it is
        // neither flagged as a duplicate nor a blocker.
        Company::factory()->create(['name' => 'Sunrise Foods Inc', 'owner_id' => $owner->id]);

        $lead = $this->qualifiedLead($rep);
        $clash = Company::factory()->ownedBy($rep)->create(['name' => 'Sunrise Foods Inc']);

        $response = $this->actingAs($rep, 'api')
            ->postJson("/api/v1/leads/{$lead->id}/convert", $this->payload())
            ->assertCreated();

        $this->assertCount(1, $response->json('duplicates'));
        $this->assertSame($clash->id, $response->json('duplicates.0.id'));
        $this->assertSame(['name'], $response->json('duplicates.0.matched_on'));
    }

    public function test_a_converted_lead_leaves_the_qualified_worklist(): void
    {
        $team = Team::factory()->create();
        $rep = User::factory()->salesrep()->create(['team_id' => $team->id]);
        $converted = $this->qualifiedLead($rep);
        $open = $this->qualifiedLead($rep, ['company_name' => 'BPO Solutions PH']);

        $this->actingAs($rep, 'api')
            ->postJson("/api/v1/leads/{$converted->id}/convert", $this->payload())
            ->assertCreated();

        $this->actingAs($rep, 'api')
            ->getJson('/api/v1/leads?filter[status]=qualified')
            ->assertOk()
            ->assertJsonPath('data.0.id', $open->id)
            ->assertJsonCount(1, 'data');

        $this->assertSame(1, Lead::where('status', LeadStatus::Qualified)->count());
        $this->assertSame(1, Activity::where('subject_id', $converted->id)->where('type', 'system')->count());
    }
}
