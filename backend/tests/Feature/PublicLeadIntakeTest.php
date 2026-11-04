<?php

namespace Tests\Feature;

use App\Enums\LeadSource;
use App\Enums\LeadStatus;
use App\Models\Lead;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Module 01 refresh S17 — the landing page's public request-staffing form.
 *
 * The endpoint is unauthenticated, so it is narrow: validation, a honeypot and throttling keep
 * abuse out, while the created record is always an unassigned website lead with consent stamped.
 */
class PublicLeadIntakeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // The route uses Laravel's numeric throttle; clear its cache between test cases.
        Cache::flush();
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'company_name' => 'Northpoint Retail',
            'contact_name' => 'Ana Cruz',
            'contact_email' => 'ana@northpoint.example',
            'contact_phone' => '+63 917 000 0000',
            'industry' => 'Retail',
            'service_interest' => 'Warehouse staffing',
            'estimated_headcount' => 12,
            'needed_roles' => 'Warehouse associates, inventory lead',
            'target_start_date' => '2026-11-15',
            'message' => 'We are opening two stores.',
            'consent' => true,
        ], $overrides);
    }

    public function test_a_valid_public_request_creates_an_unassigned_website_lead(): void
    {
        $response = $this->postJson('/api/v1/public/leads', $this->payload());

        $response->assertCreated()
            ->assertJsonPath('message', 'Thank you — your staffing request has been sent. Our team will be in touch.')
            ->assertJsonMissingPath('data')
            ->assertJsonMissingPath('id')
            ->assertJsonMissingPath('duplicates');

        $lead = Lead::query()->sole();

        $this->assertSame('Northpoint Retail', $lead->company_name);
        $this->assertSame('Ana Cruz', $lead->contact_name);
        $this->assertSame('ana@northpoint.example', $lead->contact_email);
        $this->assertSame('We are opening two stores.', $lead->notes);
        $this->assertSame(LeadSource::Website, $lead->source);
        $this->assertSame('Public landing form', $lead->source_detail);
        $this->assertSame(LeadStatus::New, $lead->status);
        $this->assertNull($lead->owner_id);
        $this->assertNotNull($lead->consent_at);
    }

    public function test_required_fields_and_a_contact_channel_are_enforced(): void
    {
        $this->postJson('/api/v1/public/leads', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['company_name', 'contact_name', 'contact_email', 'contact_phone', 'consent']);

        $this->postJson('/api/v1/public/leads', $this->payload([
            'contact_email' => null,
            'contact_phone' => null,
        ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['contact_email', 'contact_phone']);

        $this->postJson('/api/v1/public/leads', $this->payload(['consent' => false]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['consent']);

        $this->assertDatabaseCount('leads', 0);
    }

    public function test_the_honeypot_rejects_a_filled_hidden_field(): void
    {
        $this->postJson('/api/v1/public/leads', $this->payload(['website' => 'https://spam.example']))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['website']);

        $this->assertDatabaseCount('leads', 0);
    }

    public function test_the_public_form_cannot_choose_an_owner_status_score_or_source(): void
    {
        $owner = User::factory()->salesrep()->create();

        $this->postJson('/api/v1/public/leads', $this->payload([
            'owner_id' => $owner->id,
            'status' => LeadStatus::Qualified->value,
            'score' => 100,
            'rating' => 'hot',
            'source' => LeadSource::Referral->value,
            'source_detail' => 'Not the public form',
            'budget_rate' => 9999,
        ]))->assertCreated();

        $lead = Lead::query()->sole();

        $this->assertNull($lead->owner_id);
        $this->assertSame(LeadStatus::New, $lead->status);
        $this->assertNull($lead->score);
        $this->assertSame(LeadSource::Website, $lead->source);
        $this->assertSame('Public landing form', $lead->source_detail);
        $this->assertNull($lead->budget_rate);
    }

    public function test_duplicate_matching_is_advisory_and_not_exposed_to_the_visitor(): void
    {
        Lead::factory()->create([
            'company_name' => 'Northpoint Retail',
            'contact_email' => 'ana@northpoint.example',
        ]);

        $response = $this->postJson('/api/v1/public/leads', $this->payload());

        $response->assertCreated()
            ->assertJsonMissingPath('duplicates')
            ->assertJsonMissingPath('data');

        $this->assertDatabaseCount('leads', 2);
    }

    public function test_public_intake_is_throttled(): void
    {
        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->postJson('/api/v1/public/leads', $this->payload([
                'company_name' => "Northpoint {$attempt}",
            ]))->assertCreated();
        }

        $this->postJson('/api/v1/public/leads', $this->payload(['company_name' => 'Northpoint 6']))
            ->assertStatus(429);

        $this->assertDatabaseCount('leads', 5);
    }
}
