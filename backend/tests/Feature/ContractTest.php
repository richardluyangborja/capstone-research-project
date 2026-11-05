<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Contract;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_contract_detail_is_a_read_only_projection_with_provenance(): void
    {
        $admin = User::factory()->admin()->create();
        $contract = Contract::factory()->forCompany(Company::factory()->create())->signed()->create([
            'external_id' => 'core3-CON-2026-0001',
            'synced_at' => now()->subMinute(),
        ]);

        $this->actingAs($admin, 'api')
            ->getJson("/api/v1/contracts/{$contract->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $contract->id)
            ->assertJsonPath('data.external_id', 'core3-CON-2026-0001')
            ->assertJsonPath('meta.source', 'Contract Management (mocked, read-only)')
            ->assertJsonPath('meta.read_only', true)
            ->assertJsonMissingPath('data.file')
            ->assertJsonMissingPath('data.can_update')
            ->assertJsonMissingPath('data.can_mark_signed');
    }

    public function test_contract_write_and_document_routes_are_not_registered(): void
    {
        $admin = User::factory()->admin()->create();
        $company = Company::factory()->create();
        $contract = Contract::factory()->forCompany($company)->create();

        $this->actingAs($admin, 'api')
            ->postJson("/api/v1/companies/{$company->id}/contracts")
            ->assertStatus(405);

        $this->actingAs($admin, 'api')
            ->patchJson("/api/v1/contracts/{$contract->id}", ['title' => 'Changed'])
            ->assertStatus(405);

        $this->actingAs($admin, 'api')
            ->postJson("/api/v1/contracts/{$contract->id}/mark-signed")
            ->assertNotFound();

        $this->actingAs($admin, 'api')
            ->getJson("/api/v1/contracts/{$contract->id}/file")
            ->assertNotFound();
    }
}
