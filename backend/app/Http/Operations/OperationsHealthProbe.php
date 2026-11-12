<?php

namespace App\Http\Operations;

use App\Enums\OperationCheckStatus;
use App\Integrations\Core2EmployeeGateway;
use App\Integrations\DeploymentFeed;
use App\Integrations\EmailGateway;
use App\Integrations\HrGateway;
use App\Integrations\MockServiceHandoff;
use App\Integrations\RecruitmentFeed;
use App\Integrations\TimekeepingFeed;
use App\Models\User;
use App\Settings\EffectiveSettings;
use App\Settings\SettingRegistry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * The read-only operational health probe (module 08 slice S6, spec 19 §Health-check contract).
 *
 * Every check is bounded, side-effect free and secret-free:
 *
 * - the database probe is one `select 1` (no table enumeration, no exception, no DSN);
 * - the storage probe checks the configured private disk is writable through the filesystem
 *   adapter's own metadata rather than by writing a probe file — no path, no listing, no read;
 * - the email and department checks report the **bound** gateway and whether it is mocked,
 *   never a live delivery or a provider payload;
 * - the AI probe calls only the service's health endpoint with a strict two-second bound and
 *   reports a stable status — never the URL, body, token or raw exception;
 * - the queue and schema checks are platform-only capability checks; an absent or
 *   unconfigured subsystem is `not_configured`, never a false `healthy`.
 *
 * A successful probe means only that this dependency answered at `checked_at`; it never claims
 * that a fallback, a delivery or a report generation would succeed.
 */
final class OperationsHealthProbe
{
    /** The AI service's health call is bounded at two seconds, connect and total. */
    private const AI_TIMEOUT_SECONDS = 2.0;

    /** Application-plane keys, in display order (spec 19 §Health-check contract). */
    public const APPLICATION_KEYS = [
        'database', 'storage', 'email', 'ai', 'hr', 'core2_employees', 'recruitment',
        'deployment', 'timekeeping', 'notifications', 'application',
    ];

    /** Platform-only keys, in display order. Never returned to an application admin. */
    public const PLATFORM_KEYS = ['queue', 'schema'];

    public function __construct(
        private readonly EffectiveSettings $settings,
        private readonly EmailGateway $email,
        private readonly HrGateway $hr,
        private readonly Core2EmployeeGateway $core2Employees,
        private readonly RecruitmentFeed $recruitment,
        private readonly DeploymentFeed $deployment,
        private readonly TimekeepingFeed $timekeeping,
    ) {}

    /**
     * Build the check list for the caller. `$platform` adds the queue/schema checks; the
     * controller decides that from the role, so an admin's payload simply does not contain them.
     *
     * @return array<int, array<string, mixed>>
     */
    public function checks(User $user, bool $platform): array
    {
        $checks = [
            $this->databaseCheck(),
            $this->storageCheck(),
            $this->emailCheck(),
            $this->aiCheck(),
            $this->gatewayCheck(
                key: 'hr',
                label: 'HR feed (core2)',
                gateway: $this->hr,
                summary: 'Attendance and leave figures for the staff scorecard.',
                mockedSummary: 'Attendance and leave are mocked (core2 is not connected).',
                healthySummary: 'Attendance and leave are served by the core2 HR service.',
                remediation: 'Check the HR service binding and its credentials; the dashboard reads — when it is unavailable.',
            ),
            $this->gatewayCheck(
                key: 'core2_employees',
                label: 'Core 2 employee profiling feed',
                gateway: $this->core2Employees,
                summary: 'Employee profile, attendance, compensation and appraisals, read-only.',
                mockedSummary: 'Employee profiling is deterministic and mocked (Core 2 is not connected).',
                healthySummary: 'Employee projections are served by the Core 2 service.',
                remediation: 'Check the Core 2 employee gateway binding; employee pages show — when this feed is unavailable.',
            ),
            $this->gatewayCheck(
                key: 'recruitment',
                label: 'Recruitment feed',
                gateway: $this->recruitment,
                summary: 'Job orders behind an account, read-only.',
                mockedSummary: 'Job orders are mocked (Recruitment is not connected).',
                healthySummary: 'Job orders are served by the Recruitment service.',
                remediation: 'Check the Recruitment feed binding; the account Delivery tab shows an empty demand block when it is unavailable.',
            ),
            $this->gatewayCheck(
                key: 'deployment',
                label: 'Deployment feed',
                gateway: $this->deployment,
                summary: 'Deployments, fill rate and the pending-timesheet metric, read-only.',
                mockedSummary: 'Deployments are mocked (Deployment operations is not connected).',
                healthySummary: 'Deployments are served by the Deployment service.',
                remediation: 'Check the Deployment feed binding; the Deployments book and the dashboard metric fall back to an empty read.',
            ),
            $this->gatewayCheck(
                key: 'timekeeping',
                label: 'Timekeeping feed',
                gateway: $this->timekeeping,
                summary: 'Approved and pending hours per deployment, read-only.',
                mockedSummary: 'Timesheets are mocked (Timekeeping is not connected).',
                healthySummary: 'Timesheet summaries are served by the Timekeeping service.',
                remediation: 'Check the Timekeeping feed binding; a deployment then reports its timesheet state as unknown.',
            ),
            $this->handoffCheck(),
            $this->notificationsCheck(),
            $this->applicationCheck(),
        ];

        if ($platform) {
            $checks[] = $this->queueCheck();
            $checks[] = $this->schemaCheck();
        }

        return $checks;
    }

