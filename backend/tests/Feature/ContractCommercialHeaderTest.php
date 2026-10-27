<?php

namespace Tests\Feature;

use App\Enums\ActivityType;
use App\Models\Activity;
use App\Models\Company;
use App\Models\Contract;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ContractCommercialHeaderTest extends TestCase
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
            'payment_terms' => 'Net 30',
            'po_reference' => 'PO-HL-2026-004',
            'auto_renew' => true,
            'notice_days' => 60,
            'signatory_name' => 'Elena Cruz',
            'signatory_position' => 'VP, Operations',
            ...$overrides,
        ];
    }

    public function test_the_commercial_header_is_stored_and_returned_on_upload(): void
    {
        $rep = User::factory()->salesrep()->create();
        $company = Company::factory()->ownedBy($rep)->create();

        $this->actingAs($rep, 'api')
            ->post("/api/v1/companies/{$company->id}/contracts", $this->payload(), ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('data.payment_terms', 'Net 30')
            ->assertJsonPath('data.po_reference', 'PO-HL-2026-004')
            ->assertJsonPath('data.auto_renew', true)
            ->assertJsonPath('data.notice_days', 60)
            ->assertJsonPath('data.signatory_name', 'Elena Cruz')
            ->assertJsonPath('data.signatory_position', 'VP, Operations')
            // The headline value is negotiated, never derived from the header.
            ->assertJsonPath('data.value', '2400000.00');

        $contract = Contract::firstOrFail();

        $this->assertSame('Net 30', $contract->payment_terms);
        $this->assertTrue($contract->auto_renew);
        $this->assertSame(60, $contract->notice_days);
    }

    public function test_a_contract_without_a_header_defaults_to_no_auto_renewal(): void
    {
        $rep = User::factory()->salesrep()->create();
        $company = Company::factory()->ownedBy($rep)->create();

        $payload = $this->payload();
        unset($payload['payment_terms'], $payload['po_reference'], $payload['auto_renew'], $payload['notice_days'], $payload['signatory_name'], $payload['signatory_position']);

        $this->actingAs($rep, 'api')
            ->post("/api/v1/companies/{$company->id}/contracts", $payload, ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('data.payment_terms', null)
            ->assertJsonPath('data.auto_renew', false)
            ->assertJsonPath('data.notice_days', null);

        $this->assertFalse(Contract::firstOrFail()->auto_renew);
    }

    public function test_editing_the_header_persists_and_records_the_change_on_the_account_timeline(): void
    {
        $rep = User::factory()->salesrep()->create();
        $company = Company::factory()->ownedBy($rep)->create();
        $contract = Contract::factory()->forCompany($company)->create([
            'title' => 'Old title',
            'payment_terms' => 'Net 15',
            'po_reference' => 'PO-OLD',
        ]);

        $this->actingAs($rep, 'api')
            ->patchJson("/api/v1/contracts/{$contract->id}", [
                'payment_terms' => 'Net 30',
                'po_reference' => 'PO-NEW-001',
                'auto_renew' => true,
                'notice_days' => 45,
                'signatory_name' => 'Elena Cruz',
                'signatory_position' => 'VP, Operations',
            ])
            ->assertOk()
            ->assertJsonPath('data.payment_terms', 'Net 30')
            ->assertJsonPath('data.po_reference', 'PO-NEW-001')
            ->assertJsonPath('data.auto_renew', true)
            ->assertJsonPath('data.notice_days', 45)
            ->assertJsonPath('data.signatory_name', 'Elena Cruz');

        $contract->refresh();

        $this->assertSame('Net 30', $contract->payment_terms);
        $this->assertSame('PO-NEW-001', $contract->po_reference);
        $this->assertTrue($contract->auto_renew);
        $this->assertSame(45, $contract->notice_days);

        $activity = Activity::query()
            ->where('subject_type', Company::class)
            ->where('subject_id', $company->id)
            ->where('subject', 'Contract '.$contract->reference.' updated')
            ->first();

        $this->assertNotNull($activity);
        $this->assertSame(ActivityType::System, $activity->type);
        $this->assertSame($rep->id, $activity->owner_id);
        $this->assertStringContainsString('Payment terms', $activity->body);
        $this->assertStringContainsString('PO reference', $activity->body);
        $this->assertStringContainsString('auto-renew', $activity->body);
        $this->assertStringContainsString('notice window', $activity->body);
    }

    public function test_a_no_op_header_update_writes_no_activity(): void
    {
        $rep = User::factory()->salesrep()->create();
        $company = Company::factory()->ownedBy($rep)->create();
        $contract = Contract::factory()->forCompany($company)->create(['payment_terms' => 'Net 30']);

        $this->actingAs($rep, 'api')
            ->patchJson("/api/v1/contracts/{$contract->id}", ['payment_terms' => 'Net 30'])
            ->assertOk();

        $this->assertSame(
            0,
            Activity::query()
                ->where('subject_type', Company::class)
                ->where('subject_id', $company->id)
                ->where('subject', 'Contract '.$contract->reference.' updated')
                ->count(),
        );
    }

    public function test_only_someone_who_may_update_the_account_can_edit_the_header(): void
    {
        $owner = User::factory()->salesrep()->create();
        $outsider = User::factory()->salesrep()->create();
        $company = Company::factory()->ownedBy($owner)->create();
        $contract = Contract::factory()->forCompany($company)->create();

        $this->actingAs($outsider, 'api')
            ->patchJson("/api/v1/contracts/{$contract->id}", ['payment_terms' => 'Net 90'])
            ->assertStatus(403);

        $this->assertSame('Net 30', $contract->fresh()->payment_terms);

        $this->actingAs($owner, 'api')
            ->patchJson("/api/v1/contracts/{$contract->id}", ['payment_terms' => 'Net 45'])
            ->assertOk()
            ->assertJsonPath('data.payment_terms', 'Net 45');
    }

    public function test_a_manager_may_edit_a_team_accounts_contract_header(): void
    {
        $team = Team::factory()->create();
        $manager = User::factory()->manager()->create(['team_id' => $team->id]);
        $rep = User::factory()->salesrep()->create(['team_id' => $team->id]);
        $company = Company::factory()->ownedBy($rep)->create();
        $contract = Contract::factory()->forCompany($company)->create();

        $this->actingAs($manager, 'api')
            ->patchJson("/api/v1/contracts/{$contract->id}", ['notice_days' => 90])
            ->assertOk()
            ->assertJsonPath('data.notice_days', 90);
    }

    public function test_the_header_is_validated(): void
    {
        $rep = User::factory()->salesrep()->create();
        $company = Company::factory()->ownedBy($rep)->create();
        $contract = Contract::factory()->forCompany($company)->create();

        $this->actingAs($rep, 'api')
            ->patchJson("/api/v1/contracts/{$contract->id}", [
                'notice_days' => -1,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('notice_days');

        $this->actingAs($rep, 'api')
            ->patchJson("/api/v1/contracts/{$contract->id}", [
                'notice_days' => 366,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('notice_days');

        $this->actingAs($rep, 'api')
            ->patchJson("/api/v1/contracts/{$contract->id}", [
                'auto_renew' => 'not-a-boolean',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('auto_renew');
    }

    public function test_editing_the_header_does_not_touch_the_negotiated_value(): void
    {
        $rep = User::factory()->salesrep()->create();
        $company = Company::factory()->ownedBy($rep)->create();
        $contract = Contract::factory()->forCompany($company)->create(['value' => 1250000]);

        $this->actingAs($rep, 'api')
            ->patchJson("/api/v1/contracts/{$contract->id}", [
                'payment_terms' => 'Net 60',
                'auto_renew' => true,
            ])
            ->assertOk()
            ->assertJsonPath('data.value', '1250000.00');

        $this->assertSame('1250000.00', $contract->fresh()->value);
    }
}
