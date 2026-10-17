<?php

namespace Tests\Feature;

use App\Enums\ContactRoleType;
use App\Enums\ContactStatus;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ContactTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_salesrep_can_add_a_contact_to_their_own_company(): void
    {
        $rep = User::factory()->salesrep()->create();
        $company = Company::factory()->ownedBy($rep)->create();

        $response = $this->actingAs($rep, 'api')
            ->postJson("/api/v1/companies/{$company->id}/contacts", [
                'name' => 'Ramon Villanueva',
                'position' => 'Plant HR Manager',
                'role_type' => ContactRoleType::HiringManager->value,
                'email' => 'ramon@sunrisefoods.ph',
                'mobile' => '0917 555 2210',
            ])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Ramon Villanueva')
            ->assertJsonPath('data.role_type', 'hiring_manager')
            ->assertJsonPath('data.is_decision_maker', true)
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('duplicates', []);

        // The first contact on a company is its primary (spec 07 §3).
        $response->assertJsonPath('data.is_primary', true);
        $this->assertMatchesRegularExpression('/^[0-9A-HJKMNP-TV-Z]{26}$/i', $response->json('data.id'));
    }

    public function test_a_contact_cannot_be_added_to_another_salesreps_company(): void
    {
        $rep = User::factory()->salesrep()->create();
        $other = User::factory()->salesrep()->create();
        $company = Company::factory()->ownedBy($other)->create();

        $this->actingAs($rep, 'api')
            ->postJson("/api/v1/companies/{$company->id}/contacts", [
                'name' => 'Sneaky Contact',
                'role_type' => ContactRoleType::Hr->value,
            ])
            ->assertStatus(403);
    }

    public function test_role_type_is_required_and_enum_validated(): void
    {
        $rep = User::factory()->salesrep()->create();
        $company = Company::factory()->ownedBy($rep)->create();

        $this->actingAs($rep, 'api')
            ->postJson("/api/v1/companies/{$company->id}/contacts", ['name' => 'No Role'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('role_type');

        $this->actingAs($rep, 'api')
            ->postJson("/api/v1/companies/{$company->id}/contacts", [
                'name' => 'Bad Role',
                'role_type' => 'chief_of_staff',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('role_type');
    }

    public function test_phone_numbers_are_validated_when_present(): void
    {
        $rep = User::factory()->salesrep()->create();
        $company = Company::factory()->ownedBy($rep)->create();

        $this->actingAs($rep, 'api')
            ->postJson("/api/v1/companies/{$company->id}/contacts", [
                'name' => 'Bad Phone',
                'role_type' => ContactRoleType::Hr->value,
                'mobile' => 'call me maybe',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('mobile');
    }

    public function test_a_duplicate_email_on_the_same_company_is_advisory_only(): void
    {
        $rep = User::factory()->salesrep()->create();
        $company = Company::factory()->ownedBy($rep)->create();
        $existing = Contact::factory()->forCompany($company)->create(['email' => 'hr@sunrisefoods.ph']);

        $this->actingAs($rep, 'api')
            ->postJson("/api/v1/companies/{$company->id}/contacts", [
                'name' => 'Another Grace Lim',
                'role_type' => ContactRoleType::Hr->value,
                'email' => 'HR@sunrisefoods.ph',
            ])
            ->assertCreated()
            ->assertJsonPath('duplicates.0.id', $existing->id);
    }

    public function test_the_same_email_is_allowed_on_a_different_company(): void
    {
        $rep = User::factory()->salesrep()->create();
        $first = Company::factory()->ownedBy($rep)->create();
        $second = Company::factory()->ownedBy($rep)->create();
        Contact::factory()->forCompany($first)->create(['email' => 'shared@group.ph']);

        $this->actingAs($rep, 'api')
            ->postJson("/api/v1/companies/{$second->id}/contacts", [
                'name' => 'Shared Inbox',
                'role_type' => ContactRoleType::Other->value,
                'email' => 'shared@group.ph',
            ])
            ->assertCreated()
            ->assertJsonPath('duplicates', []);
    }

    public function test_making_a_contact_primary_unsets_the_previous_primary(): void
    {
        $rep = User::factory()->salesrep()->create();
        $company = Company::factory()->ownedBy($rep)->create();
        $primary = Contact::factory()->forCompany($company)->primary()->create();
        $other = Contact::factory()->forCompany($company)->create();

        $this->actingAs($rep, 'api')
            ->postJson("/api/v1/contacts/{$other->id}/make-primary")
            ->assertOk()
            ->assertJsonPath('data.is_primary', true);

        $this->assertFalse($primary->refresh()->is_primary);
        $this->assertSame(1, Contact::where('company_id', $company->id)->where('is_primary', true)->count());
    }

    public function test_a_primary_contact_is_not_offered_the_make_primary_action(): void
    {
        $rep = User::factory()->salesrep()->create();
        $company = Company::factory()->ownedBy($rep)->create();
        $primary = Contact::factory()->forCompany($company)->primary()->create();

        $this->actingAs($rep, 'api')
            ->getJson("/api/v1/contacts/{$primary->id}")
            ->assertOk()
            ->assertJsonPath('data.can_make_primary', false)
            ->assertJsonPath('data.can_update', true);
    }

    public function test_an_inactive_contact_cannot_be_made_primary(): void
    {
        $rep = User::factory()->salesrep()->create();
        $company = Company::factory()->ownedBy($rep)->create();
        Contact::factory()->forCompany($company)->primary()->create();
        $inactive = Contact::factory()->forCompany($company)->inactive()->create();

        $this->actingAs($rep, 'api')
            ->postJson("/api/v1/contacts/{$inactive->id}/make-primary")
            ->assertStatus(422)
            ->assertJsonValidationErrors('status');
    }

    public function test_deactivating_the_primary_hands_the_flag_to_another_active_contact(): void
    {
        $rep = User::factory()->salesrep()->create();
        $company = Company::factory()->ownedBy($rep)->create();
        $primary = Contact::factory()->forCompany($company)->primary()->create();
        $standIn = Contact::factory()->forCompany($company)->create();

        $this->actingAs($rep, 'api')
            ->patchJson("/api/v1/contacts/{$primary->id}", ['status' => ContactStatus::Inactive->value])
            ->assertOk()
            ->assertJsonPath('data.status', 'inactive')
            ->assertJsonPath('data.is_primary', false);

        $this->assertTrue($standIn->refresh()->is_primary);
    }

    public function test_deleting_the_primary_promotes_another_active_contact(): void
    {
        $rep = User::factory()->salesrep()->create();
        $company = Company::factory()->ownedBy($rep)->create();
        $primary = Contact::factory()->forCompany($company)->primary()->create();
        $standIn = Contact::factory()->forCompany($company)->create();
        $inactive = Contact::factory()->forCompany($company)->inactive()->create();

        $this->actingAs($rep, 'api')
            ->deleteJson("/api/v1/contacts/{$primary->id}")
            ->assertNoContent();

        // The inactive contact is never promoted (spec 07 §6 — inactive means out of the running).
        $this->assertTrue($standIn->refresh()->is_primary);
        $this->assertFalse($inactive->refresh()->is_primary);
    }

    public function test_a_contact_is_soft_deleted_and_can_be_restored(): void
    {
        $rep = User::factory()->salesrep()->create();
        $company = Company::factory()->ownedBy($rep)->create();
        $contact = Contact::factory()->forCompany($company)->create();

        $this->actingAs($rep, 'api')
            ->deleteJson("/api/v1/contacts/{$contact->id}")
            ->assertNoContent();

        $this->assertSoftDeleted('contacts', ['id' => $contact->id]);
        $this->assertSame(0, Contact::where('company_id', $company->id)->count());

        $this->actingAs($rep, 'api')
            ->postJson("/api/v1/contacts/{$contact->id}/restore")
            ->assertOk()
            ->assertJsonPath('data.id', $contact->id);

        // Back on the account, and it regains the primary flag when nobody else holds it.
        $this->assertSame(1, Contact::where('company_id', $company->id)->count());
        $this->assertTrue($contact->refresh()->is_primary);
    }

    public function test_a_contact_cannot_be_moved_to_another_company(): void
    {
        $rep = User::factory()->salesrep()->create();
        $company = Company::factory()->ownedBy($rep)->create();
        $other = Company::factory()->ownedBy($rep)->create();
        $contact = Contact::factory()->forCompany($company)->create();

        $this->actingAs($rep, 'api')
            ->patchJson("/api/v1/contacts/{$contact->id}", [
                'name' => 'Renamed Person',
                'company_id' => $other->id,
            ])
            ->assertOk()
            ->assertJsonPath('data.name', 'Renamed Person')
            ->assertJsonPath('data.company_id', $company->id);
    }

    public function test_inactive_contacts_stay_on_the_account_but_drop_out_of_active_filters(): void
    {
        $rep = User::factory()->salesrep()->create();
        $company = Company::factory()->ownedBy($rep)->create();
        Contact::factory()->forCompany($company)->create(['name' => 'Active Person']);
        Contact::factory()->forCompany($company)->inactive()->create(['name' => 'Gone Person']);

        $this->actingAs($rep, 'api')
            ->getJson("/api/v1/companies/{$company->id}/contacts")
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $this->actingAs($rep, 'api')
            ->getJson("/api/v1/companies/{$company->id}/contacts?filter[status]=active")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Active Person');
    }

    public function test_the_company_contacts_list_can_be_filtered_by_role_type(): void
    {
        $rep = User::factory()->salesrep()->create();
        $company = Company::factory()->ownedBy($rep)->create();
        Contact::factory()->forCompany($company)->role(ContactRoleType::Finance)->create();
        Contact::factory()->forCompany($company)->role(ContactRoleType::Procurement)->create();

        $this->actingAs($rep, 'api')
            ->getJson("/api/v1/companies/{$company->id}/contacts?filter[role_type]=finance,procurement")
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $this->actingAs($rep, 'api')
            ->getJson("/api/v1/companies/{$company->id}/contacts?filter[role_type]=finance")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.role_type', 'finance');
    }

    public function test_the_global_contact_book_is_scoped_by_role(): void
    {
        $team = Team::factory()->create();
        $otherTeam = Team::factory()->create();

        $rep = User::factory()->salesrep()->create(['team_id' => $team->id]);
        $teammate = User::factory()->salesrep()->create(['team_id' => $team->id]);
        $manager = User::factory()->manager()->create(['team_id' => $team->id]);
        $outsider = User::factory()->salesrep()->create(['team_id' => $otherTeam->id]);

        Contact::factory()->forCompany(Company::factory()->ownedBy($rep)->create())->create();
        Contact::factory()->forCompany(Company::factory()->ownedBy($teammate)->create())->create();
        Contact::factory()->forCompany(Company::factory()->ownedBy($outsider)->create())->create();

        $this->actingAs($rep, 'api')
            ->getJson('/api/v1/contacts')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->actingAs($manager, 'api')
            ->getJson('/api/v1/contacts')
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $this->actingAs(User::factory()->admin()->create(), 'api')
            ->getJson('/api/v1/contacts')
            ->assertOk()
            ->assertJsonCount(3, 'data');
    }

    public function test_the_global_contact_book_carries_its_company_and_supports_search(): void
    {
        $rep = User::factory()->salesrep()->create();
        $company = Company::factory()->ownedBy($rep)->create(['name' => 'Harbor Logistics']);
        Contact::factory()->forCompany($company)->create(['name' => 'Neil Santiago']);
        Contact::factory()->forCompany(Company::factory()->ownedBy($rep)->create())->create(['name' => 'Someone Else']);

        $this->actingAs($rep, 'api')
            ->getJson('/api/v1/contacts?search=santiago')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Neil Santiago')
            ->assertJsonPath('data.0.company.name', 'Harbor Logistics');
    }

    public function test_the_global_contact_book_can_be_filtered_by_company(): void
    {
        $rep = User::factory()->salesrep()->create();
        $company = Company::factory()->ownedBy($rep)->create();
        Contact::factory()->forCompany($company)->create();
        Contact::factory()->forCompany(Company::factory()->ownedBy($rep)->create())->create();

        $this->actingAs($rep, 'api')
            ->getJson("/api/v1/contacts?filter[company_id]={$company->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.company_id', $company->id);
    }

    public function test_the_company_detail_rolls_up_its_contact_count(): void
    {
        $rep = User::factory()->salesrep()->create();
        $company = Company::factory()->ownedBy($rep)->create();
        Contact::factory()->forCompany($company)->count(2)->create();

        $this->actingAs($rep, 'api')
            ->getJson("/api/v1/companies/{$company->id}")
            ->assertOk()
            ->assertJsonPath('data.counts.contacts', 2);
    }

    public function test_a_manager_can_maintain_contacts_on_their_teams_account(): void
    {
        $team = Team::factory()->create();
        $rep = User::factory()->salesrep()->create(['team_id' => $team->id]);
        $manager = User::factory()->manager()->create(['team_id' => $team->id]);
        $company = Company::factory()->ownedBy($rep)->create();
        $contact = Contact::factory()->forCompany($company)->create();

        $this->actingAs($manager, 'api')
            ->patchJson("/api/v1/contacts/{$contact->id}", ['position' => 'Group HR Head'])
            ->assertOk()
            ->assertJsonPath('data.position', 'Group HR Head');
    }

    public function test_a_salesrep_cannot_read_or_change_another_salesreps_contact(): void
    {
        $rep = User::factory()->salesrep()->create();
        $other = User::factory()->salesrep()->create();
        $contact = Contact::factory()->forCompany(Company::factory()->ownedBy($other)->create())->create();

        $this->actingAs($rep, 'api')->getJson("/api/v1/contacts/{$contact->id}")->assertStatus(403);
        $this->actingAs($rep, 'api')->patchJson("/api/v1/contacts/{$contact->id}", ['name' => 'Nope'])->assertStatus(403);
        $this->actingAs($rep, 'api')->deleteJson("/api/v1/contacts/{$contact->id}")->assertStatus(403);
        $this->actingAs($rep, 'api')->postJson("/api/v1/contacts/{$contact->id}/make-primary")->assertStatus(403);
    }

    public function test_an_admin_can_manage_any_contact(): void
    {
        $contact = Contact::factory()->forCompany(Company::factory()->create())->create();

        $this->actingAs(User::factory()->admin()->create(), 'api')
            ->patchJson("/api/v1/contacts/{$contact->id}", ['role_type' => ContactRoleType::Executive->value])
            ->assertOk()
            ->assertJsonPath('data.role_type', 'executive');
    }
}
