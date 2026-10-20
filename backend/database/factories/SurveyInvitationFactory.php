<?php

namespace Database\Factories;

use App\Enums\SurveyInvitationStatus;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Survey;
use App\Models\SurveyInvitation;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SurveyInvitation>
 */
class SurveyInvitationFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'survey_id' => Survey::factory(),
            'company_id' => Company::factory(),
            'contact_id' => null,
            'contract_id' => null,
            'communication_id' => null,
            'recipient_name' => fake()->name(),
            'recipient_email' => fake()->unique()->safeEmail(),
            'message' => null,
            'status' => SurveyInvitationStatus::Sent,
            'sent_at' => now(),
            'expires_at' => now()->addDays(14),
            'created_by' => User::factory(),
        ];
    }

    /**
     * One invitation of this survey, sent to this account — the pair every state below needs.
     */
    public function forRecord(Survey $survey, Company $company, ?Contact $contact = null): static
    {
        return $this->state(fn (array $attributes): array => [
            'survey_id' => $survey->id,
            'company_id' => $company->id,
            'contact_id' => $contact?->id,
        ]);
    }

    public function expired(): static
    {
        return $this->state(fn (array $attributes): array => [
            'sent_at' => now()->subDays(30),
            'expires_at' => now()->subDays(16),
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => SurveyInvitationStatus::Failed,
            'failed_reason' => 'The recipient address was rejected.',
        ]);
    }

    public function cancelled(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => SurveyInvitationStatus::Cancelled,
        ]);
    }

    public function submitted(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => SurveyInvitationStatus::Submitted,
            'submitted_at' => now(),
        ]);
    }
}