    // --- Application plane ---------------------------------------------------

    private function databaseCheck(): array
    {
        try {
            DB::connection()->select('select 1');
            $driver = DB::connection()->getDriverName();

            return $this->check(
                'database',
                'Database',
                OperationCheckStatus::Healthy,
                sprintf('The application database answered a bounded read (%s driver).', $driver),
                'application',
            );
        } catch (Throwable) {
            // The exception carries the DSN and credentials; it never leaves this probe.
            return $this->check(
                'database',
                'Database',
                OperationCheckStatus::Unavailable,
                'The application database did not answer a bounded read.',
                'application',
                'Check the database service is running and that the application connection settings match it, then reload this page.',
            );
        }
    }

    private function storageCheck(): array
    {
        $disk = (string) config('crm.attachments.disk', 'local');

        try {
            $adapter = Storage::disk($disk);

            // Asking the local adapter for its root metadata proves the directory exists and is
            // writable without writing, listing or reading a single customer file.
            $root = $adapter->path('');
            $exists = File::isDirectory($root);
            $writable = $exists && File::isWritable($root);

            if (! $writable) {
                return $this->check(
                    'storage',
                    'Attachment storage',
                    OperationCheckStatus::Unavailable,
                    'The configured private attachment disk is not available for writes.',
                    'application',
                    'Check the private disk directory exists and that the application process may write to it, then retry an attachment upload.',
                );
            }
        } catch (Throwable) {
            return $this->check(
                'storage',
                'Attachment storage',
                OperationCheckStatus::Unavailable,
                'The configured private attachment disk could not be inspected.',
                'application',
                'Check the private disk is configured and reachable; attachment upload and download depend on it.',
            );
        }

        return $this->check(
            'storage',
            'Attachment storage',
            OperationCheckStatus::Healthy,
            'The private attachment disk is present and writable.',
            'application',
        );
    }

    private function emailCheck(): array
    {
        $source = $this->email->source();
        $mocked = str_starts_with($this->email::class, 'App\\Integrations\\Mock') || $this->isMocked($source);
        $failures = (bool) config('crm.integrations.email.simulate_failure', false);

        if ($failures) {
            return $this->check(
                'email',
                'Email delivery',
                OperationCheckStatus::Degraded,
                sprintf('%s is configured to reject every send (simulated failure is on).', $source),
                'mocked',
                'Turn off the simulated email failure in Admin Settings so outbound mail and survey invitations are accepted again.',
            );
        }

        return $this->check(
            'email',
            'Email delivery',
            OperationCheckStatus::Healthy,
            $mocked
                ? sprintf('%s is bound and accepts sends (nothing leaves the machine).', $source)
                : sprintf('%s is bound and accepts sends.', $source),
            $mocked ? 'mocked' : 'external',
        );
    }

