<?php

namespace App\Http\Leads;

use App\Enums\Role;
use App\Http\Requests\StoreLeadRequest;
use App\Models\Lead;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * The fixed-template CSV import (spec 01 rule 6–7, spec 16 §CSV import).
 *
 * Preview and commit run the same reader and row validation, so the commit can only write
 * the rows the preview would have accepted. Nothing here merges duplicates: a match is
 * advisory and is reported beside the row that would create it.
 */
final class LeadCsvImport
{
    public const MAX_ROWS = 500;

    /** The fixed template columns, in file order. */
    public const COLUMNS = [
        'company_name',
        'contact_name',
        'contact_email',
        'contact_phone',
        'contact_position',
        'source',
        'source_detail',
        'industry',
        'location',
        'service_interest',
        'estimated_headcount',
        'needed_roles',
        'target_start_date',
        'urgency',
        'notes',
    ];

    /**
     * Columns without which no row could be valid. Email and phone are checked separately
     * because a file may carry either channel, but not necessarily both.
     *
     * @var array<int, string>
     */
    private const REQUIRED_COLUMNS = ['company_name', 'contact_name', 'source'];

    public static function template(): string
    {
        $handle = fopen('php://temp', 'r+');
        fputcsv($handle, self::COLUMNS, ',', '"', '\\');
        rewind($handle);
        $content = (string) stream_get_contents($handle);
        fclose($handle);

        return $content;
    }

    /**
     * @return array<string, mixed>
     */
    public function preview(UploadedFile $file, User $actor, ?int $ownerId): array
    {
        return $this->analyse($file, $actor, $ownerId);
    }

    /**
     * Write only the valid rows and report the rest exactly as the preview did.
     *
     * @return array<string, mixed>
     */
    public function commit(UploadedFile $file, User $actor, ?int $ownerId): array
    {
        $report = $this->analyse($file, $actor, $ownerId);

        $imported = 0;

        foreach ($report['rows'] as $index => $row) {
            if (! $row['valid']) {
                continue;
            }

            $data = $row['values'];
            $data['source_detail'] = $this->sourceDetail(
                $report['file'],
                $data['source_detail'] ?? null,
            );
            $data['owner_id'] = $report['owner']['id'] ?? null;

            if (filled($data['estimated_headcount'] ?? null)) {
                $data['estimated_headcount'] = (int) $data['estimated_headcount'];
            }

            // One row is one record: a row that cannot be written must not roll back the
            // rows before it (spec 01 rule 6 — all-or-nothing per row).
            $lead = DB::transaction(fn (): Lead => Lead::create($data));

            $report['rows'][$index]['lead_id'] = $lead->id;
            $report['rows'][$index]['reference'] = $lead->reference;
            $imported++;
        }

        $report['imported'] = $imported;
        $report['skipped'] = $report['summary']['rows'] - $imported;

        return $report;
    }

    /**
     * @return array<string, mixed>
     */
    private function analyse(UploadedFile $file, User $actor, ?int $ownerId): array
    {
        $filename = $this->filename($file);
        [$headers, $records] = $this->read($file);

        $owner = $this->resolveOwner($actor, $ownerId);

        $rows = [];
        $valid = 0;
        $duplicateRows = 0;

        foreach ($records as $record) {
            $values = $this->mapValues($headers, $record['values']);

            $validator = Validator::make($values, StoreLeadRequest::fieldRules());

            if ($validator->fails()) {
                $rows[] = [
                    'line' => $record['line'],
                    'values' => $values,
                    'valid' => false,
                    'errors' => $validator->errors()->toArray(),
                    'duplicates' => [],
                    'lead_id' => null,
                    'reference' => null,
                ];

                continue;
            }

            $duplicates = Lead::findDuplicates($values);

            if ($duplicates !== []) {
                $duplicateRows++;
            }

            $valid++;
            $rows[] = [
                'line' => $record['line'],
                'values' => $values,
                'valid' => true,
                'errors' => [],
                'duplicates' => $duplicates,
                'lead_id' => null,
                'reference' => null,
            ];
        }

        return [
            'file' => $filename,
            'owner' => $owner ? ['id' => $owner->id, 'name' => $owner->name] : null,
            'can_choose_owner' => $actor->role !== Role::Salesrep,
            'rows' => $rows,
            'summary' => [
                'rows' => count($rows),
                'valid' => $valid,
                'invalid' => count($rows) - $valid,
                'duplicates' => $duplicateRows,
            ],
        ];
    }

