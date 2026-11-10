import { useCallback, useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { Activity, AlertTriangle, RefreshCw } from 'lucide-react'
import PageHeader from '../../components/common/PageHeader'
import EmptyState from '../../components/common/EmptyState'
import AlertItem from '../../components/common/AlertItem'
import StatusBadge from '../../components/common/StatusBadge'
import StatCard from '../../components/common/StatCard'
import { auditActionLabel, auditSeverityMeta } from '../../lib/auditMeta'
import { formatDateTime } from '../../lib/format'
import {
  listRetryableFailedEmails,
  refreshMaintenanceConfiguration,
  retryFailedEmail,
} from '../../lib/admin'
import {
  loadOperations,
  operationStatusMeta,
  operationStatusOrder,
  rollUpHealth,
} from '../../lib/operationsMeta'
import OperationCheckCard from './OperationCheckCard'
import PlatformOperationsPanel from './PlatformOperationsPanel'
import type { Communication, OperationsHealthResponse, OperationsSummary } from '../../types'

/** Where each integration check's owner lives, so the page links instead of duplicating it. */
const ownerRoutes: Record<string, { to: string; label: string }> = {
  email: { to: '/communications', label: 'Open communications' },
  ai: { to: '/reports', label: 'Open AI reports' },
  hr: { to: '/dashboard', label: 'Open dashboard' },
  recruitment: { to: '/accounts', label: 'Open accounts' },
  deployment: { to: '/deployments', label: 'Open deployments' },
  timekeeping: { to: '/deployments', label: 'Open deployments' },
  notifications: { to: '/follow-ups', label: 'Open follow-ups' },
  application: { to: '/admin/settings', label: 'Open settings' },
}

/**
 * The operational health dashboard (module 08 slice S6) and its safe maintenance actions
 * (slice S7, spec 19 §S7).
 *
 * It reads the two admin operations endpoints and renders what they return. The maintenance
 * panel appears only when the server says `maintenance.actions_available`: a confirmed
 * configuration refresh and a retry of one failed outbound email, both through the admin
 * maintenance endpoints — never a command runner or a generic cache flush. The page does not
 * decide the role boundary: an application admin's summary simply carries no `platform` block
 * to render. Each half degrades on its own so one failing call never blanks the other.
 */
export default function AdminOperations() {
  const [summary, setSummary] = useState<OperationsSummary | null>(null)
  const [health, setHealth] = useState<OperationsHealthResponse | null>(null)
  const [summaryError, setSummaryError] = useState('')
  const [healthError, setHealthError] = useState('')
  const [loading, setLoading] = useState(true)
  const [refreshing, setRefreshing] = useState(false)

  const [refreshingConfiguration, setRefreshingConfiguration] = useState(false)
  const [maintenanceNotice, setMaintenanceNotice] = useState('')
  const [maintenanceError, setMaintenanceError] = useState('')

  const [failedEmails, setFailedEmails] = useState<Communication[]>([])
  const [failedEmailsLoading, setFailedEmailsLoading] = useState(false)
  const [failedEmailsError, setFailedEmailsError] = useState('')
  const [selectedEmailId, setSelectedEmailId] = useState('')
  const [retryingEmail, setRetryingEmail] = useState(false)
  const [retryOutcome, setRetryOutcome] = useState<'sent' | 'failed' | ''>('')
  const [retryNotice, setRetryNotice] = useState('')
  const [retryError, setRetryError] = useState('')

  const load = useCallback(async (initial: boolean) => {
    if (initial) setLoading(true)
    else setRefreshing(true)

    try {
      const result = await loadOperations()
      setSummary(result.summary)
      setSummaryError(result.summaryError)
      setHealth(result.health)
      setHealthError(result.healthError)
    } finally {
      setLoading(false)
      setRefreshing(false)
    }
  }, [])

  const loadFailedEmails = useCallback(async () => {
    setFailedEmailsLoading(true)
    setFailedEmailsError('')

    try {
      const emails = await listRetryableFailedEmails()
      setFailedEmails(emails)
      setSelectedEmailId((current) =>
        current && emails.some((email) => email.id === current) ? current : (emails[0]?.id ?? ''),
      )
    } catch {
      setFailedEmails([])
      setSelectedEmailId('')
      setFailedEmailsError('Could not load the failed emails. Retry the list.')
    } finally {
      setFailedEmailsLoading(false)
    }
  }, [])

  useEffect(() => {
    void load(true)
  }, [load])

  const actionsAvailable = summary?.maintenance.actions_available ?? false

  useEffect(() => {
    if (actionsAvailable) void loadFailedEmails()
  }, [actionsAvailable, loadFailedEmails])

  const refreshConfiguration = () => {
    if (
      !window.confirm(
        'Rebuild the effective configuration snapshot now? This is idempotent and writes an audit event; it is not a cache flush.',
      )
    ) {
      return
    }

    setRefreshingConfiguration(true)
    setMaintenanceNotice('')
    setMaintenanceError('')

    void refreshMaintenanceConfiguration()
      .then(async () => {
        setMaintenanceNotice('The effective configuration was refreshed.')
        await load(false)
      })
      .catch(() => setMaintenanceError('Could not refresh the configuration.'))
      .finally(() => setRefreshingConfiguration(false))
  }

  const retrySelectedEmail = () => {
    const email = failedEmails.find((candidate) => candidate.id === selectedEmailId)

    if (!email) return

    if (
      !window.confirm(
        `Retry the failed email ${email.reference}? It goes through the mocked mail gateway exactly once.`,
      )
    ) {
      return
    }

    setRetryingEmail(true)
    setRetryOutcome('')
    setRetryNotice('')
    setRetryError('')

    void retryFailedEmail(email.id)
      .then(async (result) => {
        setRetryOutcome(result.result)
        setRetryNotice(
          result.result === 'sent'
            ? 'The gateway accepted the message; it is no longer a failed email.'
            : 'The mocked gateway rejected the retry again. The message stays failed and can be retried later.',
        )
        await Promise.all([load(false), loadFailedEmails()])
      })
      .catch(() => setRetryError('Could not retry the email. Nothing was sent.'))
      .finally(() => setRetryingEmail(false))
  }

  const recipientLabel = (email: Communication) =>
    email.to_address ?? email.contact?.email ?? 'No recipient'

  if (loading) {
    return (
      <div className="flex flex-col gap-6">
        <PageHeader
          title="Operations health"
          description="Application dependencies, integration status, administrative activity and safe maintenance."
        />
        <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-3" aria-busy="true">
          {[0, 1, 2, 3, 4, 5].map((key) => (
            <div key={key} className="h-36 animate-pulse rounded-lg border border-border bg-card" />
          ))}
        </div>
      </div>
    )
  }

  const checks = health?.health.checks ?? []
  const rollup = rollUpHealth(checks)
  const platform = summary?.platform ?? null

  return (
    <div className="flex flex-col gap-6">
      <PageHeader
        title="Operations health"
        description="Application dependencies, integration status, administrative activity and safe, allow-listed maintenance."
        actions={
          <button
            type="button"
            onClick={() => void load(false)}
            disabled={refreshing}
            className="inline-flex items-center gap-2 rounded-md border border-border bg-card px-3 py-2 text-sm font-medium text-foreground hover:bg-muted disabled:opacity-60"
          >
            <RefreshCw className={`h-4 w-4 ${refreshing ? 'animate-spin' : ''}`} aria-hidden />
            {refreshing ? 'Re-checking…' : 'Re-check'}
          </button>
        }
      />

      {healthError && (
        <AlertItem variant="danger" title="Health checks could not be loaded">
          <p>{healthError} Reload the page or try again.</p>
          <button
            type="button"
            onClick={() => void load(false)}
            className="mt-2 font-medium text-foreground underline"
          >
            Retry
          </button>
        </AlertItem>
      )}

      {summaryError && (
        <AlertItem variant="warning" title="The operations summary could not be loaded">
          <p>{summaryError} The health checks below are unaffected.</p>
        </AlertItem>
      )}

      {/* Overall health strip */}
      {checks.length > 0 && (
        <section
          className="flex flex-col gap-4 rounded-lg border border-border bg-card p-4 sm:flex-row sm:items-center sm:justify-between"
          data-panel="overall"
        >
          <div className="flex items-center gap-3">
            <span
              className={`flex h-10 w-10 items-center justify-center rounded-full ${
                rollup.attention > 0 ? 'bg-cost/15' : 'bg-people/15'
              }`}
            >
              {rollup.attention > 0 ? (
                <AlertTriangle className="h-5 w-5 text-cost" aria-hidden />
              ) : (
                <Activity className="h-5 w-5 text-people" aria-hidden />
              )}
            </span>
            <div>
              <p className="text-sm font-semibold text-foreground" role="status">
                {rollup.headline}
              </p>
              <p className="text-xs text-muted-foreground">
                {rollup.total} checks · {rollup.counts.healthy} healthy · {rollup.counts.degraded}{' '}
                degraded · {rollup.counts.unavailable} unavailable · {rollup.counts.not_configured}{' '}
                not configured
              </p>
            </div>
          </div>
          <div className="flex flex-wrap gap-2">
            {operationStatusOrder.map((status) => (
              <StatusBadge key={status} tone={operationStatusMeta[status].tone}>
                {operationStatusMeta[status].label}: {rollup.counts[status]}
              </StatusBadge>
            ))}
          </div>
        </section>
      )}

      {/* Health checks */}
      <section aria-labelledby="checks-heading">
        <h3 id="checks-heading" className="mb-3 text-sm font-semibold text-foreground">
          Dependency checks
        </h3>
        {checks.length === 0 ? (
          <EmptyState
            title="No checks reported"
            description="The health endpoint returned no dependency checks. Re-check or inspect the application logs."
          />
        ) : (
          <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
            {checks.map((check) => (
              <OperationCheckCard key={check.key} check={check} />
            ))}
          </div>
        )}
      </section>

      {summary && (
        <>
          {/* Counters */}
          <section
            className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4"
            aria-label="Summary counters"
          >
            <StatCard
              label="Active accounts"
              value={summary.accounts.active}
              domain="people"
              hint={`${summary.accounts.suspended} suspended`}
              to="/admin/users"
            />
            <StatCard
              label="Admin activity (24h)"
              value={summary.administrative_activity.total}
              domain="activity"
              hint={`${summary.administrative_activity.severities.warning} warning · ${summary.administrative_activity.severities.critical} critical`}
              to="/admin/audit"
            />
            <StatCard
              label="Failed emails"
              value={
                summary.failed_operations.failed_emails === null
                  ? '—'
                  : summary.failed_operations.failed_emails
              }
              domain="cost"
              hint={
                summary.failed_operations.available
                  ? `${summary.maintenance.retryable_failed_emails ?? 0} retryable`
                  : 'Not available'
              }
              to="/communications"
            />
            <StatCard
              label="Configuration changes"
              value={summary.configuration_changes.last_7_days}
              domain="counts"
              hint={`Last ${summary.configuration_changes.window_days} days`}
              to="/admin/settings"
            />
          </section>

          <div className="grid gap-6 lg:grid-cols-2">
            {/* Integration health */}
            <section
              className="rounded-lg border border-border bg-card p-4"
              data-panel="integrations"
            >
              <h3 className="text-sm font-semibold text-foreground">Integration health</h3>
              <p className="mt-1 text-xs text-muted-foreground">
                {summary.integrations.mocked} check
                {summary.integrations.mocked === 1 ? ' is' : 's are'} served by a mocked source —
                not a live department connection.
              </p>
              <div className="mt-3 space-y-2">
                {summary.integrations.attention.length === 0 ? (
                  <p className="text-sm text-muted-foreground">
                    No integration needs attention right now.
                  </p>
                ) : (
                  summary.integrations.attention.map((item) => {
                    const meta = operationStatusMeta[item.status]
                    const route = ownerRoutes[item.key]

                    return (
                      <div
                        key={item.key}
                        className="flex items-center justify-between gap-2 rounded-md bg-muted px-3 py-2"
                      >
                        <div className="flex items-center gap-2">
                          <StatusBadge tone={meta.tone}>{meta.label}</StatusBadge>
                          <span className="text-sm text-foreground">{item.label}</span>
                        </div>
                        {route && (
                          <Link
                            to={route.to}
                            className="text-xs font-medium text-primary hover:underline"
                          >
                            {route.label}
                          </Link>
                        )}
                      </div>
                    )
                  })
                )}
              </div>
            </section>

            {/* Recent administrative activity */}
            <section className="rounded-lg border border-border bg-card p-4" data-panel="activity">
              <div className="flex items-center justify-between gap-2">
                <h3 className="text-sm font-semibold text-foreground">
                  Recent administrative activity
                </h3>
                <Link
                  to="/admin/audit"
                  className="text-xs font-medium text-primary hover:underline"
                >
                  Open audit log
                </Link>
              </div>
              {summary.administrative_activity.recent.length === 0 ? (
                <p className="mt-3 text-sm text-muted-foreground">
                  No administrative events in the last 24 hours.
                </p>
              ) : (
                <ul className="mt-3 divide-y divide-border">
                  {summary.administrative_activity.recent.map((row) => (
                    <li key={row.id} className="flex items-start justify-between gap-3 py-2">
                      <div>
                        <p className="text-sm text-foreground">{auditActionLabel(row.action)}</p>
                        <p className="text-xs text-muted-foreground">
                          {row.actor ?? 'System'} · {formatDateTime(row.created_at)}
                        </p>
                      </div>
                      <StatusBadge tone={auditSeverityMeta[row.severity].tone}>
                        {auditSeverityMeta[row.severity].label}
                      </StatusBadge>
                    </li>
                  ))}
                </ul>
              )}
            </section>
          </div>

          {/* Safe, allow-listed maintenance (S7) — only the two server-supported actions. */}
          {summary.maintenance.actions_available && (
            <section
              className="rounded-lg border border-border bg-card p-4"
              data-panel="maintenance"
              aria-labelledby="maintenance-heading"
            >
              <div className="flex flex-wrap items-center justify-between gap-2">
                <h3 id="maintenance-heading" className="text-sm font-semibold text-foreground">
                  Maintenance
                </h3>
                <p className="text-xs text-muted-foreground">
                  Allow-listed actions only · each run is confirmed and audited
                </p>
              </div>
              <p className="mt-1 text-xs text-muted-foreground">
                These actions call the owning service rather than a command runner: the settings
                refresh rebuilds the effective snapshot and a retry hands exactly one message to
                the mail gateway.
              </p>

              <div className="mt-4 grid gap-4 lg:grid-cols-2">
                <div
                  className="rounded-md border border-border bg-background p-4"
                  data-maintenance-action="refresh"
                >
                  <h4 className="text-sm font-semibold text-foreground">Configuration refresh</h4>
                  <p className="mt-1 text-xs text-muted-foreground">
                    Rebuild the effective settings snapshot from the allow-list. Idempotent, and
                    it never clears a generic cache.
                  </p>
                  <button
                    type="button"
                    onClick={refreshConfiguration}
                    disabled={refreshingConfiguration}
                    className="mt-3 inline-flex items-center gap-2 rounded-md border border-border bg-card px-3 py-2 text-sm font-medium text-foreground hover:bg-muted disabled:opacity-60"
                  >
                    <RefreshCw
                      className={`h-4 w-4 ${refreshingConfiguration ? 'animate-spin' : ''}`}
                      aria-hidden
                    />
                    {refreshingConfiguration ? 'Refreshing…' : 'Refresh configuration'}
                  </button>

                  {maintenanceNotice && (
                    <div className="mt-3">
                      <AlertItem variant="success">{maintenanceNotice}</AlertItem>
                    </div>
                  )}
                  {maintenanceError && (
                    <div className="mt-3">
                      <AlertItem variant="danger">{maintenanceError}</AlertItem>
                    </div>
                  )}
                </div>

                <div
                  className="rounded-md border border-border bg-background p-4"
                  data-maintenance-action="retry-email"
                >
                  <h4 className="text-sm font-semibold text-foreground">Retry a failed email</h4>
                  <p className="mt-1 text-xs text-muted-foreground">
                    One outbound email the mocked gateway rejected. A rejection here is a delivery
                    outcome, not a page error.
                  </p>

                  {failedEmailsLoading ? (
                    <p className="mt-3 text-sm text-muted-foreground" role="status">
                      Loading failed emails…
                    </p>
                  ) : failedEmailsError ? (
                    <div className="mt-3">
                      <AlertItem variant="danger" title="Failed emails could not be loaded">
                        <p>{failedEmailsError}</p>
                        <button
                          type="button"
                          onClick={() => void loadFailedEmails()}
                          className="mt-2 font-medium text-foreground underline"
                        >
                          Retry
                        </button>
                      </AlertItem>
                    </div>
                  ) : failedEmails.length === 0 ? (
                    <p className="mt-3 text-sm text-muted-foreground">
                      No failed outbound emails are waiting to be retried.
                    </p>
                  ) : (
                    <>
                      <label
                        htmlFor="failed-email"
                        className="mt-3 block text-xs font-medium text-foreground"
                      >
                        Failed email
                      </label>
                      <select
                        id="failed-email"
                        value={selectedEmailId}
                        onChange={(event) => setSelectedEmailId(event.target.value)}
                        className="mt-1 w-full rounded-md border border-border bg-card px-3 py-2 text-sm text-foreground"
                      >
                        {failedEmails.map((email) => (
                          <option key={email.id} value={email.id}>
                            {email.reference} — {email.subject} — {recipientLabel(email)}
                          </option>
                        ))}
                      </select>
                      <button
                        type="button"
                        onClick={retrySelectedEmail}
                        disabled={retryingEmail || !selectedEmailId}
                        className="mt-3 inline-flex items-center gap-2 rounded-md border border-border bg-card px-3 py-2 text-sm font-medium text-foreground hover:bg-muted disabled:opacity-60"
                      >
                        <RefreshCw
                          className={`h-4 w-4 ${retryingEmail ? 'animate-spin' : ''}`}
                          aria-hidden
                        />
                        {retryingEmail ? 'Retrying…' : 'Retry failed email'}
                      </button>
                    </>
                  )}

                  {retryNotice && (
                    <div className="mt-3">
                      <AlertItem variant={retryOutcome === 'failed' ? 'warning' : 'success'}>
                        {retryNotice}
                      </AlertItem>
                    </div>
                  )}
                  {retryError && (
                    <div className="mt-3">
                      <AlertItem variant="danger">{retryError}</AlertItem>
                    </div>
                  )}
                </div>
              </div>
            </section>
          )}

          {platform && <PlatformOperationsPanel platform={platform} />}
        </>
      )}

      {!summary && !summaryError && (
        <EmptyState
          title="No operations summary"
          description="The summary endpoint returned nothing to display."
        />
      )}

      <p className="text-xs text-muted-foreground">
        Times are shown in the business timezone
        {summary ? ` (${summary.window.timezone})` : ''}. A successful probe means only that the
        named dependency answered at the time shown.
      </p>
    </div>
  )
}
