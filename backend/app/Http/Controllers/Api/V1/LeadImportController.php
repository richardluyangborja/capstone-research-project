<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Leads\LeadCsvImport;
use App\Http\Requests\LeadImportRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

/**
 * The fixed-template lead import (spec 16 §CSV import, ADR-0026 §4). All roles may
 * use it; only the target owner differs, and the service enforces that.
 */
class LeadImportController extends Controller
{
    public function template(): Response
    {
        return response(LeadCsvImport::template(), 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="lead-import-template.csv"',
        ]);
    }

    public function preview(LeadImportRequest $request, LeadCsvImport $import): JsonResponse
    {
        $report = $import->preview(
            $request->file('file'),
            $request->user(),
            $request->filled('owner_id') ? $request->integer('owner_id') : null,
        );

        return response()->json(['data' => $report]);
    }

    public function store(LeadImportRequest $request, LeadCsvImport $import): JsonResponse
    {
        $report = $import->commit(
            $request->file('file'),
            $request->user(),
            $request->filled('owner_id') ? $request->integer('owner_id') : null,
        );

        return response()->json([
            'message' => sprintf(
                '%d lead(s) imported; %d row(s) skipped.',
                $report['imported'],
                $report['skipped'],
            ),
            'data' => $report,
        ]);
    }
}
