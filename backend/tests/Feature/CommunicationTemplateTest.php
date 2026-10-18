<?php

namespace Tests\Feature;

use App\Models\Communication;
use App\Models\CommunicationTemplate;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Lead;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Slice S5 — templates and merge fields (spec 11 §S5, ADR-0017 §10).
 *
 * A template is the house's wording: every role reads and renders one, only manager+ writes one,
 * and rendering is a prefill rather than a write — what gets saved is an ordinary communication
 * that never points back at the template.
 */
class CommunicationTemplateTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function template(User $author, array $attributes = []): CommunicationTemplate
    {
        return CommunicationTemplate::factory()->authoredBy($author)->create($attributes);
    }

    public function test_the_book_is_readable_by_every_role_and_marks_what_a_salesrep_may_do(): void
    {
        $manager = User::factory()->manager()->create();
        $rep = User::factory()->salesrep()->create();
        $this->template($manager, ['name' => 'Rate sheet follow-up']);

        $this->actingAs($rep, 'api')
            ->getJson('/api/v1/communication-templates')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.name', 'Rate sheet follow-up')
            ->assertJsonPath('data.0.author.name', $manager->name)
            // Reading and using are open to everyone; changing is not.
            ->assertJsonPath('data.0.can_update', false)
            ->assertJsonPath('data.0.can_delete', false)
            ->assertJsonPath('data.0.can_restore', false);
    }

    public function test_a_salesrep_cannot_author_edit_retire_or_restore_a_template(): void
    {
        $manager = User::factory()->manager()->create();
        $rep = User::factory()->salesrep()->create();
        $template = $this->template($manager);

        $this->actingAs($rep, 'api')->postJson('/api/v1/communication-templates', [
            'name' => 'Rep wording',
            'channel' => 'email',
            'subject' => 'Hello',
            'body' => 'Hi there',
        ])->assertForbidden();

        $this->actingAs($rep, 'api')
            ->patchJson("/api/v1/communication-templates/{$template->id}", ['subject' => 'Changed'])
            ->assertForbidden();

        $this->actingAs($rep, 'api')
            ->deleteJson("/api/v1/communication-templates/{$template->id}")
            ->assertForbidden();

        $this->actingAs($rep, 'api')
            ->postJson("/api/v1/communication-templates/{$template->id}/restore")
            ->assertForbidden();

        $this->assertNull($template->fresh()->deleted_at);
    }

    public function test_a_manager_authors_a_template_and_it_starts_active(): void
    {
        $manager = User::factory()->manager()->create();

        // The channel is what the wording is written for, so it is required rather than guessed.
        $this->actingAs($manager, 'api')->postJson('/api/v1/communication-templates', [
            'name' => 'Cold call script',
            'subject' => 'First call',
            'body' => 'Good morning…',
        ])->assertStatus(422)->assertJsonValidationErrors('channel');

        $this->actingAs($manager, 'api')->postJson('/api/v1/communication-templates', [
            'name' => '  Cold call script  ',
            'channel' => 'call',
            'subject' => 'First call — {{company.name}}',
            'body' => 'Good morning, this is {{user.name}}.',
        ])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Cold call script')
            ->assertJsonPath('data.channel', 'call')
            ->assertJsonPath('data.is_active', true)
            ->assertJsonPath('data.author.name', $manager->name)
            ->assertJsonPath('data.can_update', true);
    }

    public function test_a_live_duplicate_name_is_refused_but_a_retired_one_frees_the_name(): void
    {
        $manager = User::factory()->manager()->create();
        $first = $this->template($manager, ['name' => 'Rate sheet follow-up']);

        $payload = [
            'name' => 'rate sheet FOLLOW-UP',
            'channel' => 'email',
            'subject' => 'Rates',
            'body' => 'Here they are.',
        ];

        $this->actingAs($manager, 'api')
            ->postJson('/api/v1/communication-templates', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors('name');

        // Retiring one frees the label — the name is unique among *live* templates only.
        $this->actingAs($manager, 'api')
            ->deleteJson("/api/v1/communication-templates/{$first->id}")
            ->assertNoContent();

        $this->actingAs($manager, 'api')
            ->postJson('/api/v1/communication-templates', $payload)
            ->assertCreated();
    }

    public function test_restoring_is_refused_when_a_live_template_has_taken_the_name(): void
    {
        $manager = User::factory()->manager()->create();
        $old = $this->template($manager, ['name' => 'Site visit confirmation']);

        $this->actingAs($manager, 'api')
            ->deleteJson("/api/v1/communication-templates/{$old->id}")
            ->assertNoContent();

        $this->template($manager, ['name' => 'Site visit confirmation']);

        // Two templates answering to one label would make the picker ambiguous, so the retired
        // one cannot come back until the live one is renamed.
        $this->actingAs($manager, 'api')
            ->postJson("/api/v1/communication-templates/{$old->id}/restore")
            ->assertStatus(422)
            ->assertJsonValidationErrors('name');

        $this->assertNotNull($old->fresh()->deleted_at);
    }

    public function test_a_retired_template_leaves_the_picker_but_is_still_listed_in_the_book(): void
    {
        $manager = User::factory()->manager()->create();
        $live = $this->template($manager, ['name' => 'Meeting agenda']);
        $retired = $this->template($manager, ['name' => 'Holiday greeting', 'is_active' => false]);

        $this->actingAs($manager, 'api')
            ->getJson('/api/v1/communication-templates?filter[active]=true')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $live->id);

        $this->actingAs($manager, 'api')
            ->getJson('/api/v1/communication-templates?filter[active]=false')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $retired->id);

        // The book lists both, because the filter is a view rather than the whole truth.
        $this->actingAs($manager, 'api')
            ->getJson('/api/v1/communication-templates')
            ->assertOk()
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('data.0.is_active', true);
    }

    public function test_the_book_filters_by_channel_and_searches_the_wording(): void
    {
        $manager = User::factory()->manager()->create();
        $email = $this->template($manager, ['name' => 'Rate sheet follow-up', 'channel' => 'email']);
        $this->template($manager, ['name' => 'Cold call script', 'channel' => 'call']);

        $this->actingAs($manager, 'api')
            ->getJson('/api/v1/communication-templates?filter[channel]=call')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.name', 'Cold call script');

        $this->actingAs($manager, 'api')
            ->getJson('/api/v1/communication-templates?search=rate sheet')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $email->id);
    }

    public function test_a_manager_edits_a_template_and_a_retired_one_cannot_be_read(): void
    {
        $manager = User::factory()->manager()->create();
        $template = $this->template($manager, ['name' => 'Meeting agenda']);

        $this->actingAs($manager, 'api')
            ->patchJson("/api/v1/communication-templates/{$template->id}", [
                'name' => 'Manpower review agenda',
                'body' => 'Attendees: {{contact.name}}.',
                'is_active' => false,
            ])
            ->assertOk()
            ->assertJsonPath('data.name', 'Manpower review agenda')
            ->assertJsonPath('data.is_active', false);

        $this->actingAs($manager, 'api')
            ->deleteJson("/api/v1/communication-templates/{$template->id}")
            ->assertNoContent();

        $this->actingAs($manager, 'api')
            ->getJson("/api/v1/communication-templates/{$template->id}")
            ->assertNotFound();

        $this->actingAs($manager, 'api')
            ->postJson("/api/v1/communication-templates/{$template->id}/render", [
                'subject_type' => 'company',
                'subject_id' => Company::factory()->create()->id,
            ])
            ->assertNotFound();

        $this->actingAs($manager, 'api')
            ->postJson("/api/v1/communication-templates/{$template->id}/restore")
            ->assertOk()
            ->assertJsonPath('data.name', 'Manpower review agenda');
    }

    public function test_fields_returns_the_merge_vocabulary_the_renderer_can_fill(): void
    {
        $rep = User::factory()->salesrep()->create();

        $response = $this->actingAs($rep, 'api')
            ->getJson('/api/v1/communication-templates/fields')
            ->assertOk();

        $tokens = array_column($response->json('data'), 'token');

        $this->assertContains('contact.first_name', $tokens);
        $this->assertContains('company.name', $tokens);
        $this->assertContains('today', $tokens);
        // Every offered token is one the renderer actually resolves.
        $this->assertSame(
            array_keys(CommunicationTemplate::mergeFields()),
            $tokens,
        );
    }

    public function test_render_fills_the_fields_from_the_record_and_the_caller(): void
    {
        $manager = User::factory()->manager()->create();
        $company = Company::factory()->ownedBy($manager)->create(['name' => 'Sunrise Foods Inc']);
        $contact = Contact::factory()->forCompany($company)->primary()->create([
            'name' => 'Ramon Villanueva',
            'position' => 'Plant HR Manager',
        ]);

        $template = $this->template($manager, [
            'subject' => 'Rate sheet for {{company.name}}',
            'body' => 'Hi {{contact.first_name}} ({{contact.name}}, {{contact.position}}) — {{user.name}}, {{today}}',
        ]);

        $today = Carbon::now()->timezone(config('crm.templates.timezone'))->format('d M Y');

        $this->actingAs($manager, 'api')
            ->postJson("/api/v1/communication-templates/{$template->id}/render", [
                'subject_type' => 'company',
                'subject_id' => $company->id,
                'contact_id' => $contact->id,
            ])
            ->assertOk()
            ->assertJsonPath('data.channel', 'email')
            ->assertJsonPath('data.subject', 'Rate sheet for Sunrise Foods Inc')
            ->assertJsonPath('data.body', "Hi Ramon (Ramon Villanueva, Plant HR Manager) — {$manager->name}, {$today}")
            ->assertJsonPath('data.unresolved', []);

        // With no contact named, the account's primary one is the person the message is with.
        $this->actingAs($manager, 'api')
            ->postJson("/api/v1/communication-templates/{$template->id}/render", [
                'subject_type' => 'company',
                'subject_id' => $company->id,
            ])
            ->assertOk()
            ->assertJsonPath('data.body', "Hi Ramon (Ramon Villanueva, Plant HR Manager) — {$manager->name}, {$today}");

        // Rendering is a prefill, not a write: nothing was logged and nothing was created.
        $this->assertSame(0, Communication::query()->count());
        $this->assertSame(1, CommunicationTemplate::query()->count());
    }

    public function test_render_leaves_what_it_cannot_fill_verbatim_and_reports_it(): void
    {
        $manager = User::factory()->manager()->create();
        // No contacts at all, so every `contact.*` field is empty for this record.
        $company = Company::factory()->ownedBy($manager)->create(['name' => 'Greenfield Hotels']);

        $template = $this->template($manager, [
            'subject' => 'Hello {{company.name}}',
            'body' => 'Hi {{contact.first_name}}, about {{mystery.token}}.',
        ]);

        $this->actingAs($manager, 'api')
            ->postJson("/api/v1/communication-templates/{$template->id}/render", [
                'subject_type' => 'company',
                'subject_id' => $company->id,
            ])
            ->assertOk()
            // The line is honest about the gap instead of being silently blank.
            ->assertJsonPath('data.body', 'Hi {{contact.first_name}}, about {{mystery.token}}.')
            ->assertJsonPath('data.unresolved', ['contact.first_name', 'mystery.token']);
    }

    public function test_render_uses_a_leads_own_contact_details(): void
    {
        $rep = User::factory()->salesrep()->create();
        $lead = Lead::factory()->create([
            'company_name' => 'Sunrise Foods Inc',
            'contact_name' => 'Grace Lim',
            'contact_position' => 'Recruitment Officer',
            'contact_email' => 'grace@sunrise.test',
            'owner_id' => $rep->id,
        ]);

        $template = $this->template($rep, [
            'subject' => 'Following up with {{company.name}}',
            'body' => 'Hi {{contact.first_name}} ({{contact.position}}) — {{contact.email}}',
        ]);

        $this->actingAs($rep, 'api')
            ->postJson("/api/v1/communication-templates/{$template->id}/render", [
                'subject_type' => 'lead',
                'subject_id' => $lead->id,
            ])
            ->assertOk()
            ->assertJsonPath('data.subject', 'Following up with Sunrise Foods Inc')
            ->assertJsonPath('data.body', 'Hi Grace (Recruitment Officer) — grace@sunrise.test')
            ->assertJsonPath('data.unresolved', []);
    }

    public function test_render_needs_the_view_right_on_the_record_it_reads(): void
    {
        $rep = User::factory()->salesrep()->create();
        $manager = User::factory()->manager()->create();
        $otherRep = User::factory()->salesrep()->create();

        $theirCompany = Company::factory()->ownedBy($otherRep)->create(['name' => 'BPO Solutions PH']);
        $theirContact = Contact::factory()->forCompany($theirCompany)->create();

        $template = $this->template($manager);

        $this->actingAs($rep, 'api')
            ->postJson("/api/v1/communication-templates/{$template->id}/render", [
                'subject_type' => 'company',
                'subject_id' => $theirCompany->id,
            ])
            ->assertForbidden();

        // Naming a record and naming a person are both disclosures, so both are checked.
        $owned = Company::factory()->ownedBy($rep)->create();

        $this->actingAs($rep, 'api')
            ->postJson("/api/v1/communication-templates/{$template->id}/render", [
                'subject_type' => 'company',
                'subject_id' => $owned->id,
                'contact_id' => $theirContact->id,
            ])
            ->assertForbidden();

        $this->actingAs($rep, 'api')
            ->postJson("/api/v1/communication-templates/{$template->id}/render", [
                'subject_type' => 'company',
                'subject_id' => $owned->id,
            ])
            ->assertOk();
    }

    public function test_render_refuses_a_record_that_does_not_exist(): void
    {
        $manager = User::factory()->manager()->create();
        $template = $this->template($manager);

        $this->actingAs($manager, 'api')
            ->postJson("/api/v1/communication-templates/{$template->id}/render", [
                'subject_type' => 'opportunity',
                'subject_id' => '01JZZZZZZZZZZZZZZZZZZZZZZZ',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('subject_id');
    }
}
