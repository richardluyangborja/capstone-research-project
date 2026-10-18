<?php

namespace Tests\Feature;

use App\Enums\CommunicationStatus;
use App\Models\Attachment;
use App\Models\Communication;
use App\Models\Company;
use App\Models\Opportunity;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Slice S3 — files on a communication (spec 11 §S3): the polymorphic `attachments` table
 * `00-data-model.md` deferred, uploaded to a private disk and streamed back through an
 * authorized endpoint, with the record's rights deciding who and the message's state deciding
 * whether its files may still change.
 */
class AttachmentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    private function dealFor(User $owner): Opportunity
    {
        return Opportunity::factory()
            ->forCompany(Company::factory()->ownedBy($owner)->create())
            ->ownedBy($owner)
            ->create();
    }

    /** A logged call — the everyday case: a touch recorded after the fact, files and all. */
    private function callFor(User $owner): Communication
    {
        return Communication::factory()->about($this->dealFor($owner), $owner)->call()->create();
    }

    private function pdf(string $name = 'rate-card.pdf'): UploadedFile
    {
        return UploadedFile::fake()->create($name, 120, 'application/pdf');
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return TestResponse
     */
    private function upload(User $user, Communication $communication, ?array $payload = null)
    {
        return $this->actingAs($user, 'api')->post(
            "/api/v1/communications/{$communication->id}/attachments",
            // `null` uploads the default PDF; an empty array sends nothing at all.
            $payload ?? ['file' => $this->pdf()],
            ['Accept' => 'application/json'],
        );
    }

    public function test_a_file_is_attached_to_a_message_and_streamed_back_through_an_authorized_endpoint(): void
    {
        $rep = User::factory()->salesrep()->create();
        $call = $this->callFor($rep);

        $response = $this->upload($rep, $call)
            ->assertCreated()
            ->assertJsonPath('data.name', 'rate-card.pdf')
            ->assertJsonPath('data.mime_type', 'application/pdf')
            ->assertJsonPath('data.size', 120 * 1024)
            ->assertJsonPath('data.uploaded_by', $rep->id)
            ->assertJsonPath('data.uploader.name', $rep->name)
            ->assertJsonPath('data.can_delete', true);

        $attachment = Attachment::firstOrFail();

        // The bytes go to the private disk under a generated name; the uploader's filename is
        // kept for display and never becomes the path.
        Storage::disk('local')->assertExists($attachment->path);
        $this->assertStringStartsWith('attachments/', $attachment->path);
        $this->assertNotSame('rate-card.pdf', basename($attachment->path));
        $this->assertSame($call->id, $attachment->attachable_id);
        $response->assertJsonPath('data.id', $attachment->id);

        $download = $this->actingAs($rep, 'api')->get("/api/v1/attachments/{$attachment->id}/file");

        $download->assertOk();
        // It comes back under the name the uploader gave it, not the stored one.
        $download->assertHeader('content-disposition', 'attachment; filename=rate-card.pdf');
    }

    public function test_the_stored_bytes_are_what_the_download_returns(): void
    {
        $rep = User::factory()->salesrep()->create();
        $call = $this->callFor($rep);
        $attachment = Attachment::factory()->uploadedBy($rep)->create([
            'attachable_type' => Communication::class,
            'attachable_id' => $call->id,
        ]);

        Storage::disk('local')->put($attachment->path, 'the signed rate card');

        $this->actingAs($rep, 'api')
            ->get("/api/v1/attachments/{$attachment->id}/file")
            ->assertOk()
            ->assertStreamedContent('the signed rate card');
    }

    public function test_only_a_pdf_or_image_within_the_size_ceiling_is_accepted(): void
    {
        $rep = User::factory()->salesrep()->create();
        $call = $this->callFor($rep);

        $this->upload($rep, $call, ['file' => UploadedFile::fake()->create('notes.txt', 4, 'text/plain')])
            ->assertStatus(422)
            ->assertJsonValidationErrors('file');

        $this->upload($rep, $call, ['file' => UploadedFile::fake()->create('huge.pdf', 11000, 'application/pdf')])
            ->assertStatus(422)
            ->assertJsonValidationErrors('file');

        $this->upload($rep, $call, [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('file');

        $this->assertSame(0, Attachment::count());
    }

    public function test_a_message_carries_at_most_five_files(): void
    {
        $rep = User::factory()->salesrep()->create();
        $call = $this->callFor($rep);

        Attachment::factory()->count(5)->uploadedBy($rep)->create([
            'attachable_type' => Communication::class,
            'attachable_id' => $call->id,
        ]);

        $this->upload($rep, $call)
            ->assertStatus(422)
            ->assertJsonValidationErrors('file');

        $this->assertSame(5, $call->attachments()->count());
    }

    public function test_a_message_that_has_left_keeps_the_files_it_carried(): void
    {
        $rep = User::factory()->salesrep()->create();
        $deal = $this->dealFor($rep);
        $sent = Communication::factory()->about($deal, $rep)->create();
        $received = Communication::factory()->about($deal, $rep)->inbound()->create();
        $failed = Communication::factory()->about($deal, $rep)
            ->status(CommunicationStatus::Failed)
            ->create();

        foreach ([$sent, $received] as $locked) {
            $this->upload($rep, $locked)
                ->assertStatus(422)
                ->assertJsonValidationErrors('status');
        }

        // A rejected send never went anywhere, so it is still the rep's to fix before retrying.
        $this->upload($rep, $failed)->assertCreated();

        // And what a delivered message already carries cannot be taken away either.
        $carried = Attachment::factory()->uploadedBy($rep)->create([
            'attachable_type' => Communication::class,
            'attachable_id' => $sent->id,
        ]);

        $this->actingAs($rep, 'api')
            ->deleteJson("/api/v1/attachments/{$carried->id}")
            ->assertStatus(422)
            ->assertJsonValidationErrors('status');

        $this->assertDatabaseHas('attachments', ['id' => $carried->id]);
    }

    public function test_attaching_reads_and_removing_follow_the_records_rights(): void
    {
        $team = Team::factory()->create();
        $rep = User::factory()->salesrep()->create(['team_id' => $team->id]);
        $manager = User::factory()->manager()->create(['team_id' => $team->id]);
        $outsider = User::factory()->salesrep()->create();

        $call = $this->callFor($rep);
        $attachment = Attachment::factory()->uploadedBy($rep)->create([
            'attachable_type' => Communication::class,
            'attachable_id' => $call->id,
        ]);

        // A salesrep works their own record; someone else's is not theirs to touch.
        $this->upload($outsider, $call)->assertForbidden();
        $this->actingAs($outsider, 'api')
            ->get("/api/v1/attachments/{$attachment->id}/file")
            ->assertForbidden();
        $this->actingAs($outsider, 'api')
            ->deleteJson("/api/v1/attachments/{$attachment->id}")
            ->assertForbidden();

        // A manager oversees the team's records.
        Storage::disk('local')->put($attachment->path, 'the signed rate card');

        $this->upload($manager, $call)->assertCreated();
        $this->actingAs($manager, 'api')
            ->get("/api/v1/attachments/{$attachment->id}/file")
            ->assertOk();
    }

    public function test_removing_a_file_takes_it_off_the_disk(): void
    {
        $rep = User::factory()->salesrep()->create();
        $call = $this->callFor($rep);

        $this->upload($rep, $call)->assertCreated();

        $attachment = Attachment::firstOrFail();
        $path = $attachment->path;

        $this->actingAs($rep, 'api')
            ->deleteJson("/api/v1/attachments/{$attachment->id}")
            ->assertNoContent();

        $this->assertDatabaseMissing('attachments', ['id' => $attachment->id]);
        Storage::disk('local')->assertMissing($path);
    }

    public function test_a_file_that_is_missing_from_storage_is_a_404(): void
    {
        $rep = User::factory()->salesrep()->create();
        $call = $this->callFor($rep);
        $attachment = Attachment::factory()->uploadedBy($rep)->create([
            'attachable_type' => Communication::class,
            'attachable_id' => $call->id,
        ]);

        // The row is there, the file is not — say so rather than handing back an empty download.
        $this->actingAs($rep, 'api')
            ->getJson("/api/v1/attachments/{$attachment->id}/file")
            ->assertNotFound();
    }

    public function test_the_payload_carries_the_files_and_whether_more_may_be_added(): void
    {
        $rep = User::factory()->salesrep()->create();
        $call = $this->callFor($rep);

        $this->upload($rep, $call)->assertCreated();

        $this->actingAs($rep, 'api')
            ->getJson("/api/v1/communications/{$call->id}")
            ->assertOk()
            ->assertJsonPath('data.attachments.0.name', 'rate-card.pdf')
            ->assertJsonPath('data.attachments.0.uploader.name', $rep->name)
            ->assertJsonPath('data.can_attach', true);

        // A message that has gone out takes no more files, so the control is not offered.
        $sent = Communication::factory()->about($this->dealFor($rep), $rep)->create();

        $this->actingAs($rep, 'api')
            ->getJson("/api/v1/communications/{$sent->id}")
            ->assertOk()
            ->assertJsonPath('data.can_attach', false)
            ->assertJsonPath('data.attachments', []);

        // The book carries the count without the files themselves.
        $book = $this->actingAs($rep, 'api')->getJson('/api/v1/communications')->assertOk();
        $row = collect($book->json('data'))->firstWhere('id', $call->id);

        $this->assertSame(1, $row['attachments_count']);
        $this->assertArrayNotHasKey('attachments', $row);
    }

    public function test_destroying_a_message_outright_takes_its_files_with_it(): void
    {
        $rep = User::factory()->salesrep()->create();
        $call = $this->callFor($rep);

        $this->upload($rep, $call)->assertCreated();

        $attachment = Attachment::firstOrFail();
        $path = $attachment->path;

        // A retired message keeps its files (it is still readable); one destroyed outright
        // does not leave them behind on the disk.
        $call->forceDelete();

        $this->assertDatabaseMissing('attachments', ['id' => $attachment->id]);
        Storage::disk('local')->assertMissing($path);
    }
}
