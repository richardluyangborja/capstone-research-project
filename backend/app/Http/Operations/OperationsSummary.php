<?php

namespace App\Http\Operations;

use App\Enums\AuditSeverity;
use App\Enums\CommunicationChannel;
use App\Enums\CommunicationStatus;
use App\Enums\UserStatus;
use App\Http\Audit\AuditPresenter;
use App\Models\AuditLog;
use App\Models\Communication;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Contract;
use App\Models\Lead;
use App\Models\Opportunity;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * The operations summary (module 08 slice S6, spec 19 §Summary contract).
 *
 * Counts only, bounded: every number is one aggregate query (or a small fixed set of them)
 * and no table is loaded, no message body, recipient, failure reason or setting value is read
 * into the payload. The response is role-shaped — an application admin never receives the
 * `platform` block, and their audit counts and recent events exclude security-sensitive rows
 * through the same `AuditPresenter` boundary the audit book uses.
 *
 * Every optional subsystem degrades to an explicit state: a missing table yields a count of
 * `null` with the reason stated, never a `0` that would read as "nothing wrong".
 */
final class OperationsSummary
{
    /** At most this many recent audit rows are summarised (spec 19 §Summary contract). */
    private const RECENT_LIMIT = 10;

    public function __construct(private readonly OperationsHealthProbe $probe) {}

    /**
     * @return array<string, mixed>
     */
    public function build(User $user): array
    {
        $platform = $user->role->canOperatePlatform();
        $healthCounts = $this->healthCounts($user, $platform);

        return [
            'generated_at' => now()->toIso8601String(),
            'window' => [
                'activity_hours' => 24,
                'configuration_days' => 7,
                'timezone' => 'Asia/Manila',
            ],
            'accounts' => $this->accounts(),
            'administrative_activity' => $this->administrativeActivity($platform),
            'integrations' => $healthCounts,
            'failed_operations' => $this->failedOperations(),
            'maintenance' => $this->maintenance(),
            'configuration_changes' => $this->configurationChanges($platform),
            'platform' => $platform ? $this->platform() : null,
        ];
    }

    /**
     * The integration status counts, computed from the same checks the health endpoint returns
     * so the two views can never disagree.
     *
     * @return array<string, mixed>
     */
    private function healthCounts(User $user, bool $platform): array
    {
        $checks = $this->probe->checks($user, $platform);
        $counts = array_fill_keys(['healthy', 'degraded', 'unavailable', 'not_configured'], 0);

        foreach ($checks as $check) {
            $counts[$check['status']] = ($counts[$check['status']] ?? 0) + 1;
        }

        $attention = array_values(array_map(
            fn (array $check): array => [
                'key' => $check['key'],
                'label' => $check['label'],
                'status' => $check['status'],
                'source' => $check['source'],
            ],
            array_filter(
                $checks,
                fn (array $check): bool => in_array($check['status'], ['degraded', 'unavailable'], true),
            ),
        ));

        return [
            'counts' => $counts,
            'total' => count($checks),
            'attention' => $attention,
            'mocked' => count(array_filter($checks, fn (array $check): bool => $check['source'] === 'mocked')),
        ];
    }

    /**
     * @return array<string, int>
     */
    private function accounts(): array
    {
        $rows = User::query()
            ->select('status', DB::raw('count(*) as total'))
            ->groupBy('status')
            ->pluck('total', 'status');

        return [
            'active' => (int) ($rows[UserStatus::Active->value] ?? 0),
            'suspended' => (int) ($rows[UserStatus::Suspended->value] ?? 0),
        ];
    }

    /**
     * The last 24 hours of administrative activity: a total, severity counts and at most ten
     * recent summaries. Never a before/after snapshot, never a covered PII field. An
     * application admin's counts and rows exclude every security-sensitive action.
     *
     * @return array<string, mixed>
     */
    private function administrativeActivity(bool $platform): array
    {
        $since = now()->subDay();
        $base = $this->auditScope($platform)->where('created_at', '>=', $since);

        $severityRows = (clone $base)
            ->select('severity', DB::raw('count(*) as total'))
            ->groupBy('severity')
            ->pluck('total', 'severity');

        $severities = [];
        foreach (AuditSeverity::cases() as $severity) {
            $severities[$severity->value] = (int) ($severityRows[$severity->value] ?? 0);
        }

        $recent = (clone $base)
            ->with('actor:id,name')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(self::RECENT_LIMIT)
            ->get(['id', 'action', 'severity', 'actor_id', 'created_at'])
            ->map(fn (AuditLog $log): array => [
                'id' => $log->id,
                'action' => $log->action,
                'severity' => $log->severity->value,
                'actor' => $log->actor?->name,
                'created_at' => $log->created_at?->toIso8601String(),
            ])
            ->all();

        return [
            'total' => (clone $base)->count(),
            'severities' => $severities,
            'recent' => $recent,
        ];
    }