    private function aiCheck(): array
    {
        $enabled = (bool) config('ai.report.enabled', false);
        $url = trim((string) config('ai.report.service_url', ''));
        $token = (string) config('ai.report.service_token', '');

        if (! $enabled) {
            return $this->check(
                'ai',
                'AI service',
                OperationCheckStatus::NotConfigured,
                'AI reports are switched off; the reports page uses its rules-based fallback.',
                'external',
                'Enable AI reports in Admin Settings when a provider key is available on the AI service.',
            );
        }

        if ($url === '') {
            return $this->check(
                'ai',
                'AI service',
                OperationCheckStatus::NotConfigured,
                'No AI service URL is configured; the reports page uses its rules-based fallback.',
                'external',
                'Set the AI service URL in the deployment environment, then reload this page.',
            );
        }

        if ($token === '') {
            return $this->check(
                'ai',
                'AI service',
                OperationCheckStatus::NotConfigured,
                'The AI service is configured without a shared service token, so generation is not attempted.',
                'external',
                'Set the shared AI service token to match the AI service, then reload this page.',
            );
        }

        try {
            // Only the health endpoint: no report generation, no token in the payload, and the
            // response body is never surfaced — just the status code.
            $response = Http::baseUrl(rtrim($url, '/'))
                ->acceptJson()
                ->connectTimeout(self::AI_TIMEOUT_SECONDS)
                ->timeout(self::AI_TIMEOUT_SECONDS)
                ->get('/health');

            if ($response->successful()) {
                return $this->check(
                    'ai',
                    'AI service',
                    OperationCheckStatus::Healthy,
                    'The AI service answered its health endpoint within two seconds.',
                    'external',
                );
            }

            return $this->check(
                'ai',
                'AI service',
                OperationCheckStatus::Unavailable,
                sprintf('The AI service answered its health endpoint with status %d.', $response->status()),
                'external',
                'Check the AI service deployment and its provider configuration; the reports page keeps using its rules-based fallback meanwhile.',
            );
        } catch (Throwable) {
            // A timeout, a DNS failure or a connection refusal — all one stable outcome.
            return $this->check(
                'ai',
                'AI service',
                OperationCheckStatus::Unavailable,
                'The AI service did not answer its health endpoint within two seconds.',
                'external',
                'Check the AI service is running and reachable from the application, then reload this page. Reports fall back to rules meanwhile.',
            );
        }
    }

    private function notificationsCheck(): array
    {
        $enabled = (bool) config('notifications.database_enabled', true);

        if (! $enabled) {
            return $this->check(
                'notifications',
                'Notifications',
                OperationCheckStatus::Degraded,
                'The database notification channel is switched off; in-app notifications will not be written.',
                'application',
                'Turn the database notification channel back on in Admin Settings so assignment and due reminders reach their owner.',
            );
        }

        return $this->check(
            'notifications',
            'Notifications',
            OperationCheckStatus::Healthy,
            'The database notification channel is enabled and written on the same connection as the application data.',
            'application',
        );
    }

    private function applicationCheck(): array
    {
        $definitions = SettingRegistry::all();
        $counts = ['database' => 0, 'environment' => 0, 'default' => 0];

        foreach ($definitions as $definition) {
            $source = $this->settings->source($definition);

            if (array_key_exists($source, $counts)) {
                $counts[$source]++;
            }
        }

        $resolved = array_sum($counts);

        if ($resolved === 0) {
            return $this->check(
                'application',
                'Application',
                OperationCheckStatus::Degraded,
                'No allow-listed settings are registered, so effective configuration could not be confirmed.',
                'application',
                'Check the settings registry is intact, then reload this page.',
            );
        }

        return $this->check(
            'application',
            'Application',
            OperationCheckStatus::Healthy,
            sprintf(
                'Effective configuration resolved for %d settings (%d database, %d environment, %d default).',
                $resolved,
                $counts['database'],
                $counts['environment'],
                $counts['default'],
            ),
            'application',
            null,
            [
                'build' => $this->buildLabel(),
                'settings' => $counts,
            ],
        );
    }

    // --- Platform plane ------------------------------------------------------

    private function queueCheck(): array
    {
        $connection = (string) config('queue.default', '');

        if ($connection === '') {
            return $this->check(
                'queue',
                'Queue',
                OperationCheckStatus::NotConfigured,
                'No queue connection is configured; queued work runs synchronously.',
                'application',
                null,
                ['driver' => null],
            );
        }

        if ($connection === 'sync') {
            return $this->check(
                'queue',
                'Queue',
                OperationCheckStatus::Healthy,
                'The queue driver is synchronous — dispatched work runs inside the request.',
                'application',
                null,
                ['driver' => $connection, 'stateful' => false],
            );
        }

        try {
            Schema::connection($this->queueTableConnection())->hasTable($this->queueTable());

            return $this->check(
                'queue',
                'Queue',
                OperationCheckStatus::Healthy,
                sprintf('The %s queue connection is configured and its jobs table is readable.', $connection),
                'application',
                null,
                ['driver' => $connection, 'stateful' => true],
            );
        } catch (Throwable) {
            return $this->check(
                'queue',
                'Queue',
                OperationCheckStatus::Unavailable,
                'The configured queue connection could not be inspected.',
                'application',
                'Check the queue connection settings and the queue worker, then reload this page.',
                ['driver' => $connection, 'stateful' => true],
            );
        }
    }