    /**
     * @return array{0: array<int, string>, 1: array<int, array{line: int, values: array<int, string|null>}>}
     */
    private function read(UploadedFile $file): array
    {
        $path = $file->getRealPath();

        if ($path === false) {
            throw ValidationException::withMessages([
                'file' => 'The uploaded file could not be read. Please try again.',
            ]);
        }

        $handle = fopen($path, 'rb');

        if ($handle === false) {
            throw ValidationException::withMessages([
                'file' => 'The uploaded file could not be read. Please try again.',
            ]);
        }

        $headerRecord = fgetcsv($handle, 0, ',', '"', '\\');

        if ($headerRecord === false || $headerRecord === [null]) {
            fclose($handle);

            throw ValidationException::withMessages([
                'file' => 'The CSV file is empty. Download the template and try again.',
            ]);
        }

        $headers = array_map(
            fn ($header): string => strtolower(trim((string) $header)),
            $headerRecord,
        );

        // Excel writes a UTF-8 BOM; it must not become part of the first column name.
        $headers[0] = preg_replace('/^\xEF\xBB\xBF/', '', $headers[0]) ?? $headers[0];

        $this->assertHeaders($headers);

        $records = [];
        $line = 1;

        while (($record = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
            $line++;

            if ($this->isBlank($record)) {
                continue;
            }

            $records[] = ['line' => $line, 'values' => $record];

            if (count($records) > self::MAX_ROWS) {
                fclose($handle);

                throw ValidationException::withMessages([
                    'file' => sprintf(
                        'A CSV file may contain at most %d rows. Split the file and try again.',
                        self::MAX_ROWS,
                    ),
                ]);
            }
        }

        fclose($handle);

        return [$headers, $records];
    }

    /**
     * @param  array<int, string>  $headers
     */
    private function assertHeaders(array $headers): void
    {
        $missing = array_values(array_diff(self::REQUIRED_COLUMNS, $headers));

        if ($missing !== []) {
            throw ValidationException::withMessages([
                'file' => sprintf(
                    'The CSV is missing required column(s): %s. Download the template and try again.',
                    implode(', ', $missing),
                ),
            ]);
        }

        if (! in_array('contact_email', $headers, true) && ! in_array('contact_phone', $headers, true)) {
            throw ValidationException::withMessages([
                'file' => 'The CSV must include a contact_email or contact_phone column.',
            ]);
        }
    }

    /**
     * @param  array<int, string|null>  $record
     */
    private function isBlank(array $record): bool
    {
        foreach ($record as $value) {
            if (trim((string) $value) !== '') {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<int, string>  $headers
     * @param  array<int, string|null>  $record
     * @return array<string, string|null>
     */
    private function mapValues(array $headers, array $record): array
    {
        $indexes = array_flip($headers);
        $values = [];

        foreach (self::COLUMNS as $column) {
            $index = $indexes[$column] ?? null;
            $value = $index === null ? null : trim((string) ($record[$index] ?? ''));
            $values[$column] = $value === '' ? null : $value;
        }

        return $values;
    }

    private function resolveOwner(User $actor, ?int $ownerId): ?User
    {
        // A salesrep's imported rows are always their own; any owner_id in the request is
        // ignored rather than rejected (spec 16 §CSV import rule 4).
        if ($actor->role === Role::Salesrep) {
            return $actor;
        }

        if ($ownerId === null) {
            return null;
        }

        $owner = User::query()
            ->whereKey($ownerId)
            ->where('role', Role::Salesrep->value)
            ->first();

        if ($owner === null) {
            throw ValidationException::withMessages([
                'owner_id' => 'Choose a salesrep you are allowed to assign leads to.',
            ]);
        }

        if ($actor->role === Role::Manager
            && ($owner->team_id === null || $owner->team_id !== $actor->team_id)) {
            throw ValidationException::withMessages([
                'owner_id' => 'You may only assign imported leads to a salesrep on your team.',
            ]);
        }

        return $owner;
    }

    private function filename(UploadedFile $file): string
    {
        $name = basename($file->getClientOriginalName());
        $name = preg_replace('/[\x00-\x1F\x7F]/u', '', $name) ?? 'leads.csv';

        return mb_substr($name, 0, 120);
    }

    private function sourceDetail(string $filename, ?string $rowDetail): string
    {
        $detail = 'Imported from '.$filename;

        if (filled($rowDetail)) {
            $detail .= ' — '.trim((string) $rowDetail);
        }

        return mb_substr($detail, 0, 255);
    }
}
