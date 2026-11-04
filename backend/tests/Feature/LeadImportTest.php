<?php

namespace Tests\Feature;

use App\Http\Leads\LeadCsvImport;
use App\Models\Lead;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class LeadImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_role_can_download_the_template(): void
    {
        foreach ([User::factory()->salesrep()->create(), User::factory()->manager()->create(), User::factory()->admin()->create()] as $user) {
            $response = $this->actingAs($user, 'api')->get('/api/v1/leads/import/template');

            $response->assertOk()
                ->assertHeader('content-type', 'text/csv; charset=UTF-8')
                ->assertHeader('content-disposition', 'attachment; filename="lead-import-template.csv"');

            $this->assertStringContainsString('company_name,contact_name', $response->getContent());
        }
    }

    public function test_preview_reports_valid_invalid_and_duplicate_rows_without_writing(): void
    {
        $manager = User::factory()->manager()->create();
        $existing = Lead::factory()->create([
            'company_name' => 'Northpoint Retail',
            'contact_email' => 'old@northpoint.example',
            'contact_phone' => null,
        ]);

        $file = $this->csv([
            $this->row(['company_name' => 'Northpoint Retail']),
            $this->row([
                'company_name' => 'Missing Channel',
                'contact_email' => '',
                'contact_phone' => '',
            ]),
            $this->row([
                'company_name' => 'Other Source',
                'contact_email' => 'other@example.test',
                'source' => 'other',
                'source_detail' => '',
            ]),
            $this->row([
                'company_name' => 'Valid New Co',
                'contact_email' => 'new@example.test',
            ]),
        ]);

        $response = $this->actingAs($manager, 'api')->post('/api/v1/leads/import/preview', [
            'file' => $file,
        ], ['Accept' => 'application/json']);

        $response->assertOk()
            ->assertJsonPath('data.file', 'leads.csv')
            ->assertJsonPath('data.owner', null)
            ->assertJsonPath('data.can_choose_owner', true)
            ->assertJsonPath('data.summary.rows', 4)
            ->assertJsonPath('data.summary.valid', 2)
            ->assertJsonPath('data.summary.invalid', 2)
            ->assertJsonPath('data.summary.duplicates', 1)
            ->assertJsonPath('data.rows.0.valid', true)
            ->assertJsonPath('data.rows.0.duplicates.0.id', $existing->id)
            ->assertJsonPath('data.rows.1.valid', false)
            ->assertJsonPath('data.rows.2.valid', false)
            ->assertJsonPath('data.rows.2.errors.source_detail.0', 'The source detail field is required when source is other.');

        $this->assertDatabaseCount('leads', 1);
    }

    public function test_a_salesrep_owns_imported_rows_and_owner_id_is_ignored(): void
    {
        $rep = User::factory()->salesrep()->create();
        $other = User::factory()->salesrep()->create();
        $file = $this->csv([$this->row()]);

        $preview = $this->actingAs($rep, 'api')->post('/api/v1/leads/import/preview', [
            'file' => $file,
            'owner_id' => $other->id,
        ], ['Accept' => 'application/json']);

        $preview->assertOk()
            ->assertJsonPath('data.owner.id', $rep->id)
            ->assertJsonPath('data.can_choose_owner', false);

        $this->actingAs($rep, 'api')->post('/api/v1/leads/import', [
            'file' => $this->csv([$this->row()]),
            'owner_id' => 999999,
        ], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('data.imported', 1)
            ->assertJsonPath('data.owner.id', $rep->id);

        $this->assertSame($rep->id, Lead::firstOrFail()->owner_id);
    }

    public function test_a_manager_imports_into_the_unassigned_queue_by_default(): void
    {
        $manager = User::factory()->manager()->create();

        $this->actingAs($manager, 'api')->post('/api/v1/leads/import', [
            'file' => $this->csv([$this->row()]),
        ], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('data.imported', 1)
            ->assertJsonPath('data.owner', null);

        $this->assertNull(Lead::firstOrFail()->owner_id);
    }

    public function test_a_manager_can_choose_a_team_owner_for_the_import(): void
    {
        $team = Team::factory()->create();
        $manager = User::factory()->manager()->create(['team_id' => $team->id]);
        $rep = User::factory()->salesrep()->create(['team_id' => $team->id]);

        $this->actingAs($manager, 'api')->post('/api/v1/leads/import', [
            'file' => $this->csv([$this->row(), $this->row(['company_name' => 'Second Co'])]),
            'owner_id' => $rep->id,
        ], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('data.imported', 2)
            ->assertJsonPath('data.owner.id', $rep->id);

        $this->assertSame(2, Lead::where('owner_id', $rep->id)->count());
    }

    public function test_a_manager_cannot_choose_an_owner_outside_their_team(): void
    {
        $team = Team::factory()->create();
        $manager = User::factory()->manager()->create(['team_id' => $team->id]);
        $outsider = User::factory()->salesrep()->create();

        $this->actingAs($manager, 'api')->post('/api/v1/leads/import', [
            'file' => $this->csv([$this->row()]),
            'owner_id' => $outsider->id,
        ], ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('owner_id');

        $this->assertDatabaseCount('leads', 0);
    }

    public function test_an_admin_can_choose_any_salesrep(): void
    {
        $admin = User::factory()->admin()->create();
        $rep = User::factory()->salesrep()->create();

        $this->actingAs($admin, 'api')->post('/api/v1/leads/import', [
            'file' => $this->csv([$this->row()]),
            'owner_id' => $rep->id,
        ], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('data.owner.id', $rep->id);

        $this->assertSame($rep->id, Lead::firstOrFail()->owner_id);
    }

    public function test_commit_skips_invalid_rows_and_names_the_file_in_source_detail(): void
    {
        $admin = User::factory()->admin()->create();

        $file = $this->csv([
            $this->row(['company_name' => 'Imported One']),
            $this->row(['company_name' => '', 'contact_email' => 'broken@example.test']),
            $this->row([
                'company_name' => 'Imported Two',
                'contact_email' => 'two@example.test',
                'source_detail' => 'October list',
            ]),
        ], headers: LeadCsvImport::COLUMNS, filename: 'october-leads.csv');

        $response = $this->actingAs($admin, 'api')->post('/api/v1/leads/import', [
            'file' => $file,
        ], ['Accept' => 'application/json']);

        $response->assertOk()
            ->assertJsonPath('data.imported', 2)
            ->assertJsonPath('data.skipped', 1)
            ->assertJsonPath('data.summary.valid', 2)
            ->assertJsonPath('data.summary.invalid', 1)
            ->assertJsonPath('data.rows.1.valid', false);

        $this->assertDatabaseCount('leads', 2);
        $this->assertDatabaseHas('leads', [
            'company_name' => 'Imported One',
            'source_detail' => 'Imported from october-leads.csv',
        ]);
        $this->assertDatabaseHas('leads', [
            'company_name' => 'Imported Two',
            'source_detail' => 'Imported from october-leads.csv — October list',
        ]);
    }

    public function test_duplicates_are_reported_but_are_not_merged(): void
    {
        $rep = User::factory()->salesrep()->create();
        $existing = Lead::factory()->create([
            'company_name' => 'Northpoint Retail',
            'contact_email' => 'ana@northpoint.example',
            'contact_phone' => null,
        ]);

        $this->actingAs($rep, 'api')->post('/api/v1/leads/import', [
            'file' => $this->csv([$this->row()]),
        ], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('data.summary.duplicates', 1)
            ->assertJsonPath('data.rows.0.duplicates.0.id', $existing->id);

        $this->assertDatabaseCount('leads', 2);
    }

    public function test_the_file_is_capped_at_500_rows(): void
    {
        $admin = User::factory()->admin()->create();
        $rows = [];

        for ($i = 1; $i <= 501; $i++) {
            $rows[] = $this->row([
                'company_name' => 'Company '.$i,
                'contact_email' => 'contact'.$i.'@example.test',
            ]);
        }

        $this->actingAs($admin, 'api')->post('/api/v1/leads/import/preview', [
            'file' => $this->csv($rows),
        ], ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('file');

        $this->assertDatabaseCount('leads', 0);
    }

    public function test_a_missing_required_column_is_rejected(): void
    {
        $admin = User::factory()->admin()->create();
        $headers = array_values(array_diff(LeadCsvImport::COLUMNS, ['source']));

        $this->actingAs($admin, 'api')->post('/api/v1/leads/import/preview', [
            'file' => $this->csv([$this->row()], headers: $headers),
        ], ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('file');
    }

    public function test_a_file_without_a_channel_column_is_rejected(): void
    {
        $admin = User::factory()->admin()->create();
        $headers = array_values(array_diff(LeadCsvImport::COLUMNS, ['contact_email', 'contact_phone']));

        $this->actingAs($admin, 'api')->post('/api/v1/leads/import/preview', [
            'file' => $this->csv([$this->row()], headers: $headers),
        ], ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('file');
    }

    public function test_preview_requires_a_csv_file(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin, 'api')->post('/api/v1/leads/import/preview', [], ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('file');
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @param  array<int, string>  $headers
     */
    private function csv(
        array $rows,
        array $headers = LeadCsvImport::COLUMNS,
        string $filename = 'leads.csv',
    ): UploadedFile {
        $handle = fopen('php://temp', 'r+');
        fputcsv($handle, $headers, ',', '"', '\\');

        foreach ($rows as $row) {
            fputcsv(
                $handle,
                array_map(fn (string $header): string => (string) ($row[$header] ?? ''), $headers),
                ',',
                '"',
                '\\',
            );
        }

        rewind($handle);
        $content = (string) stream_get_contents($handle);
        fclose($handle);

        return UploadedFile::fake()->createWithContent($filename, $content);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function row(array $overrides = []): array
    {
        return array_merge([
            'company_name' => 'Northpoint Retail',
            'contact_name' => 'Ana Cruz',
            'contact_email' => 'ana@northpoint.example',
            'contact_phone' => '',
            'contact_position' => 'HR Manager',
            'source' => 'referral',
            'source_detail' => '',
            'industry' => 'Retail',
            'location' => 'Makati',
            'service_interest' => 'Warehouse staffing',
            'estimated_headcount' => '12',
            'needed_roles' => 'Warehouse associates',
            'target_start_date' => '2026-12-01',
            'urgency' => 'high',
            'notes' => 'Opening two stores.',
        ], $overrides);
    }
}