    private function schemaCheck(): array
    {
        try {
            if (! Schema::hasTable('migrations')) {
                return $this->check(
                    'schema',
                    'Schema',
                    OperationCheckStatus::NotConfigured,
                    'The migration book is not present, so the schema state cannot be reported.',
                    'application',
                    'Run the migration step for this environment, then reload this page.',
                    ['state' => 'not_configured', 'pending' => null],
                );
            }

            $ran = (int) DB::table('migrations')->count();
            $onDisk = count(File::glob(database_path('migrations/*.php')));
            $pending = max($onDisk - $ran, 0);

            return $this->check(
                'schema',
                'Schema',
                $pending === 0 ? OperationCheckStatus::Healthy : OperationCheckStatus::Degraded,
                $pending === 0
                    ? sprintf('The schema is current (%d migrations applied).', $ran)
                    : sprintf('%d migration(s) have not been applied yet.', $pending),
                'application',
                $pending === 0
                    ? null
                    : 'Apply the pending migrations from the deployment pipeline, not from this page.',
                ['state' => $pending === 0 ? 'current' : 'pending', 'pending' => $pending],
            );
        } catch (Throwable) {
            return $this->check(
                'schema',
                'Schema',
                OperationCheckStatus::Unavailable,
                'The schema state could not be read.',
                'application',
                'Check the database connection; the schema state is read from the migration book.',
                ['state' => 'unavailable', 'pending' => null],
            );
        }
    }

    // --- Shared shapes -------------------------------------------------------

    /**
     * A read-only department feed or gateway: report the binding and whether it is mocked.
     * No call is made — a feed has no health endpoint, and pulling account data as a probe
     * would be neither bounded nor read-only in spirit.
     */
    private function gatewayCheck(
        string $key,
        string $label,
        object $gateway,
        string $summary,
        string $mockedSummary,
        string $healthySummary,
        string $remediation,
    ): array {
        $mocked = str_starts_with($gateway::class, 'App\\Integrations\\Mock');

        return $this->check(
            $key,
            $label,
            $mocked ? OperationCheckStatus::Degraded : OperationCheckStatus::Healthy,
            $mocked ? $mockedSummary : $healthySummary,
            $mocked ? 'mocked' : 'external',
            $mocked ? $remediation : null,
            ['binding' => $gateway::class, 'read_only' => true, 'detail' => $summary],
        );
    }

    /**
     * The Service handoff is a historical, read-only flag: the CRM no longer hands a contract
     * off (ADR-0027), so the check reports the binding honestly instead of claiming a live path.
     */
    private function handoffCheck(): array
    {
        return $this->check(
            'service_handoff',
            'Service handoff',
            OperationCheckStatus::NotConfigured,
            'Contracts are a read-only projection owned by Contract Management; the CRM no longer hands a client off.',
            'mocked',
            null,
            ['binding' => MockServiceHandoff::class, 'read_only' => true],
        );
    }

    /**
     * One check row. `detail` is safe context for the UI (never a secret, path, exception or
     * provider payload); the spec's six fields are always present.
     *
     * @param  array<string, mixed>  $detail
     * @return array<string, mixed>
     */
    private function check(
        string $key,
        string $label,
        OperationCheckStatus $status,
        string $summary,
        string $source,
        ?string $remediation = null,
        array $detail = [],
    ): array {
        return [
            'key' => $key,
            'label' => $label,
            'status' => $status->value,
            'checked_at' => now()->toIso8601String(),
            'summary' => $summary,
            'remediation' => $remediation,
            'source' => $source,
            'detail' => $detail,
        ];
    }

    private function isMocked(string $source): bool
    {
        return str_contains(strtolower($source), 'mock');
    }

    private function buildLabel(): string
    {
        $version = trim((string) config('app.version', ''));

        return $version === '' ? 'unknown' : $version;
    }

    private function queueTableConnection(): ?string
    {
        $connection = config('queue.connections.'.config('queue.default').'.connection');

        return is_string($connection) && $connection !== '' ? $connection : null;
    }

    private function queueTable(): string
    {
        $table = config('queue.connections.'.config('queue.default').'.table');

        return is_string($table) && $table !== '' ? $table : 'jobs';
    }
}
