<?php

namespace Database\Factories;

use App\Enums\SurveyBand;
use App\Models\Company;
use App\Models\Survey;
use App\Models\SurveyInvitation;
use App\Models\SurveyResponse;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SurveyResponse>
 */
class SurveyResponseFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'invitation_id' => SurveyInvitation::factory(),
            'survey_id' => Survey::factory(),
            'company_id' => Company::factory(),
            'contact_id' => null,
            'submitted_at' => now(),
            'csat' => null,
            'nps' => null,
            'band' => null,
            'needs_attention' => false,
        ];
    }

    /**
     * A response that answers this invitation, borrowing its survey and account — the shape a
     * real submission has.
     */
    public function forInvitation(SurveyInvitation $invitation): static
    {
        return $this->state(fn (array $attributes): array => [
            'invitation_id' => $invitation->id,
            'survey_id' => $invitation->survey_id,
            'company_id' => $invitation->company_id,
            'contact_id' => $invitation->contact_id,
        ]);
    }

    /**
     * A scored response: CSAT mean, the promoter answer and what it means.
     */
    public function scored(?float $csat, ?int $nps): static
    {
        return $this->state(fn (array $attributes): array => [
            'csat' => $csat,
            'nps' => $nps,
            'band' => SurveyBand::fromNps($nps),
        ]);
    }
}
