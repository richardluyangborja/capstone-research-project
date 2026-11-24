<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\AuditSeverity;
use App\Events\EmployeeDiscrepancyRaised;
use App\Http\Controllers\Controller;
use App\Http\Requests\ResolveEmployeeDiscrepancyRequest;
use App\Http\Requests\StoreEmployeeDiscrepancyRequest;
use App\Http\Resources\EmployeeDiscrepancyResource;
use App\Models\AuditLog;
use App\Models\EmployeeDiscrepancy;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Discrepancy escalation (module 09 slice T4, spec 21 §T4, ADR-0033).
 *
 * A rep raises a flag on their own record; their manager and the admins are notified; a manager or
 * admin settles it. Nothing here writes Core 2 — the flag is the CRM's escalation trail, and the
 * row is history that is never deleted.
 */
class EmployeeDiscrepancyController extends Controller
{
    /**
     * The employee's flags, newest first. A resolved row stays visible: it is the record of what
     * was disputed and what was done about it.
     */
    public function index(Request $request, User $employee): JsonResponse
    {
        Gate::authorize('viewEmployeeDiscrepancies', $employee);

        $discrepancies = $employee->discrepancies()
            ->with(['raisedBy:id,public_id,name', 'resolvedBy:id,public_id,name', 'subjectable'])
            ->latest()
            ->get();

        return response()->json([
            'data' => $discrepancies
                ->map(fn (EmployeeDiscrepancy $discrepancy): array => (new EmployeeDiscrepancyResource($discrepancy))->resolve($request))
                ->values(),
            'meta' => [
                'total' => $discrepancies->count(),
                'open' => $discrepancies->filter(fn (EmployeeDiscrepancy $row): bool => ! $row->isResolved())->count(),
                'can_raise' => $request->user()->can('raiseEmployeeDiscrepancy', $employee),
                'can_resolve' => $request->user()->can('resolveEmployeeDiscrepancy', $employee),
            ],
        ]);
    }

    public function store(StoreEmployeeDiscrepancyRequest $request, User $employee): JsonResponse
    {
        Gate::authorize('raiseEmployeeDiscrepancy', $employee);

        $validated = $request->validated();

        $discrepancy = $employee->discrepancies()->create([
            'raised_by' => $request->user()->id,
            'category' => $validated['category'],
            'explanation' => $validated['explanation'],
            'subject_type' => $request->subjectClass(),
            'subject_id' => $request->subjectClass() === null ? null : $validated['subject_id'],
        ])->load(['raisedBy:id,public_id,name', 'subjectable']);

        AuditLog::record(
            'employee.discrepancy_raised',
            AuditSeverity::Notice,
            $request->user()->id,
            User::class,
            $employee->id,
            null,
            ['id' => $discrepancy->id, 'category' => $discrepancy->category->value],
        );

        EmployeeDiscrepancyRaised::dispatch($discrepancy);

        return response()->json([
            'data' => (new EmployeeDiscrepancyResource($discrepancy))->resolve($request),
        ], 201);
    }

    /**
     * Settle the flag. A second resolve is a 422 state error, not a permission error — the same
     * split the survey call-back uses (module 05 slice S4).
     */
    public function resolve(
        ResolveEmployeeDiscrepancyRequest $request,
        User $employee,
        EmployeeDiscrepancy $discrepancy,
    ): JsonResponse {
        Gate::authorize('resolveEmployeeDiscrepancy', $employee);

        abort_unless($discrepancy->employee_id === $employee->id, 404);

        if ($discrepancy->isResolved()) {
            throw ValidationException::withMessages([
                'discrepancy' => ['This discrepancy has already been resolved.'],
            ]);
        }

        $discrepancy->markResolved($request->user(), $request->validated('note'));
        $discrepancy->load(['raisedBy:id,public_id,name', 'resolvedBy:id,public_id,name', 'subjectable']);

        AuditLog::record(
            'employee.discrepancy_resolved',
            AuditSeverity::Info,
            $request->user()->id,
            User::class,
            $employee->id,
            ['id' => $discrepancy->id, 'status' => 'open'],
            ['id' => $discrepancy->id, 'status' => $discrepancy->status->value],
        );

        return response()->json([
            'data' => (new EmployeeDiscrepancyResource($discrepancy))->resolve($request),
        ]);
    }
}
