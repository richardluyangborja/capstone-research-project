<?php

namespace Database\Factories;

use App\Models\Attachment;
use App\Models\Communication;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Attachment>
 */
class AttachmentFactory extends Factory
{
    /**
     * The row only: a file's bytes live on a disk, and a test that needs to read them back
     * fakes the disk and writes the file it expects (see `AttachmentTest`).
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'disk' => config('crm.attachments.disk'),
            'path' => 'attachments/'.fake()->uuid().'.pdf',
            'original_name' => fake()->word().'-notes.pdf',
            'mime_type' => 'application/pdf',
            'size' => fake()->numberBetween(20_000, 900_000),
            'uploaded_by' => User::factory(),
            'attachable_type' => Communication::class,
            'attachable_id' => Communication::factory(),
        ];
    }

    public function uploadedBy(User $user): static
    {
        return $this->state(fn (array $attributes): array => ['uploaded_by' => $user->id]);
    }

    /** A scan of a signed page rather than a document the rep typed. */
    public function scan(): static
    {
        return $this->state(fn (array $attributes): array => [
            'original_name' => fake()->word().'-scan.png',
            'mime_type' => 'image/png',
        ]);
    }
}
