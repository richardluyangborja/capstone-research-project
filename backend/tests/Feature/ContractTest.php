<?php

namespace Tests\Feature;

use App\Enums\CompanyStatus;
use App\Enums\ContractStatus;
use App\Enums\HandoffStatus;
use App\Events\CompanyActivated;
use App\Events\ContractSigned;
use App\Models\Company;
use App\Models\Contract;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ContractTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return [
            'file' => UploadedFile::fake()->create('signed-agreement.pdf', 120, 'application/pdf'),
            'title' => 'Forklift operator pooling agreement',
            'start_date' => now()->toDateString(),
            'end_date' => now()->addYear()->toDateString(),
            'value' => 2400000,
            'terms' => 'Net 30, 60-day notice on volume changes.',
            ...$overrides,
        ];
    }

    public function test_a_contract_is_uploaded_with_its_document_and_terms(): void
    {
        $rep = User::factory()->salesrep()->create();
        $company = Company::factory()->ownedBy($rep)->create();

        $response = $this->actingAs($rep, 'api')
            ->post("/api/v1/companies/{$company->id}/contracts", $this->payload(), ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.currency', 'PHP')
            ->assertJsonPath('data.value', '2400000.00')
            ->assertJsonPath('data.file.present', true)
            ->assertJsonPath('data.file.name', 'signed-agreement.pdf')
            ->assertJsonPath('data.owner_id', $rep->id)
            ->assertJsonPath('data.company_id', $company->id);

        $contract = Contract::firstOrFail();

        $this->assertStringStartsWith('CON-', $contract->reference);
        $this->assertSame(ContractStatus::Draft, $contract->status);

        // The document goes to the private disk, never to a public path.
        Storage::disk('local')->assertExists($contract->file_path);
        $this->assertStringStartsWith('contracts/', $contract->file_path);
        $this->assertSame('application/pdf', $contract->file_mime);

        $this->assertDatabaseHas('activities', [
            'subject_type' => Company::class,
            'subject_id' => $company->id,
            'subject' => 'Contract '.$contract->reference.' uploaded',
        ]);

        $response->assertJsonPath('data.reference', $contract->reference);
    }

    public function test_a_contract_document_must_be_a_pdf_or_image(): void
    {
        $rep = User::factory()->salesrep()->create();
        $company = Company::factory()->ownedBy($rep)->create();

        $this->actingAs($rep, 'api')
            ->post("/api/v1/companies/{$company->id}/contracts", $this->payload([
                'file' => UploadedFile::fake()->create('notes.txt', 4, 'text/plain'),
            ]), ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('file');

        $this->assertSame(0, Contract::count());
    }

    public function test_a_contract_cannot_be_created_already_signed(): void
    {
        $rep = User::factory()->salesrep()->create();
        $company = Company::factory()->ownedBy($rep)->create();

        // Signing has side effects (activation + handoff), so it has its own action.
        $this->actingAs($rep, 'api')
            ->post("/api/v1/companies/{$company->id}/contracts", $this->payload([
                'status' => ContractStatus::Signed->value,
            ]), ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('status');
    }

    public function test_a_renewal_must_reference_a_contract_of_the_same_company(): void
    {
        $rep = User::factory()->salesrep()->create();
        $company = Company::factory()->ownedBy($rep)->create();
        $other = Company::factory()->ownedBy($rep)->create();

        $foreign = Contract::factory()->forCompany($other)->expired()->create();

        $this->actingAs($rep, 'api')
            ->post("/api/v1/companies/{$company->id}/contracts", $this->payload([
                'renewal_of_contract_id' => $foreign->id,
            ]), ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('renewal_of_contract_id');
    }

    public function test_a_renewal_chains_to_the_contract_it_replaces(): void
    {
        $rep = User::factory()->salesrep()->create();
        $company = Company::factory()->ownedBy($rep)->create();
        $original = Contract::factory()->forCompany($company)->expired()->create();

        $renewalId = $this->actingAs($rep, 'api')
            ->post("/api/v1/companies/{$company->id}/contracts", $this->payload([
                'renewal_of_contract_id' => $original->id,
            ]), ['Accept' => 'application/json'])
            ->assertCreated()
            ->json('data.id');

        $this->actingAs($rep, 'api')
            ->getJson("/api/v1/contracts/{$renewalId}")
            ->assertOk()
            ->assertJsonPath('data.renewal_of_contract_id', $original->id)
            ->assertJsonPath('data.renewal_of.reference', $original->reference);
    }

    public function test_the_contracts_tab_lists_the_contracts_of_a_company(): void
    {
        $rep = User::factory()->salesrep()->create();
        $company = Company::factory()->ownedBy($rep)->create();

        Contract::factory()->forCompany($company)->signed()->create(['title' => 'Live agreement']);
        Contract::factory()->forCompany($company)->expired()->create(['title' => 'Old agreement']);

        $this->actingAs($rep, 'api')
            ->getJson("/api/v1/companies/{$company->id}/contracts")
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('meta.active', 1);

        $this->actingAs($rep, 'api')
            ->getJson("/api/v1/companies/{$company->id}/contracts?filter[status]=signed")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'Live agreement')
            ->assertJsonPath('data.0.is_active', true);
    }

    public function test_signing_a_contract_activates_the_client_and_hands_it_to_service(): void
    {
        $rep = User::factory()->salesrep()->create();
        $company = Company::factory()->ownedBy($rep)->create(['status' => CompanyStatus::Prospect]);
        $contract = Contract::factory()->forCompany($company)->create();

        $activated = null;
        Event::listen(CompanyActivated::class, function (CompanyActivated $event) use (&$activated): void {
            $activated = $event;
        });

        $this->actingAs($rep, 'api')
            ->postJson("/api/v1/contracts/{$contract->id}/mark-signed")
            ->assertOk()
            ->assertJsonPath('data.status', 'signed')
            ->assertJsonPath('data.is_active', true);

        $company->refresh();

        $this->assertSame(CompanyStatus::Active, $company->status);
        $this->assertNotNull($company->client_since);
        $this->assertSame(HandoffStatus::Sent, $company->handoff_status);
        $this->assertSame('SVC-'.$contract->reference, $company->handoff_reference);
        $this->assertNull($company->handoff_error);
        $this->assertNotNull($company->handoff_requested_at);

        $this->assertNotNull($contract->fresh()->signed_at);
        $this->assertNotNull($activated);
        $this->assertTrue($activated->company->is($company));

        $this->assertDatabaseHas('activities', [
            'subject_type' => Company::class,
            'subject_id' => $company->id,
            'subject' => 'Contract '.$contract->reference.' signed — handed off to Service',
        ]);
        $this->assertDatabaseHas('activities', [
            'subject_type' => Company::class,
            'subject_id' => $company->id,
            'subject' => 'Client status changed prospect → active',
        ]);
    }

    public function test_an_established_client_is_not_reset_by_a_second_contract(): void
    {
        $rep = User::factory()->salesrep()->create();
        $company = Company::factory()->ownedBy($rep)->create([
            'status' => CompanyStatus::Dormant,
            'client_since' => now()->subYears(2)->toDateString(),
        ]);
        $contract = Contract::factory()->forCompany($company)->create();

        $this->actingAs($rep, 'api')
            ->postJson("/api/v1/contracts/{$contract->id}/mark-signed")
            ->assertOk();

        $company->refresh();

        // Repeat business does not resurrect — or reset — a client (spec 09 §3).
        $this->assertSame(CompanyStatus::Dormant, $company->status);
        $this->assertSame(now()->subYears(2)->toDateString(), $company->client_since?->toDateString());
        $this->assertSame(HandoffStatus::Sent, $company->handoff_status);
    }

    public function test_a_failed_handoff_keeps_the_signed_contract_and_leaves_a_retry(): void
    {
        config(['crm.integrations.service_handoff.simulate_failure' => true]);

        $rep = User::factory()->salesrep()->create();
        $company = Company::factory()->ownedBy($rep)->create(['status' => CompanyStatus::Prospect]);
        $contract = Contract::factory()->forCompany($company)->create();

        $this->actingAs($rep, 'api')
            ->postJson("/api/v1/contracts/{$contract->id}/mark-signed")
            ->assertOk()
            ->assertJsonPath('data.status', 'signed');

        $company->refresh();

        // The integration failure never rolls the contract back (spec 09 §2).
        $this->assertSame(ContractStatus::Signed, $contract->fresh()->status);
        $this->assertSame(CompanyStatus::Onboarding, $company->status);
        $this->assertSame(HandoffStatus::Failed, $company->handoff_status);
        $this->assertNotNull($company->handoff_error);
        $this->assertNull($company->handoff_reference);

        $this->actingAs($rep, 'api')
            ->getJson("/api/v1/companies/{$company->id}")
            ->assertOk()
            ->assertJsonPath('data.handoff.status', 'failed')
            ->assertJsonPath('data.handoff.can_retry', true)
            ->assertJsonPath('data.status', 'onboarding');

        // Once the mock recovers, the retry activates the client.
        config(['crm.integrations.service_handoff.simulate_failure' => false]);

        $this->actingAs($rep, 'api')
            ->postJson("/api/v1/companies/{$company->id}/handoff/retry")
            ->assertOk()
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.handoff.status', 'sent');

        $company->refresh();

        $this->assertSame(HandoffStatus::Sent, $company->handoff_status);
        $this->assertSame('SVC-'.$contract->reference, $company->handoff_reference);
        $this->assertNull($company->handoff_error);
    }

    public function test_retrying_a_handoff_without_a_signed_contract_is_rejected(): void
    {
        $rep = User::factory()->salesrep()->create();
        $company = Company::factory()->ownedBy($rep)->create();

        $this->actingAs($rep, 'api')
            ->postJson("/api/v1/companies/{$company->id}/handoff/retry")
            ->assertStatus(422)
            ->assertJsonValidationErrors('handoff');
    }

    public function test_a_contract_cannot_be_signed_twice(): void
    {
        $rep = User::factory()->salesrep()->create();
        $company = Company::factory()->ownedBy($rep)->create();
        $contract = Contract::factory()->forCompany($company)->signed()->create();

        $this->actingAs($rep, 'api')
            ->postJson("/api/v1/contracts/{$contract->id}/mark-signed")
            ->assertStatus(422)
            ->assertJsonValidationErrors('status');
    }

    public function test_an_expired_contract_cannot_be_signed(): void
    {
        $rep = User::factory()->salesrep()->create();
        $company = Company::factory()->ownedBy($rep)->create();
        $contract = Contract::factory()->forCompany($company)->expired()->create();

        $this->actingAs($rep, 'api')
            ->postJson("/api/v1/contracts/{$contract->id}/mark-signed")
            ->assertStatus(422);
    }

    public function test_a_contract_status_cannot_be_jumped_to_signed_by_patch(): void
    {
        $rep = User::factory()->salesrep()->create();
        $company = Company::factory()->ownedBy($rep)->create();
        $contract = Contract::factory()->forCompany($company)->create();

        $this->actingAs($rep, 'api')
            ->patchJson("/api/v1/contracts/{$contract->id}", ['status' => ContractStatus::Signed->value])
            ->assertStatus(422)
            ->assertJsonValidationErrors('status');

        // A legal clerical move still works: the agreement goes out to the client.
        $this->actingAs($rep, 'api')
            ->patchJson("/api/v1/contracts/{$contract->id}", ['status' => ContractStatus::Sent->value])
            ->assertOk()
            ->assertJsonPath('data.status', 'sent');

        $this->actingAs($rep, 'api')
            ->patchJson("/api/v1/contracts/{$contract->id}", ['status' => ContractStatus::Signed->value])
            ->assertStatus(422);

        $this->assertSame(ContractStatus::Sent, $contract->fresh()->status);
    }

    public function test_only_a_manager_may_terminate_a_contract(): void
    {
        $team = Team::factory()->create();
        $rep = User::factory()->salesrep()->create(['team_id' => $team->id]);
        $manager = User::factory()->manager()->create(['team_id' => $team->id]);
        $company = Company::factory()->ownedBy($rep)->create();
        $contract = Contract::factory()->forCompany($company)->signed()->create();

        $this->actingAs($rep, 'api')
            ->patchJson("/api/v1/contracts/{$contract->id}", ['status' => ContractStatus::Terminated->value])
            ->assertStatus(422)
            ->assertJsonValidationErrors('status');

        $this->actingAs($manager, 'api')
            ->patchJson("/api/v1/contracts/{$contract->id}", ['status' => ContractStatus::Terminated->value])
            ->assertOk()
            ->assertJsonPath('data.status', 'terminated');
    }

    public function test_the_document_downloads_through_an_authorized_endpoint(): void
    {
        $rep = User::factory()->salesrep()->create();
        $other = User::factory()->salesrep()->create();
        $company = Company::factory()->ownedBy($rep)->create();

        $contract = Contract::factory()->forCompany($company)->create([
            'file_path' => 'contracts/agreement.pdf',
            'file_name' => 'agreement.pdf',
            'file_mime' => 'application/pdf',
        ]);

        Storage::disk('local')->put('contracts/agreement.pdf', 'signed bytes');

        $this->actingAs($other, 'api')
            ->getJson("/api/v1/contracts/{$contract->id}/file")
            ->assertStatus(403);

        $this->actingAs($rep, 'api')
            ->get("/api/v1/contracts/{$contract->id}/file")
            ->assertOk()
            ->assertDownload('agreement.pdf');

        // A contract with no document is a 404, not an empty file.
        $noFile = Contract::factory()->forCompany($company)->create();

        $this->actingAs($rep, 'api')
            ->getJson("/api/v1/contracts/{$noFile->id}/file")
            ->assertNotFound();
    }

    public function test_contracts_are_scoped_to_the_account(): void
    {
        $team = Team::factory()->create();
        $otherTeam = Team::factory()->create();
        $rep = User::factory()->salesrep()->create(['team_id' => $team->id]);
        $manager = User::factory()->manager()->create(['team_id' => $team->id]);
        $outsider = User::factory()->salesrep()->create(['team_id' => $otherTeam->id]);

        $company = Company::factory()->ownedBy($rep)->create();
        $contract = Contract::factory()->forCompany($company)->create();

        $this->actingAs($outsider, 'api')
            ->getJson("/api/v1/contracts/{$contract->id}")
            ->assertStatus(403);

        $this->actingAs($outsider, 'api')
            ->getJson("/api/v1/companies/{$company->id}/contracts")
            ->assertStatus(403);

        $this->actingAs($outsider, 'api')
            ->post("/api/v1/companies/{$company->id}/contracts", $this->payload(), ['Accept' => 'application/json'])
            ->assertStatus(403);

        $this->actingAs($manager, 'api')
            ->getJson("/api/v1/contracts/{$contract->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $contract->id);
    }

    public function test_a_salesrep_cannot_delete_a_contract_but_a_manager_can(): void
    {
        $team = Team::factory()->create();
        $rep = User::factory()->salesrep()->create(['team_id' => $team->id]);
        $manager = User::factory()->manager()->create(['team_id' => $team->id]);
        $company = Company::factory()->ownedBy($rep)->create();
        $contract = Contract::factory()->forCompany($company)->signed()->create();

        $this->actingAs($rep, 'api')
            ->deleteJson("/api/v1/contracts/{$contract->id}")
            ->assertStatus(403);

        $this->assertNotSoftDeleted('contracts', ['id' => $contract->id]);

        $this->actingAs($manager, 'api')
            ->deleteJson("/api/v1/contracts/{$contract->id}")
            ->assertOk();

        $this->assertSoftDeleted('contracts', ['id' => $contract->id]);

        $this->actingAs($manager, 'api')
            ->postJson("/api/v1/contracts/{$contract->id}/restore")
            ->assertOk()
            ->assertJsonPath('data.id', $contract->id);
    }

    public function test_an_active_contract_blocks_deleting_the_company(): void
    {
        $manager = User::factory()->manager()->create();
        $company = Company::factory()->ownedBy($manager)->create();

        Contract::factory()->forCompany($company)->signed()->create();

        $this->actingAs($manager, 'api')
            ->deleteJson("/api/v1/companies/{$company->id}")
            ->assertStatus(422)
            ->assertJsonValidationErrors('company');

        $this->assertNotSoftDeleted('companies', ['id' => $company->id]);

        // A retired contract does not block anything.
        $company->contracts()->update(['status' => ContractStatus::Terminated->value]);

        $this->actingAs($manager, 'api')
            ->deleteJson("/api/v1/companies/{$company->id}")
            ->assertNoContent();
    }

    public function test_the_company_payload_reports_the_contract_count_and_lifecycle_affordances(): void
    {
        $team = Team::factory()->create();
        $rep = User::factory()->salesrep()->create(['team_id' => $team->id]);
        $manager = User::factory()->manager()->create(['team_id' => $team->id]);
        $company = Company::factory()->ownedBy($rep)->create();

        Contract::factory()->forCompany($company)->signed()->create();
        Contract::factory()->forCompany($company)->create();

        $this->actingAs($rep, 'api')
            ->getJson("/api/v1/companies/{$company->id}")
            ->assertOk()
            ->assertJsonPath('data.counts.contracts', 2)
            ->assertJsonPath('data.can_upload_contract', true)
            ->assertJsonPath('data.can_manage_lifecycle', false)
            ->assertJsonPath('data.can_view_billing', false);

        $this->actingAs($manager, 'api')
            ->getJson("/api/v1/companies/{$company->id}")
            ->assertOk()
            ->assertJsonPath('data.can_manage_lifecycle', true)
            ->assertJsonPath('data.can_view_billing', true);
    }

    public function test_a_contract_expiring_inside_the_window_is_flagged(): void
    {
        $rep = User::factory()->salesrep()->create();
        $company = Company::factory()->ownedBy($rep)->create();

        $soon = Contract::factory()->forCompany($company)->expiringSoon()->create();
        $later = Contract::factory()->forCompany($company)->signed()->create([
            'end_date' => now()->addMonths(8)->toDateString(),
        ]);

        $this->assertTrue($soon->isExpiringSoon());
        $this->assertFalse($later->isExpiringSoon());
        $this->assertSame(10, $soon->daysUntilExpiry());

        $this->actingAs($rep, 'api')
            ->getJson("/api/v1/companies/{$company->id}/contracts?filter[expiring]=true")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $soon->id);
    }

    public function test_contract_events_are_dispatched_when_a_contract_is_signed(): void
    {
        Event::fake([ContractSigned::class]);

        $rep = User::factory()->salesrep()->create();
        $company = Company::factory()->ownedBy($rep)->create();
        $contract = Contract::factory()->forCompany($company)->create();

        $this->actingAs($rep, 'api')
            ->postJson("/api/v1/contracts/{$contract->id}/mark-signed")
            ->assertOk();

        Event::assertDispatched(ContractSigned::class, fn (ContractSigned $event): bool => $event->contract->is($contract));
    }
}