    /**
     * Failed outbound email records. A count only — no recipient, subject, body or failure
     * reason (spec 19 §Summary contract).
     *
     * @return array<string, mixed>
     */
    private function failedOperations(): array
    {
        try {
            $failed = Communication::query()
                ->where('status', CommunicationStatus::Failed)
                ->count();

            $last = Communication::query()
                ->where('status', CommunicationStatus::Failed)
                ->max('updated_at');

            return [
                'failed_emails' => $failed,
                'available' => true,
                'last_failed_at' => $last === null ? null : (string) $last,
            ];
        } catch (Throwable) {
            return ['failed_emails' => null, 'available' => false, 'last_failed_at' => null];
        }
    }

    /**
     * What maintenance a later slice could offer — S6 executes nothing and shows no action.
     *
     * @return array<string, mixed>
     */
    private function maintenance(): array
    {
        try {
            $retryable = Communication::query()
                ->where('status', CommunicationStatus::Failed)
                ->where('channel', CommunicationChannel::Email)
                ->count();

            return [
                'retryable_failed_emails' => $retryable,
                'configuration_refresh_available' => true,
                'actions_available' => false,
            ];
        } catch (Throwable) {
            return [
                'retryable_failed_emails' => null,
                'configuration_refresh_available' => true,
                'actions_available' => false,
            ];
        }
    }

    /**
     * Setting changes in the last seven days, counted without returning a single value.
     *
     * @return array<string, mixed>
     */
    private function configurationChanges(bool $platform): array
    {
        $count = $this->auditScope($platform)
            ->whereIn('action', ['setting.updated', 'setting.reset'])
            ->where('created_at', '>=', now()->subDays(7))
            ->count();

        return [
            'last_7_days' => $count,
            'window_days' => 7,
        ];
    }

    /**
     * Platform-only fields. The controller never attaches this for an admin, so the boundary
     * is the server's, not the page's.
     *
     * @return array<string, mixed>
     */
    private function platform(): array
    {
        return [
            'application' => [
                'build' => trim((string) config('app.version', '')) ?: 'unknown',
                'runtime' => 'PHP '.PHP_VERSION.' · Laravel '.app()->version(),
                'environment' => (string) app()->environment(),
            ],
            'schema' => $this->schemaState(),
            'queue' => [
                'driver' => (string) config('queue.default', 'unknown'),
                'failed_jobs' => $this->failedJobs(),
            ],
            'entity_totals' => $this->entityTotals(),
            'security_events' => $this->securityEvents(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function schemaState(): array
    {
        try {
            if (! Schema::hasTable('migrations')) {
                return ['state' => 'not_configured', 'pending' => null];
            }

            $ran = (int) DB::table('migrations')->count();
            $pending = max(count(glob(database_path('migrations/*.php')) ?: []) - $ran, 0);

            return ['state' => $pending === 0 ? 'current' : 'pending', 'pending' => $pending];
        } catch (Throwable) {
            return ['state' => 'unavailable', 'pending' => null];
        }
    }

    private function failedJobs(): ?int
    {
        try {
            return Schema::hasTable('failed_jobs') ? (int) DB::table('failed_jobs')->count() : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Allow-listed entity totals only — a fixed list of tables, never a caller-supplied name.
     *
     * @return array<string, int|null>
     */
    private function entityTotals(): array
    {
        $totals = [];

        foreach ([
            'leads' => Lead::class,
            'accounts' => Company::class,
            'contacts' => Contact::class,
            'opportunities' => Opportunity::class,
            'contracts' => Contract::class,
            'communications' => Communication::class,
            'users' => User::class,
        ] as $label => $model) {
            try {
                $totals[$label] = $model::query()->count();
            } catch (Throwable) {
                $totals[$label] = null;
            }
        }

        return $totals;
    }

    /**
     * @return array<string, int>
     */
    private function securityEvents(): array
    {
        $rows = AuditLog::query()
            ->where('created_at', '>=', now()->subDay())
            ->where('severity', '!=', AuditSeverity::Info->value)
            ->where(function (Builder $query): void {
                $query->where('action', 'like', 'security.%')
                    ->orWhereIn('action', AuditPresenter::SECURITY_ACTIONS);
            })
            ->select('severity', DB::raw('count(*) as total'))
            ->groupBy('severity')
            ->pluck('total', 'severity');

        return [
            'warning' => (int) ($rows[AuditSeverity::Warning->value] ?? 0),
            'critical' => (int) ($rows[AuditSeverity::Critical->value] ?? 0),
        ];
    }

    /**
     * The audit rows the caller may count: an application admin never sees a security event
     * (same rule as the audit book, spec 19 §Role capability matrix).
     */
    private function auditScope(bool $platform): Builder
    {
        $query = AuditLog::query();

        if (! $platform) {
            $query->where('action', 'not like', 'security.%')
                ->whereNotIn('action', AuditPresenter::SECURITY_ACTIONS);
        }

        return $query;
    }
}
