<?php

namespace Database\Factories;

use App\Enums\CommunicationChannel;
use App\Enums\CommunicationDirection;
use App\Enums\CommunicationOutcome;
use App\Enums\CommunicationStatus;
use App\Models\Communication;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Contract;
use App\Models\Lead;
use App\Models\Opportunity;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Communication>
 */
class CommunicationFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'subject_type' => Lead::class,
            'subject_id' => Lead::factory(),
            'channel' => CommunicationChannel::Email,
            'direction' => CommunicationDirection::Outbound,
            'status' => CommunicationStatus::Sent,
            'subject' => fake()->sentence(4),
            'body' => fake()->paragraph(),
            'to_name' => fake()->name(),
            'to_address' => fake()->safeEmail(),
            'occurred_at' => now(),
            'owner_id' => User::factory(),
        ];
    }

    /**
     * Hang the message off a record — a lead, account, contact, deal or contract.
     */
    public function about(Lead|Company|Contact|Opportunity|Contract $subject, ?User $owner = null): static
    {
        $attributes = [
            'subject_type' => $subject::class,
            'subject_id' => $subject->id,
        ];

        if ($subject instanceof Contact) {
            $attributes['contact_id'] = $subject->id;
        }

        if ($owner !== null) {
            $attributes['owner_id'] = $owner->id;
        }

        return $this->state($attributes);
    }

    public function ownedBy(User $user): static
    {
        return $this->state(fn (array $attributes): array => ['owner_id' => $user->id]);
    }

    public function channel(CommunicationChannel $channel): static
    {
        return $this->state(fn (array $attributes): array => ['channel' => $channel]);
    }

    public function inbound(): static
    {
        return $this->state(fn (array $attributes): array => [
            'direction' => CommunicationDirection::Inbound,
            'status' => CommunicationStatus::Received,
        ]);
    }

    public function status(CommunicationStatus $status): static
    {
        return $this->state(fn (array $attributes): array => ['status' => $status]);
    }

    /**
     * A scheduled follow-up (module 04 slice S2): an outbound email that has not gone out yet,
     * so it carries a due moment and no `occurred_at` — the model leaves that one null.
     */
    public function scheduled(?CarbonInterface $due = null): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => CommunicationStatus::Scheduled,
            'scheduled_for' => $due ?? now()->addDay(),
            'occurred_at' => null,
        ]);
    }

    /**
     * A call that was taken (or attempted), rather than a message that was sent.
     */
    public function call(CommunicationOutcome $outcome = CommunicationOutcome::Connected): static
    {
        return $this->state(fn (array $attributes): array => [
            'channel' => CommunicationChannel::Call,
            'status' => CommunicationStatus::Logged,
            'outcome' => $outcome,
            'duration_minutes' => $outcome === CommunicationOutcome::Connected ? 12 : null,
        ]);
    }
}
