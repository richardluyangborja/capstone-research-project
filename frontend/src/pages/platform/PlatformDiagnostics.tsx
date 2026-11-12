import { useCallback, useEffect, useState } from 'react'
import type { ReactNode } from 'react'
import { Link } from 'react-router-dom'
import { Database, HardDrive, RefreshCw, ShieldCheck, TimerOff } from 'lucide-react'
import PageHeader from '../../components/common/PageHeader'
import EmptyState from '../../components/common/EmptyState'
import AlertItem from '../../components/common/AlertItem'
import StatusBadge from '../../components/common/StatusBadge'
import { getPlatformDiagnostics } from '../../lib/admin'
import { formatDateTime, formatFileSize } from '../../lib/format'
import { diagnosticEntityOrder, diagnosticStateMeta } from '../../lib/diagnosticsMeta'
import type { DiagnosticSectionState, PlatformDiagnosticsResponse } from '../../types'

function DiagnosticStateBadge({ state }: { state: DiagnosticSectionState }) {
  const meta = diagnosticStateMeta[state]

  return <StatusBadge tone={meta.tone}>{meta.label}</StatusBadge>
}

function Fact({ label, children }: { label: string; children: ReactNode }) {
  return (
    <div>
      <dt className="text-xs text-muted-foreground">{label}</dt>
      <dd className="mt-0.5 text-sm font-medium text-foreground">{children}</dd>
    </div>
  )
}

/**
 * Superadmin platform diagnostics (module 08 slice S8, spec 19 §S8).
 *
 * A read-only rendering of the bounded diagnostic payload: runtime labels, migration state,
 * attachment storage usage, failed queue jobs, environment capability status and allow-listed
 * entity counts. Each section carries its own explicit state, so an unavailable subsystem or a
 * timed-out fixed read is visible rather than shown as zero. There is no SQL console, schema
 * dump, credential view, export or write action — and every successful view is audited.
 */
