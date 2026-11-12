<?php

namespace App\Http\Diagnostics;

use App\Enums\ActivityType;
use App\Integrations\EmailGateway;
use App\Models\Activity;
use App\Models\Attachment;
use App\Models\AuditLog;
use App\Models\Communication;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Contract;
use App\Models\Lead;
use App\Models\Opportunity;
use App\Models\Survey;
use App\Models\SurveyInvitation;
use App\Models\SurveyResponse;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Bounded, read-only platform diagnostics (module 08 slice S8, spec 19 §S8).
 *
 * This service is deliberately one-way: it only reads fixed queries over an allow-list of
 * tables and environment capabilities. It never accepts a table name from a caller, never
 * writes, never runs a command or migration, and never returns a secret, token, hash, file
 * path, message body, payload, exception or arbitrary PII.
 *
 * Every section reports its own state. A missing table, a refused connection or a query that
 * exceeds the statement timeout becomes `unavailable`/`timeout` on that section — never a
 * misleading zero. On PostgreSQL each fixed read runs inside a transaction with a statement
 * timeout; other drivers run the same bounded query without a server-side timeout.
 */
final class PlatformDiagnostics
{
    /** A fixed read may take at most 1.5 seconds. */
    private const QUERY_TIMEOUT_MS = 1500;

    /** At most this many recent failed jobs are listed; their payloads are never read. */
    private const RECENT_FAILED_JOBS = 5;

    public function __construct(private readonly EmailGateway $email) {}

    /**
     * @return array<string, mixed>
     */
    public function build(): array
    {
        return [
            'generated_at' => now()->toIso8601String(),
            'read_only' => true,
            'application' => $this->application(),
            'schema' => $this->schema(),
            'storage' => $this->storage(),
            'failed_work' => $this->failedWork(),
            'entity_totals' => $this->entityTotals(),
            'capabilities' => $this->capabilities(),
        ];
    }

    // --- Runtime and capabilities -------------------------------------------

    /**
     * Safe runtime labels only: no environment dump, path, credential or provider URL.
     *
     * @return array<string, mixed>
     */
    private function application(): array
    {
        return [
            'state' => 'available',
            'build' => trim((string) config('app.version', '')) ?: 'unknown',
            'runtime' => 'PHP '.PHP_VERSION.' · Laravel '.app()->version(),
            'environment' => (string) app()->environment(),
            'timezone' => (string) config('app.timezone', 'UTC'),
            'debug' => (bool) config('app.debug', false),
            'maintenance_mode' => app()->isDownForMaintenance(),
        ];
    }

