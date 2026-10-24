import { useEffect, useState } from 'react'
import type { LucideIcon } from 'lucide-react'
import {
  ArrowRight,
  ArrowUpRight,
  BriefcaseBusiness,
  Building2,
  BadgeDollarSign,
  CalendarClock,
  ClipboardList,
  Percent,
  Target,
  TriangleAlert,
} from 'lucide-react'
import { Link } from 'react-router-dom'
import PageHeader from '../components/common/PageHeader'
import StatCard from '../components/common/StatCard'
import ChartCard from '../components/common/ChartCard'
import LineChart from '../components/common/LineChart'
import DonutChart from '../components/common/DonutChart'
import DataTable, { type DataTableColumn } from '../components/common/DataTable'
import StatusBadge, { type Tone } from '../components/common/StatusBadge'
import AlertItem from '../components/common/AlertItem'
import AiReportPanel from '../components/dashboard/AiReportPanel'
import StaffPerformanceTable from '../components/dashboard/StaffPerformanceTable'
import { useAuth } from '../lib/auth-context'
import {
  DASHBOARD_RANGES,
  getDashboard,
  type DashboardData,
  type DashboardRange,
  type DashboardWorklistRow,
  type WorklistKind,
  type WorklistSeverity,
} from '../lib/dashboard'

const kpiIcons: Record<string, LucideIcon> = {
  'new-leads': ClipboardList,
  qualified: Target,
  pipeline: BriefcaseBusiness,
  won: BadgeDollarSign,
  'win-rate': Percent,
  clients: Building2,
  'follow-ups': CalendarClock,
  attention: TriangleAlert,
}

const severityTone: Record<WorklistSeverity, Tone> = {
  danger: 'danger',
  warning: 'warm',
  info: 'counts',
}

const kindLabel: Record<WorklistKind, string> = {
  lead: 'Lead',
  company: 'Account',
  opportunity: 'Deal',
  follow_up: 'Follow-up',
  survey: 'Survey',
  contract: 'Contract',
}

const itemTone: Record<DashboardData['activity'][number]['domain'], string> = {
  counts: 'bg-counts',
  people: 'bg-people',
  activity: 'bg-activity',
  cost: 'bg-cost',
}

const timeOfDay = () => {
  const hour = Number(
    new Intl.DateTimeFormat('en-GB', {
      hour: 'numeric',
      hour12: false,
      timeZone: 'Asia/Manila',
    }).format(new Date()),
  )

  if (hour < 12) return 'Good morning'
  if (hour < 18) return 'Good afternoon'
  return 'Good evening'
}

/**
 * The dashboard home (module 06, spec 14, ADR-0020).
 *
 * Every block comes from the three read-only dashboard endpoints through `getDashboard`; the
 * server scopes them by the session. The page is role-aware through the session role only (no
 * per-role layouts): a salesrep reads their book, a manager the team, an admin the org. Every
 * KPI, chart legend, worklist row, alert and AI action links to an existing page.
 */