export default function PlatformDiagnostics() {
  const [response, setResponse] = useState<PlatformDiagnosticsResponse | null>(null)
  const [loading, setLoading] = useState(true)
  const [refreshing, setRefreshing] = useState(false)
  const [error, setError] = useState('')

  const load = useCallback(async (initial: boolean) => {
    if (initial) setLoading(true)
    else setRefreshing(true)

    setError('')

    try {
      setResponse(await getPlatformDiagnostics())
    } catch {
      setError('Could not load the platform diagnostics.')
    } finally {
      setLoading(false)
      setRefreshing(false)
    }
  }, [])

  useEffect(() => {
    void load(true)
  }, [load])

  const diagnostics = response?.diagnostics ?? null

  return (
    <div className="flex flex-col gap-6">
      <PageHeader
        title="Platform diagnostics"
        description="Bounded, read-only runtime and data diagnostics. Fixed queries only — no SQL console, schema dump, credential view or write action."
        actions={
          <button
            type="button"
            onClick={() => void load(false)}
            disabled={refreshing}
            className="inline-flex items-center gap-2 rounded-md border border-border bg-card px-3 py-2 text-sm font-medium text-foreground hover:bg-muted disabled:opacity-60"
          >
            <RefreshCw className={`h-4 w-4 ${refreshing ? 'animate-spin' : ''}`} aria-hidden />
            {refreshing ? 'Refreshing…' : 'Refresh'}
          </button>
        }
      />

      <div className="flex flex-wrap items-center gap-2 rounded-md border border-border bg-muted px-3 py-2 text-xs text-muted-foreground">
        <ShieldCheck className="h-3.5 w-3.5 text-counts" aria-hidden />
        Every successful diagnostic view is recorded in the audit trail as
        <span className="font-mono text-foreground">diagnostic.viewed</span>.
        <Link to="/admin/audit" className="ml-auto font-medium text-primary hover:underline">
          Open audit log
        </Link>
      </div>

      {loading && (
        <div className="grid gap-4 lg:grid-cols-2" aria-busy="true">
          {[0, 1, 2, 3].map((key) => (
            <div key={key} className="h-48 animate-pulse rounded-lg border border-border bg-card" />
          ))}
        </div>
      )}

      {!loading && error && (
        <AlertItem variant="danger" title="Platform diagnostics could not be loaded">
          <p>{error} Reload the page or try again.</p>
          <button
            type="button"
            onClick={() => void load(true)}
            className="mt-2 font-medium text-foreground underline"
          >
            Retry
          </button>
        </AlertItem>
      )}

      {!loading && !diagnostics && !error && (
        <EmptyState
          title="No diagnostics reported"
          description="The endpoint returned nothing to display. Refresh the page or inspect the application logs."
        />
      )}

      {diagnostics && (
        <>
          <section
            className="flex flex-col gap-2 rounded-lg border border-border bg-card p-4 sm:flex-row sm:items-center sm:justify-between"
            data-panel="overview"
          >
            <div>
              <p className="text-sm font-semibold text-foreground">Read-only snapshot</p>
              <p className="text-xs text-muted-foreground">
                Generated {formatDateTime(diagnostics.generated_at)} · times in{' '}
                {response?.meta.timezone ?? 'Asia/Manila'}
              </p>
            </div>
            <div className="flex flex-wrap items-center gap-2">
              <StatusBadge tone="success">Read-only</StatusBadge>
              <StatusBadge tone="activity">Superadmin</StatusBadge>
            </div>
          </section>

          <div className="grid gap-4 lg:grid-cols-2">
            <section
              className="rounded-lg border border-border bg-card p-4"
              data-panel="application"
            >
              <div className="mb-3 flex items-center justify-between gap-2">
                <div className="flex items-center gap-2">
                  <Database className="h-4 w-4 text-counts" aria-hidden />
                  <h3 className="text-sm font-semibold text-foreground">Application runtime</h3>
                </div>
                <DiagnosticStateBadge state={diagnostics.application.state} />
              </div>
              <dl className="grid grid-cols-2 gap-3">
                <Fact label="Build">{diagnostics.application.build}</Fact>
                <Fact label="Runtime">{diagnostics.application.runtime}</Fact>
                <Fact label="Environment">{diagnostics.application.environment}</Fact>
                <Fact label="Timezone">{diagnostics.application.timezone}</Fact>
                <Fact label="Debug mode">
                  <StatusBadge tone={diagnostics.application.debug ? 'warm' : 'success'}>
                    {diagnostics.application.debug ? 'On' : 'Off'}
                  </StatusBadge>
                </Fact>
                <Fact label="Maintenance mode">
                  <StatusBadge
                    tone={diagnostics.application.maintenance_mode ? 'warm' : 'success'}
                  >
                    {diagnostics.application.maintenance_mode ? 'On' : 'Off'}
                  </StatusBadge>
                </Fact>
              </dl>
            </section>

            <section className="rounded-lg border border-border bg-card p-4" data-panel="schema">
              <div className="mb-3 flex items-center justify-between gap-2">
                <h3 className="text-sm font-semibold text-foreground">Schema</h3>
                <DiagnosticStateBadge state={diagnostics.schema.state} />
              </div>
              <dl className="grid grid-cols-2 gap-3">
                <Fact label="Applied migrations">
                  {diagnostics.schema.applied === null ? 'Not available' : diagnostics.schema.applied}
                </Fact>
                <Fact label="Pending migrations">
                  {diagnostics.schema.pending === null ? 'Not available' : diagnostics.schema.pending}
                </Fact>
                <Fact label="Latest migration">
                  <span className="break-all font-mono text-xs">
                    {diagnostics.schema.latest ?? 'Not available'}
                  </span>
                </Fact>
                <Fact label="Latest batch">
                  {diagnostics.schema.batch === null ? 'Not available' : diagnostics.schema.batch}
                </Fact>
              </dl>
            </section>
          </div>

          <div className="grid gap-4 lg:grid-cols-2">
            <section className="rounded-lg border border-border bg-card p-4" data-panel="storage">
              <div className="mb-3 flex items-center justify-between gap-2">
                <div className="flex items-center gap-2">
                  <HardDrive className="h-4 w-4 text-cost" aria-hidden />
                  <h3 className="text-sm font-semibold text-foreground">Attachment storage</h3>
                </div>
                <DiagnosticStateBadge state={diagnostics.storage.state} />
              </div>
              <dl className="grid grid-cols-2 gap-3">
                <Fact label="Disk">{diagnostics.storage.disk}</Fact>
                <Fact label="Writable">
                  <StatusBadge tone={diagnostics.storage.writable ? 'success' : 'danger'}>
                    {diagnostics.storage.writable ? 'Yes' : 'No'}
                  </StatusBadge>
                </Fact>
                <Fact label="Attachment files">
                  <span className="flex flex-wrap items-center gap-2">
                    {diagnostics.storage.attachments.count === null
                      ? 'Not available'
                      : diagnostics.storage.attachments.count}
                    {diagnostics.storage.attachments.state !== 'available' && (
                      <DiagnosticStateBadge state={diagnostics.storage.attachments.state} />
                    )}
                  </span>
                </Fact>
                <Fact label="Attachment bytes">
                  {diagnostics.storage.attachments.bytes === null
                    ? 'Not available'
                    : formatFileSize(diagnostics.storage.attachments.bytes)}
                </Fact>
                <Fact label="Free space">
                  {diagnostics.storage.free_bytes === null
                    ? 'Not reported'
                    : formatFileSize(diagnostics.storage.free_bytes)}
                </Fact>
                <Fact label="Total space">
                  {diagnostics.storage.total_bytes === null
                    ? 'Not reported'
                    : formatFileSize(diagnostics.storage.total_bytes)}
                </Fact>
              </dl>
            </section>

            <section
              className="rounded-lg border border-border bg-card p-4"
              data-panel="failed-work"
            >
              <div className="mb-3 flex items-center justify-between gap-2">
                <div className="flex items-center gap-2">
                  <TimerOff className="h-4 w-4 text-activity" aria-hidden />
                  <h3 className="text-sm font-semibold text-foreground">Failed work</h3>
                </div>
                <DiagnosticStateBadge state={diagnostics.failed_work.state} />
              </div>
              <dl className="grid grid-cols-2 gap-3">
                <Fact label="Queue driver">{diagnostics.failed_work.queue.driver}</Fact>
                <Fact label="Failed jobs">
                  {diagnostics.failed_work.queue.count === null
                    ? 'Not available'
                    : diagnostics.failed_work.queue.count}
                </Fact>
                <Fact label="Last failed">
                  {diagnostics.failed_work.queue.last_failed_at
                    ? formatDateTime(diagnostics.failed_work.queue.last_failed_at)
                    : 'None recorded'}
                </Fact>
                <Fact label="Failed-request store">
                  <span className="flex flex-wrap items-center gap-2">
                    <DiagnosticStateBadge state={diagnostics.failed_work.requests.state} />
                    <span className="text-xs font-normal text-muted-foreground">
                      {diagnostics.failed_work.requests.summary}
                    </span>
                  </span>
                </Fact>
              </dl>

              <div className="mt-4">
                <p className="text-xs font-medium text-muted-foreground">Recent failed jobs</p>
                {diagnostics.failed_work.queue.recent.length === 0 ? (
                  <p className="mt-1 text-sm text-muted-foreground">
                    No failed jobs are recorded. Payloads and exception traces are never read.
                  </p>
                ) : (
                  <ul className="mt-2 divide-y divide-border">
                    {diagnostics.failed_work.queue.recent.map((job) => (
                      <li
                        key={job.uuid}
                        className="flex flex-wrap items-center justify-between gap-2 py-2"
                      >
                        <span className="font-mono text-xs text-foreground">{job.uuid}</span>
                        <span className="text-xs text-muted-foreground">
                          {job.connection ?? 'default'} · {job.queue ?? 'default'} ·{' '}
                          {job.failed_at ? formatDateTime(job.failed_at) : 'unknown time'}
                        </span>
                      </li>
                    ))}
                  </ul>
                )}
              </div>
            </section>
          </div>

          <section
            className="rounded-lg border border-border bg-card p-4"
            data-panel="capabilities"
            aria-labelledby="capabilities-heading"
          >
            <div className="mb-3 flex items-center justify-between gap-2">
              <h3 id="capabilities-heading" className="text-sm font-semibold text-foreground">
                Environment capabilities
              </h3>
              <p className="text-xs text-muted-foreground">Labels only · no credentials or URLs</p>
            </div>
            <ul className="divide-y divide-border">
              {diagnostics.capabilities.map((capability) => (
                <li
                  key={capability.key}
                  data-capability={capability.key}
                  className="flex flex-col gap-1 py-2 sm:flex-row sm:items-start sm:justify-between sm:gap-4"
                >
                  <div>
                    <p className="text-sm font-medium text-foreground">{capability.label}</p>
                    <p className="text-xs text-muted-foreground">{capability.summary}</p>
                  </div>
                  <div className="flex shrink-0 items-center gap-2">
                    <span className="font-mono text-xs text-muted-foreground">
                      {capability.value}
                    </span>
                    <DiagnosticStateBadge state={capability.state} />
                  </div>
                </li>
              ))}
            </ul>
          </section>

          <section
            className="rounded-lg border border-border bg-card p-4"
            data-panel="entities"
            aria-labelledby="entities-heading"
          >
            <div className="mb-3 flex items-center justify-between gap-2">
              <h3 id="entities-heading" className="text-sm font-semibold text-foreground">
                Allow-listed record totals
              </h3>
              <DiagnosticStateBadge state={diagnostics.entity_totals.state} />
            </div>
            <dl className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-5">
              {diagnosticEntityOrder.map(({ key, label }) => {
                const entity = diagnostics.entity_totals.counts[key]

                return (
                  <div key={key}>
                    <dt className="text-xs text-muted-foreground">{entity?.label ?? label}</dt>
                    <dd className="mt-0.5 flex flex-wrap items-center gap-1.5 text-sm font-medium text-foreground">
                      {entity?.count === null || entity === undefined ? '—' : entity.count}
                      {entity && entity.state !== 'available' && (
                        <DiagnosticStateBadge state={entity.state} />
                      )}
                    </dd>
                  </div>
                )
              })}
            </dl>
          </section>
        </>
      )}
    </div>
  )
}
