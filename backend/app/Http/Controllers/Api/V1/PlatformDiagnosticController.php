<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\AuditSeverity;
use App\Http\Controllers\Controller;
use App\Http\Diagnostics\PlatformDiagnostics;
use App\Models\AuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Superadmin platform diagnostics (module 08 slice S8, spec 19 §S8, ADR-0030).
 *
 * One read-only endpoint behind `PlatformDiagnosticPolicy`. It returns the bounded
 * `PlatformDiagnostics` payload and appends a `diagnostic.viewed` audit event for every
 * successful access. There is no SQL console, schema dump, write action, migration, backup,
 * command runner or export here; support exports remain the audit book's bounded, redacted CSV.
 */
class PlatformDiagnosticController extends Controller
{
    public function __construct(private readonly PlatformDiagnostics $diagnostics) {}

    public function show(Request $request): JsonResponse
    {
        Gate::authorize('viewPlatformDiagnostics');

        $user = $request->user();
        $data = $this->diagnostics->build();

        AuditLog::record(
            'diagnostic.viewed',
            AuditSeverity::Notice,
            $user->id,
            null,
            null,
            null,
            [
                'read_only' => true,
                'sections' => [
                    'application',
                    'schema',
                    'storage',
                    'failed_work',
                    'entity_totals',
                    'capabilities',
                ],
                'states' => [
                    'schema' => $data['schema']['state'],
                    'storage' => $data['storage']['state'],
                    'failed_work' => $data['failed_work']['state'],
                    'entity_totals' => $data['entity_totals']['state'],
                ],
            ],
        );

        return response()->json([
            'data' => $data,
            'meta' => [
                'timezone' => 'Asia/Manila',
                'read_only' => true,
                'platform' => true,
            ],
        ]);
    }
}
