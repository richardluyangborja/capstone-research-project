<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\AuditSeverity;
use App\Http\Audit\AuditPresenter;
use App\Http\Controllers\Controller;
use App\Http\Resources\AuditLogResource;
use App\Models\AuditLog;
use App\Models\Setting;
use App\Models\Team;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The immutable audit book (module 08 slice S5, spec 19, ADR-0030).
 *
 * Admin+ reads and exports; an application admin never sees a security-sensitive event.
 * Filters are bounded (per-page max 100, export max 5,000 rows and a 366-day window), the
 * export is itself audited (`audit.exported`), and every response is redacted again by
 * `AuditPresenter` — the writer already stripped secrets, this layer masks contact PII.
 * There is no update or delete route: the model is append-only.
 */
class AdminAuditLogController extends Controller
{
    private const EXPORT_LIMIT = 5000;

    private const EXPORT_MAX_DAYS = 366;

    private const EXPORT_DEFAULT_DAYS = 90;

    /** The resource filter's allow-list, keyed by the short label the UI sends. */
    private const RESOURCE_TYPES = [
        'User' => User::class,
        'Team' => Team::class,
        'Setting' => Setting::class,
        'AuditLog' => AuditLog::class,
    ];

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorizeClassOrFail($request, 'viewAny', AuditLog::class);

        $this->validateFilters($request);

        $from = $request->filled('from')
            ? Carbon::parse((string) $request->input('from'))->startOfDay()
            : null;
        $to = $request->filled('to')
            ? Carbon::parse((string) $request->input('to'))->endOfDay()
            : null;

        $logs = $this->filtered($request, $from, $to)
            ->with(['actor:id,name,email,public_id', 'subject'])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(min(max((int) $request->input('per_page', 25), 1), 100));