    /**
     * Environment capability status. Values are configuration labels or booleans; secrets
     * (mail credentials, AI provider key, JWT key) never leave their environment source.
     *
     * @return array<int, array<string, mixed>>
     */
    private function capabilities(): array
    {
        $mail = $this->email->source();
        $mailMocked = str_starts_with($this->email::class, 'App\\Integrations\\Mock')
            || str_contains(strtolower($mail), 'mock');

        $queue = trim((string) config('queue.default', ''));
        $aiEnabled = (bool) config('ai.report.enabled', false);
        $aiConfigured = trim((string) config('ai.report.service_url', '')) !== ''
            && (string) config('ai.report.service_token', '') !== '';

        return [
            $this->capability(
                'database',
                'Database',
                'available',
                (string) DB::connection()->getDriverName(),
                'The application database driver is configured.',
            ),
            $this->capability(
                'cache',
                'Cache',
                'available',
                (string) config('cache.default', 'unknown'),
                'The application cache store is configured.',
            ),
            $this->capability(
                'queue',
                'Queue',
                $queue === '' ? 'not_configured' : 'available',
                $queue === '' ? 'none' : $queue,
                $queue === ''
                    ? 'No queue connection is configured; queued work runs synchronously.'
                    : 'The application queue connection is configured.',
            ),
            $this->capability(
                'session',
                'Session',
                'available',
                (string) config('session.driver', 'unknown'),
                'The session driver is configured.',
            ),
            $this->capability(
                'email',
                'Email',
                $mailMocked ? 'degraded' : 'available',
                $mail,
                $mailMocked
                    ? 'Email is served by the mocked gateway; nothing leaves the machine.'
                    : 'Email is served by the configured gateway.',
            ),
            $this->capability(
                'storage',
                'Attachment storage',
                $this->storageWritable() ? 'available' : 'unavailable',
                (string) config('crm.attachments.disk', 'local'),
                $this->storageWritable()
                    ? 'The private attachment disk is present and writable.'
                    : 'The private attachment disk is not available for writes.',
            ),
            $this->capability(
                'notifications',
                'Database notifications',
                (bool) config('notifications.database_enabled', true) ? 'available' : 'degraded',
                (bool) config('notifications.database_enabled', true) ? 'enabled' : 'disabled',
                (bool) config('notifications.database_enabled', true)
                    ? 'In-app notifications are enabled.'
                    : 'In-app notifications are switched off; assignment and due reminders are not written.',
            ),
            $this->capability(
                'ai_reports',
                'AI reports',
                $aiEnabled && $aiConfigured ? 'available' : 'not_configured',
                $aiEnabled ? 'enabled' : 'disabled',
                ! $aiEnabled
                    ? 'AI report generation is switched off; the rules-based fallback is used.'
                    : ($aiConfigured
                        ? 'AI report generation has a service URL and a shared token.'
                        : 'AI report generation is enabled but the service URL or shared token is missing.'),
            ),
            $this->capability(
                'debug',
                'Debug mode',
                (bool) config('app.debug', false) ? 'degraded' : 'available',
                (bool) config('app.debug', false) ? 'on' : 'off',
                (bool) config('app.debug', false)
                    ? 'Debug mode is on; production should keep it off.'
                    : 'Debug mode is off.',
            ),
            $this->capability(
                'maintenance',
                'Maintenance mode',
                app()->isDownForMaintenance() ? 'degraded' : 'available',
                app()->isDownForMaintenance() ? 'on' : 'off',
                app()->isDownForMaintenance()
                    ? 'The application is currently in maintenance mode.'
                    : 'The application is accepting requests.',
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function capability(
        string $key,
        string $label,
        string $state,
        string $value,
        string $summary,
    ): array {
        return [
            'key' => $key,
            'label' => $label,
            'state' => $state,
            'value' => $value,
            'summary' => $summary,
        ];
    }

    private function storageWritable(): bool
    {
        try {
            $disk = (string) config('crm.attachments.disk', 'local');
            $root = Storage::disk($disk)->path('');

            return File::isDirectory($root) && File::isWritable($root);
        } catch (Throwable) {
            return false;
        }
    }

    // --- Schema -------------------------------------------------------------

    /**
     * The migration book only: applied/pending counts, the latest migration name and its
     * batch. No table list, column dump, DDL or path is returned.
     *
     * @return array<string, mixed>
     */
    private function schema(): array
    {
        $result = $this->run(function (): array {
            if (! Schema::hasTable('migrations')) {
                return [
                    'state' => 'not_configured',
                    'applied' => null,
                    'pending' => null,
                    'latest' => null,
                    'batch' => null,
                ];
            }

            $applied = (int) DB::table('migrations')->count();
            $onDisk = count(File::glob(database_path('migrations/*.php')) ?: []);
            $pending = max($onDisk - $applied, 0);
            $latest = DB::table('migrations')
                ->orderByDesc('batch')
                ->orderByDesc('migration')
                ->first(['migration', 'batch']);

            return [
                'state' => $pending === 0 ? 'available' : 'degraded',
                'applied' => $applied,
                'pending' => $pending,
                'latest' => $latest?->migration,
                'batch' => $latest?->batch === null ? null : (int) $latest->batch,
            ];
        });

        if ($result['state'] !== 'available' || ! is_array($result['value'])) {
            return $this->unavailableSection($result['state'], [
                'applied' => null,
                'pending' => null,
                'latest' => null,
                'batch' => null,
            ]);
        }

        return $result['value'];
    }

    // --- Storage ------------------------------------------------------------

    /**
     * Attachment usage summary without a file listing, path or contents. The disk name is a
     * configuration label; the root is inspected only for writability.
     *
     * @return array<string, mixed>
     */
    private function storage(): array
    {
        $disk = (string) config('crm.attachments.disk', 'local');
        $writable = $this->storageWritable();
        $usage = $this->run(function (): array {
            if (! Schema::hasTable('attachments')) {
                // The run() helper turns this into an explicit unavailable state; a missing
                // table must never be reported as zero attachments.
                throw new \RuntimeException('The attachments table is not configured.');
            }

            $row = DB::table('attachments')
                ->selectRaw('count(*) as files, coalesce(sum(size), 0) as bytes')
                ->first();

            return [
                'count' => (int) ($row?->files ?? 0),
                'bytes' => (int) ($row?->bytes ?? 0),
            ];
        });

        $usageState = $usage['state'];
        $state = ! $writable
            ? 'unavailable'
            : ($usageState === 'available' ? 'available' : $usageState);

        return [
            'state' => $state,
            'disk' => $disk,
            'writable' => $writable,
            'attachments' => [
                'state' => $usageState,
                'count' => is_array($usage['value']) ? $usage['value']['count'] : null,
                'bytes' => is_array($usage['value']) ? $usage['value']['bytes'] : null,
            ],
            'free_bytes' => $this->diskSpace('free'),
            'total_bytes' => $this->diskSpace('total'),
        ];
    }

    private function diskSpace(string $kind): ?int
    {
        try {
            $root = Storage::disk((string) config('crm.attachments.disk', 'local'))->path('');
            $value = $kind === 'free' ? @disk_free_space($root) : @disk_total_space($root);

            return $value === false ? null : (int) $value;
        } catch (Throwable) {
            return null;
        }
    }

    // --- Failed work --------------------------------------------------------

    /**
     * Recent failed queue jobs, if the failed-job table exists. Only the job identity,
     * connection, queue and failure instant are read — never `payload` or `exception`, which
     * can contain record data and stack traces. There is no failed-request store in this
     * application, and it is reported as such rather than silently omitted.
     *
     * @return array<string, mixed>
     */
    private function failedWork(): array
    {
        $table = (string) config('queue.failed.table', 'failed_jobs');
        $driver = (string) config('queue.default', 'unknown');

        $queue = $this->run(function () use ($table): array {
            if (! Schema::hasTable($table)) {
                return [
                    'state' => 'not_configured',
                    'count' => null,
                    'last_failed_at' => null,
                    'recent' => [],
                ];
            }

            $count = (int) DB::table($table)->count();
            $last = DB::table($table)->max('failed_at');
            $recent = DB::table($table)
                ->orderByDesc('failed_at')
                ->limit(self::RECENT_FAILED_JOBS)
                ->get(['uuid', 'connection', 'queue', 'failed_at'])
                ->map(fn (object $row): array => [
                    'uuid' => (string) $row->uuid,
                    'connection' => $row->connection,
                    'queue' => $row->queue,
                    'failed_at' => $row->failed_at,
                ])
                ->all();

            return [
                'state' => 'available',
                'count' => $count,
                'last_failed_at' => $last === null ? null : (string) $last,
                'recent' => $recent,
            ];
        });

        $queueSection = $queue['state'] === 'available' && is_array($queue['value'])
            ? $queue['value']
            : $this->unavailableSection($queue['state'], [
                'count' => null,
                'last_failed_at' => null,
                'recent' => [],
            ]);

        return [
            'state' => $queueSection['state'],
            'queue' => [
                'driver' => $driver,
                'state' => $queueSection['state'],
                'count' => $queueSection['count'],
                'last_failed_at' => $queueSection['last_failed_at'],
                'recent' => $queueSection['recent'],
            ],
            'requests' => [
                'state' => 'not_configured',
                'summary' => 'No failed-request store is configured; only queued work is inspectable.',
            ],
        ];
    }

    // --- Allow-listed entity totals ----------------------------------------

    /**
     * Fixed entity counts. The list is server-owned — no caller-supplied table or column is
     * ever interpolated into a query. A missing table leaves its count `null` and marks the
     * section partial/unavailable rather than reporting a false zero.
     *
     * @return array<string, mixed>
     */
    private function entityTotals(): array
    {
        $definitions = [
            'leads' => ['Leads', fn (): int => Lead::query()->count()],
            'accounts' => ['Accounts', fn (): int => Company::query()->count()],
            'contacts' => ['Contacts', fn (): int => Contact::query()->count()],
            'opportunities' => ['Opportunities', fn (): int => Opportunity::query()->count()],
            'contracts' => ['Contracts', fn (): int => Contract::query()->count()],
            'communications' => ['Communications', fn (): int => Communication::query()->count()],
            'activities' => ['Activities', fn (): int => Activity::query()->count()],
            'follow_up_tasks' => [
                'Follow-up tasks',
                fn (): int => Activity::query()->where('type', ActivityType::Task->value)->count(),
            ],
            'attachments' => ['Attachments', fn (): int => Attachment::query()->count()],
            'surveys' => ['Surveys', fn (): int => Survey::query()->count()],
            'survey_invitations' => ['Survey invitations', fn (): int => SurveyInvitation::query()->count()],
            'survey_responses' => ['Survey responses', fn (): int => SurveyResponse::query()->count()],
            'teams' => ['Teams', fn (): int => Team::query()->count()],
            'users' => ['Users', fn (): int => User::query()->count()],
            'audit_logs' => ['Audit rows', fn (): int => AuditLog::query()->count()],
        ];

        $counts = [];
        $states = [];

        foreach ($definitions as $key => [$label, $query]) {
            $result = $this->run($query);
            $state = $result['state'];

            $counts[$key] = [
                'label' => $label,
                'count' => $state === 'available' ? (int) $result['value'] : null,
                'state' => $state,
            ];
            $states[] = $state;
        }

        return [
            'state' => $this->rollUpState($states),
            'counts' => $counts,
        ];
    }

    // --- Bounded queries ----------------------------------------------------

    /**
     * Run one fixed read. On PostgreSQL the transaction sets a local statement timeout, so a
     * pathological query is cancelled by the server; other drivers still receive a fixed,
     * non-interpolated query and the same explicit failure states.
     *
     * @param  callable(): mixed  $query
     * @return array{state: string, value: mixed}
     */
    private function run(callable $query): array
    {
        try {
            $driver = DB::connection()->getDriverName();

            $value = $driver === 'pgsql'
                ? DB::connection()->transaction(function () use ($query): mixed {
                    DB::connection()->statement(
                        'SET LOCAL statement_timeout = '.self::QUERY_TIMEOUT_MS,
                    );

                    return $query();
                })
                : $query();

            return ['state' => 'available', 'value' => $value];
        } catch (QueryException $exception) {
            return [
                'state' => $this->timedOut($exception) ? 'timeout' : 'unavailable',
                'value' => null,
            ];
        } catch (Throwable) {
            return ['state' => 'unavailable', 'value' => null];
        }
    }

    private function timedOut(QueryException $exception): bool
    {
        $sqlState = $exception->errorInfo[0] ?? null;
        $message = strtolower($exception->getMessage());

        return $sqlState === '57014'
            || str_contains($message, 'statement timeout')
            || str_contains($message, 'timed out')
            || str_contains($message, 'timeout');
    }

    /**
     * @param  array<int, string>  $states
     */
    private function rollUpState(array $states): string
    {
        if ($states === []) {
            return 'not_configured';
        }

        if (in_array('timeout', $states, true)) {
            return 'timeout';
        }

        $available = count(array_filter($states, fn (string $state): bool => $state === 'available'));

        if ($available === count($states)) {
            return 'available';
        }

        return $available === 0 ? 'unavailable' : 'partial';
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function unavailableSection(string $state, array $extra): array
    {
        return ['state' => $state] + $extra;
    }
}