export default function DashboardHome() {
  const { user } = useAuth()
  const role = user?.role ?? 'salesrep'
  const firstName = user?.name.split(' ')[0] ?? 'there'

  const [range, setRange] = useState<DashboardRange>('30d')
  const [data, setData] = useState<DashboardData | null>(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')
  const [reloadKey, setReloadKey] = useState(0)

  useEffect(() => {
    let cancelled = false
    setLoading(true)
    setError('')
    setData(null)

    void getDashboard(role, range)
      .then((next) => {
        if (!cancelled) setData(next)
      })
      .catch(() => {
        if (!cancelled) {
          setError('Could not load the dashboard. Check the API and try again.')
        }
      })
      .finally(() => {
        if (!cancelled) setLoading(false)
      })

    return () => {
      cancelled = true
    }
  }, [role, range, reloadKey])

  const worklistColumns: DataTableColumn<DashboardWorklistRow>[] = [
    {
      key: 'severity',
      header: 'Priority',
      render: (row) => <StatusBadge tone={severityTone[row.severity]}>{row.due}</StatusBadge>,
    },
    {
      key: 'subject',
      header: 'Needs attention',
      render: (row) => (
        <div className="flex flex-col">
          <Link to={row.to} className="font-medium text-foreground hover:text-primary">
            {row.subject}
          </Link>
          <span className="text-xs text-muted-foreground">{row.detail}</span>
        </div>
      ),
    },
    {
      key: 'kind',
      header: 'Type',
      render: (row) => (
        <span className="text-xs font-medium uppercase tracking-wide text-muted-foreground">
          {kindLabel[row.kind]}
        </span>
      ),
    },
    {
      key: 'owner',
      header: 'Owner',
      render: (row) => row.owner,
    },
    {
      key: 'action',
      header: '',
      headerClassName: 'text-right',
      className: 'text-right',
      render: (row) => (
        <Link
          to={row.to}
          className="inline-flex items-center gap-1 text-sm font-medium text-primary hover:underline"
        >
          {row.action}
          <ArrowRight className="h-3.5 w-3.5" aria-hidden />
        </Link>
      ),
    },
  ]

  return (
    <div className="flex flex-col gap-6" aria-busy={loading}>
      <PageHeader
        title={`${timeOfDay()}, ${firstName}`}
        description={
          data
            ? `${data.scope.label} · ${data.scope.description} · Updated ${data.updated_at}`
            : loading
              ? 'Loading your book…'
              : 'The dashboard could not be loaded.'
        }
        actions={
          <div className="flex flex-wrap items-center justify-end gap-2">
            <div
              className="inline-flex rounded-md border border-border bg-card p-0.5"
              role="group"
              aria-label="Reporting period"
            >
              {DASHBOARD_RANGES.map((option) => {
                const active = range === option.value
                return (
                  <button
                    key={option.value}
                    type="button"
                    aria-pressed={active}
                    disabled={loading}
                    onClick={() => setRange(option.value)}
                    className={`rounded px-2.5 py-1 text-xs font-medium transition-colors disabled:opacity-60 ${
                      active
                        ? 'bg-primary text-primary-foreground'
                        : 'text-muted-foreground hover:text-foreground'
                    }`}
                  >
                    {option.label}
                  </button>
                )
              })}
            </div>
          </div>
        }
      />

      {error && (
        <AlertItem variant="danger" title="Dashboard unavailable">
          <div className="flex flex-wrap items-center justify-between gap-3">
            <p>{error}</p>
            <button
              type="button"
              onClick={() => setReloadKey((key) => key + 1)}
              className="rounded-md border border-border bg-card px-3 py-1.5 text-xs font-medium text-foreground hover:bg-secondary"
            >
              Try again
            </button>
          </div>
        </AlertItem>
      )}

      {loading && !data ? (
        <p
          role="status"
          className="rounded-lg border border-border bg-card p-8 text-center text-sm text-muted-foreground"
        >
          Loading the dashboard…
        </p>
      ) : null}

      {data && (
        <>
          <section aria-label="Headline numbers" className="flex flex-col gap-4">
            <div className="grid grid-cols-2 gap-4 lg:grid-cols-4">
              {data.primary.map((kpi) => (
                <StatCard
                  key={kpi.id}
                  label={kpi.label}
                  value={kpi.value}
                  domain={kpi.domain}
                  icon={kpiIcons[kpi.id]}
                  hint={kpi.hint}
                  trend={kpi.trend}
                  to={kpi.to}
                />
              ))}
            </div>
            <div className="grid grid-cols-2 gap-4 lg:grid-cols-4">
              {data.secondary.map((kpi) => (
                <StatCard
                  key={kpi.id}
                  label={kpi.label}
                  value={kpi.value}
                  domain={kpi.domain}
                  icon={kpiIcons[kpi.id]}
                  hint={kpi.hint}
                  trend={kpi.trend}
                  to={kpi.to}
                />
              ))}
            </div>
          </section>

          <section aria-label="Charts" className="grid grid-cols-1 gap-6 lg:grid-cols-3">
            <ChartCard
              className="lg:col-span-2"
              title="Acquisition & conversion"
              description="Leads captured, qualified and won over the period."
              action={
                <Link
                  to="/leads"
                  className="inline-flex items-center gap-1 text-xs font-medium text-primary hover:underline"
                >
                  Leads
                  <ArrowRight className="h-3.5 w-3.5" aria-hidden />
                </Link>
              }
              footer={<p className="text-xs text-muted-foreground">{data.line.caption}</p>}
            >
              <LineChart labels={data.line.labels} series={data.line.series} />
            </ChartCard>

            <ChartCard
              title="Open pipeline"
              description="Where the money is sitting right now."
              action={
                <Link
                  to="/pipeline"
                  className="inline-flex items-center gap-1 text-xs font-medium text-primary hover:underline"
                >
                  Board
                  <ArrowUpRight className="h-3.5 w-3.5" aria-hidden />
                </Link>
              }
              footer={<p className="text-xs text-muted-foreground">{data.donut.caption}</p>}
            >
              {data.donut.segments.length > 0 ? (
                <DonutChart
                  segments={data.donut.segments}
                  total={data.donut.total}
                  ariaLabel="Open pipeline by stage"
                />
              ) : (
                <p className="py-10 text-center text-sm text-muted-foreground">
                  No open deals yet — the pipeline is clear.
                </p>
              )}
            </ChartCard>
          </section>

          <AiReportPanel report={data.ai} />

          <section aria-label="Needs attention" className="grid grid-cols-1 gap-6 lg:grid-cols-3">
            <div className="rounded-lg border border-border bg-card lg:col-span-2">
              <div className="flex flex-wrap items-center justify-between gap-2 border-b border-border p-4">
                <div>
                  <h3 className="text-sm font-semibold text-foreground">Needs attention</h3>
                  <p className="mt-0.5 text-xs text-muted-foreground">
                    {data.worklist.length} items in view · every row opens its worklist
                  </p>
                </div>
                <Link
                  to="/follow-ups"
                  className="inline-flex items-center gap-1 text-xs font-medium text-primary hover:underline"
                >
                  Open follow-ups
                  <ArrowRight className="h-3.5 w-3.5" aria-hidden />
                </Link>
              </div>
              <DataTable
                columns={worklistColumns}
                rows={data.worklist}
                rowKey={(row) => row.id}
                caption="Needs-attention worklist"
                empty={
                  <p className="p-6 text-center text-sm text-muted-foreground">
                    Nothing needs attention — the book is clear.
                  </p>
                }
              />
            </div>

            <div className="flex flex-col gap-6">
              <section
                aria-labelledby="dashboard-alerts-title"
                className="rounded-lg border border-border bg-card p-4"
              >
                <h3 id="dashboard-alerts-title" className="text-sm font-semibold text-foreground">
                  Alerts
                </h3>
                <div className="mt-3 flex flex-col gap-2">
                  {data.alerts.length > 0 ? (
                    data.alerts.map((alert) => (
                      <AlertItem key={alert.id} variant={alert.variant} title={alert.title}>
                        <p>{alert.body}</p>
                        <Link
                          to={alert.to}
                          className="mt-1 inline-flex items-center gap-1 text-xs font-medium underline"
                        >
                          {alert.link_label}
                          <ArrowRight className="h-3 w-3" aria-hidden />
                        </Link>
                      </AlertItem>
                    ))
                  ) : (
                    <p className="text-sm text-muted-foreground">
                      No alerts — nothing needs escalation.
                    </p>
                  )}
                </div>
              </section>

              <section
                aria-labelledby="dashboard-activity-title"
                className="rounded-lg border border-border bg-card p-4"
              >
                <h3 id="dashboard-activity-title" className="text-sm font-semibold text-foreground">
                  Recent activity
                </h3>
                {data.activity.length > 0 ? (
                  <ul className="mt-3 flex flex-col gap-3">
                    {data.activity.map((item) => (
                      <li key={item.id} className="flex items-start gap-2">
                        <span
                          className={`mt-1.5 h-2 w-2 shrink-0 rounded-full ${itemTone[item.domain]}`}
                          aria-hidden
                        />
                        <div className="min-w-0 flex-1">
                          <Link
                            to={item.to}
                            className="text-sm font-medium text-foreground hover:text-primary"
                          >
                            {item.title}
                          </Link>
                          <p className="truncate text-xs text-muted-foreground">{item.detail}</p>
                        </div>
                        <span className="shrink-0 text-xs text-muted-foreground">{item.when}</span>
                      </li>
                    ))}
                  </ul>
                ) : (
                  <p className="mt-3 text-sm text-muted-foreground">
                    No recent activity in view yet.
                  </p>
                )}
              </section>
            </div>
          </section>

          <StaffPerformanceTable
            rows={data.staff}
            note={data.staff_note}
            caption={
              data.scope.tier === 'personal'
                ? 'Your scorecard'
                : data.scope.tier === 'team'
                  ? 'The team'
                  : 'Everyone'
            }
          />
        </>
      )}
    </div>
  )
}
