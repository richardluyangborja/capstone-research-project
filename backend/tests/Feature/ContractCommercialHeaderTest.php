<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Contract;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ContractCommercialHeaderTest extends TestCase
{
    use RefreshDatabase;

    public function test_read_only_contract_reads_preserve_commercial_header(): void
    {
        $admin = User::factory()->admin()->create();
        $contract = Contract::factory()->forCompany(Company::factory()->create())->create([
            'payment_terms' => 'Net 45',
            'po_reference' => 'PO-READ-ONLY',
            'auto_renew' => true,
            'notice_days' => 60,
        ]);

        $this->actingAs($admin, 'api')
            ->getJson("/api/v1/contracts/{$contract->id}")
            ->assertOk()
            ->assertJsonPath('data.payment_terms', 'Net 45')
            ->assertJsonPath('data.po_reference', 'PO-READ-ONLY')
            ->assertJsonPath('data.auto_renew', true)
            ->assertJsonPath('data.notice_days', 60);
    }

    public function test_contract_header_cannot_be_modified(): void
    {
        $admin = User::factory()->admin()->create();
        $contract = Contract::factory()->forCompany(Company::factory()->create())->create();

        $this->actingAs($admin, 'api')
            ->patchJson("/api/v1/contracts/{$contract->id}", ['payment_terms' => 'Net 60'])
            ->assertStatus(405);

        $this->assertDatabaseHas('contracts', [
            'id' => $contract->id,
            'payment_terms' => $contract->payment_terms,
        ]);
    }
}
