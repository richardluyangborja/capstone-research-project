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
  loadOperations,
  operationStatusMeta,
  operationStatusOrder,
  rollUpHealth,
} from '../../lib/operationsMeta'
import OperationCheckCard from './OperationCheckCard'
import PlatformOperationsPanel from './PlatformOperationsPanel'
import type { OperationsHealthResponse, OperationsSummary } from '../../types'

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
 * The operational health dashboard (module 08 slice S6, spec 19 §S6).
 *
 * Read-only: it reads the two admin operations endpoints and renders what they return. It adds
 * no maintenance control (S7 owns those), and it does not decide the role boundary — an
 * application admin's summary simply carries no `platform` block to render. The page has
 * loading, empty, partial and error/retry states, and each half degrades on its own so one
 * failing call never blanks the other.
 */
export default function AdminOperations() {
  const [summary, setSummary] = useState<OperationsSummary | null>(null)
  const [health, setHealth] = useState<OperationsHealthResponse | null>(null)
  const [summaryError, setSummaryError] = useState('')
  const [healthError, setHealthError] = useState('')
  const [loading, setLoading] = useState(true)
  const [refreshing, setRefreshing] = useState(false)

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

  useEffect(() => {
    void load(true)
  }, [load])

  if (loading) {
    return (
      <div className="flex flex-col gap-6">
        <PageHeader
          title="Operations health"
          description="Application dependencies, integration status and administrative activity. Read-only."
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
        description="Application dependencies, integration status and administrative activity. Read-only — no maintenance runs from this page."
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

          {/* Pending maintenance — informational only; S7 owns the actions. */}
          <AlertItem variant="info" title="Pending maintenance">
            {summary.maintenance.retryable_failed_emails ?? 0} failed email
            {summary.maintenance.retryable_failed_emails === 1 ? '' : 's'} could be retried and the
            effective configuration can be refreshed. This page does not run either — maintenance
            actions arrive in a later slice.
          </AlertItem>

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