        return AuditLogResource::collection($logs);
    }

    public function show(Request $request, AuditLog $auditLog): AuditLogResource
    {
        $this->authorizeOrFail($request, 'view', $auditLog);

        $auditLog->load(['actor:id,name,email,public_id', 'subject']);

        return new AuditLogResource($auditLog);
    }

    public function export(Request $request): StreamedResponse
    {
        $this->authorizeClassOrFail($request, 'export', AuditLog::class);

        [$from, $to] = $this->exportWindow($request);

        $rows = $this->filtered($request, $from, $to)
            ->with(['actor:id,name,email,public_id', 'subject'])
            ->orderBy('created_at')
            ->orderBy('id')
            ->limit(self::EXPORT_LIMIT + 1)
            ->get();

        if ($rows->count() > self::EXPORT_LIMIT) {
            throw ValidationException::withMessages([
                'export' => [sprintf(
                    'The export is limited to %d rows. Narrow the filters and try again.',
                    self::EXPORT_LIMIT,
                )],
            ]);
        }

        AuditLog::record('audit.exported', AuditSeverity::Notice, $request->user()->id, AuditLog::class, null, null, [
            'filters' => $this->filterSummary($request),
            'rows' => $rows->count(),
        ]);

        return response()->streamDownload(function () use ($rows): void {
            $handle = fopen('php://output', 'w');

            if ($handle === false) {
                return;
            }

            fputcsv($handle, AuditPresenter::csvColumns(), ',', '"', '');

            foreach ($rows as $row) {
                fputcsv($handle, AuditPresenter::csvRow($row), ',', '"', '');
            }

            fclose($handle);
        }, 'audit-log-'.now()->format('Ymd-His').'.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    private function filtered(Request $request, ?Carbon $from = null, ?Carbon $to = null): Builder
    {
        $query = AuditLog::query();

        if (! $request->user()->role->canOperatePlatform()) {
            $query->where('action', 'not like', 'security.%')
                ->whereNotIn('action', AuditPresenter::SECURITY_ACTIONS);
        }

        return $query
            ->when($request->filled('action'), fn (Builder $q) => $q->where('action', (string) $request->input('action')))
            ->when($request->filled('severity'), function (Builder $q) use ($request): void {
                $severity = AuditSeverity::tryFrom((string) $request->input('severity'));

                if ($severity !== null) {
                    $q->where('severity', $severity->value);
                }
            })
            ->when($request->filled('resource'), function (Builder $q) use ($request): void {
                $type = self::RESOURCE_TYPES[(string) $request->input('resource')] ?? null;

                if ($type !== null) {
                    $q->where('subject_type', $type);
                }
            })
            ->when($request->filled('actor'), function (Builder $q) use ($request): void {
                $term = (string) $request->input('actor');
                $q->whereHas('actor', function (Builder $actor) use ($term): void {
                    $actor->where('public_id', $term)
                        ->orWhere('name', 'like', '%'.$term.'%')
                        ->orWhere('email', 'like', '%'.$term.'%');
                });
            })
            ->when($request->filled('request_id'), fn (Builder $q) => $q->where('request_id', (string) $request->input('request_id')))
            ->when($from !== null, fn (Builder $q) => $q->where('created_at', '>=', $from))
            ->when($to !== null, fn (Builder $q) => $q->where('created_at', '<=', $to));
    }

    private function validateFilters(Request $request): void
    {
        $request->validate([
            'actor' => ['nullable', 'string', 'max:120'],
            'action' => ['nullable', 'string', 'max:64'],
            'resource' => ['nullable', 'string', Rule::in(array_keys(self::RESOURCE_TYPES))],
            'severity' => ['nullable', Rule::enum(AuditSeverity::class)],
            'request_id' => ['nullable', 'string', 'max:64'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'per_page' => ['nullable', 'integer', 'min:1'],
        ]);
    }

    /**
     * The export's date window: the caller's range, or the last 90 days when none is given.
     * A window wider than 366 days is refused before the query runs.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    private function exportWindow(Request $request): array
    {
        $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ]);

        $from = $request->filled('from')
            ? Carbon::parse((string) $request->input('from'))->startOfDay()
            : now()->subDays(self::EXPORT_DEFAULT_DAYS)->startOfDay();

        $to = $request->filled('to')
            ? Carbon::parse((string) $request->input('to'))->endOfDay()
            : now()->endOfDay();

        if ($from->diffInDays($to) > self::EXPORT_MAX_DAYS) {
            throw ValidationException::withMessages([
                'from' => [sprintf('The export window is limited to %d days.', self::EXPORT_MAX_DAYS)],
            ]);
        }

        return [$from, $to];
    }

    /**
     * @return array<string, mixed>
     */
    private function filterSummary(Request $request): array
    {
        return array_filter([
            'actor' => $request->input('actor'),
            'action' => $request->input('action'),
            'resource' => $request->input('resource'),
            'severity' => $request->input('severity'),
            'request_id' => $request->input('request_id'),
            'from' => $request->input('from'),
            'to' => $request->input('to'),
        ], fn (mixed $value): bool => $value !== null && $value !== '');
    }

    private function authorizeOrFail(Request $request, string $ability, AuditLog $log): void
    {
        try {
            Gate::authorize($ability, $log);
        } catch (AuthorizationException $exception) {
            AuditLog::record('security.access_denied', AuditSeverity::Warning, $request->user()?->id, AuditLog::class, null, null, [
                'ability' => $ability,
                'audit_log_id' => $log->id,
            ]);

            throw $exception;
        }
    }

    /**
     * A denied read or export is itself a security event; record it before rethrowing the 403.
     */
    private function authorizeClassOrFail(Request $request, string $ability, string $model): void
    {
        try {
            Gate::authorize($ability, $model);
        } catch (AuthorizationException $exception) {
            AuditLog::record('security.access_denied', AuditSeverity::Warning, $request->user()?->id, $model, null, null, [
                'ability' => $ability,
            ]);

            throw $exception;
        }
    }
}
