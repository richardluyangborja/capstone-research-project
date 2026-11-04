import { Link } from 'react-router-dom'
import { ArrowRight } from 'lucide-react'
import DataTable, { type DataTableColumn } from '../common/DataTable'
import StatusBadge, { type Tone } from '../common/StatusBadge'
import type {
  DashboardAccountRisk,
  DashboardCommercial,
  DashboardTeamRep,
  DashboardTeamWorkload,
  WorklistSeverity,
} from '../../lib/dashboard'

const severityTone: Record<WorklistSeverity, Tone> = {
  danger: 'danger',
  warning: 'warm',
  info: 'counts',
}

interface CommercialMetricProps {
  label: string
  value: string
  hint: string
  to: string
}

function CommercialMetric({ label, value, hint, to }: CommercialMetricProps) {
  return (
    <Link
      to={to}
      className="group flex flex-col gap-1 bg-card p-4 transition-colors hover:bg-secondary/60"
    >
      <span className="text-xs font-medium uppercase tracking-wide text-muted-foreground">
        {label}
      </span>
      <span className="text-lg font-semibold text-foreground group-hover:text-primary">
        {value}
      </span>
      <span className="text-xs text-muted-foreground">{hint}</span>
    </Link>
  )
}

interface ManagerDashboardBlocksProps {
  commercial: DashboardCommercial
  accountRisks: { total: number; rows: DashboardAccountRisk[] }
  teamWorkload: DashboardTeamWorkload
}

/**
 * The manager+ dashboard strip (module 01 refresh S14, spec 16 §Manager dashboard).
 *
 * Presentational only: the API already scoped and derived every row. A salesrep never receives
 * these blocks, so the page simply omits the component when they are absent.
 */
export default function ManagerDashboardBlocks({
  commercial,
  accountRisks,
  teamWorkload,
}: ManagerDashboardBlocksProps) {
  const repColumns: DataTableColumn<DashboardTeamRep>[] = [
    {
      key: 'rep',
      header: 'Rep',
      render: (rep) => (
        <div className="flex items-center gap-2">
          <span className="flex h-7 w-7 items-center justify-center rounded-full bg-people/15 text-xs font-semibold text-people">
            {rep.initials}
          </span>
          <span className="font-medium text-foreground">{rep.name}</span>
        </div>
      ),
    },
    {
      key: 'open-leads',
      header: 'Open leads',
      render: (rep) => rep.open_leads,
    },
    {
      key: 'overdue-follow-ups',
      header: 'Overdue follow-ups',
      render: (rep) =>
        rep.overdue_follow_ups > 0 ? (
          <StatusBadge tone="danger">{rep.overdue_follow_ups}</StatusBadge>
        ) : (
          <span className="text-muted-foreground">0</span>
        ),
    },
    {
      key: 'accounts',
      header: 'Accounts owned',
      render: (rep) => rep.accounts_owned,
    },
  ]

  return (
    <div className="flex flex-col gap-6">
      <section
        aria-labelledby="dashboard-commercial-title"
        className="rounded-lg border border-border bg-card"
      >
        <div className="flex flex-wrap items-start justify-between gap-2 p-4">
          <div>
            <h3 id="dashboard-commercial-title" className="text-sm font-semibold text-foreground">
              Commercial & delivery
            </h3>
            <p className="mt-0.5 text-xs text-muted-foreground">
              {commercial.source} · as of {commercial.as_of}
            </p>
          </div>
          <Link
            to="/billing"
            className="inline-flex items-center gap-1 text-xs font-medium text-primary hover:underline"
          >
            Open billing book
            <ArrowRight className="h-3.5 w-3.5" aria-hidden />
          </Link>
        </div>
        <div className="grid grid-cols-2 gap-px border-t border-border bg-border md:grid-cols-3 xl:grid-cols-6">
          <CommercialMetric
            label="AR outstanding"
            value={commercial.outstanding}
            hint="Finance · read-only"
            to="/billing"
          />
          <CommercialMetric
            label="AR overdue"
            value={commercial.overdue}
            hint={`${commercial.overdue_accounts} ${
              commercial.overdue_accounts === 1 ? 'account' : 'accounts'
            } overdue`}
            to="/billing"
          />
          <CommercialMetric
            label="Renewals due"
            value={commercial.renewals.map((window) => window.count).join(' / ')}
            hint="Inside 30 / 60 / 90 days"
            to="/contracts"
          />
          <CommercialMetric
            label="Active deployments"
            value={String(commercial.active_deployments)}
            hint="Deployment feed · read-only"
            to="/deployments"
          />
          <CommercialMetric
            label="Overall fill rate"
            value={commercial.fill_rate}
            hint="Active vs requested"
            to="/deployments"
          />
          <CommercialMetric
            label="Pending timesheets"
            value={String(commercial.pending_timesheets)}
            hint="Awaiting a decision"
            to="/deployments"
          />
        </div>
      </section>

      <section
        aria-labelledby="dashboard-risks-title"
        className="rounded-lg border border-border bg-card"
      >
        <div className="flex flex-wrap items-center justify-between gap-2 border-b border-border p-4">
          <div>
            <h3 id="dashboard-risks-title" className="text-sm font-semibold text-foreground">
              Account risks
            </h3>
            <p className="mt-0.5 text-xs text-muted-foreground">
              {accountRisks.total} warning or critical {accountRisks.total === 1 ? 'flag' : 'flags'}{' '}
              in view · highest severity first
            </p>
          </div>
          <Link
            to="/accounts"
            className="inline-flex items-center gap-1 text-xs font-medium text-primary hover:underline"
          >
            Account book
            <ArrowRight className="h-3.5 w-3.5" aria-hidden />
          </Link>
        </div>

        {accountRisks.rows.length > 0 ? (
          <ul className="divide-y divide-border">
            {accountRisks.rows.map((row) => (
              <li key={row.id} className="flex flex-wrap items-start justify-between gap-3 p-4">
                <div className="min-w-0 flex-1">
                  <div className="flex flex-wrap items-center gap-2">
                    <StatusBadge tone={severityTone[row.severity]}>{row.flag}</StatusBadge>
                    <Link
                      to={row.account_to}
                      className="font-medium text-foreground hover:text-primary"
                    >
                      {row.account.name}
                    </Link>
                    <span className="text-xs text-muted-foreground">{row.account.reference}</span>
                  </div>
                  <p className="mt-1 text-sm text-muted-foreground">{row.reason}</p>
                  <p className="mt-1 text-xs text-muted-foreground">
                    {row.source} · Owner {row.owner}
                  </p>
                </div>
                <Link
                  to={row.to}
                  className="inline-flex items-center gap-1 self-center text-xs font-medium text-primary hover:underline"
                >
                  {row.action}
                  <ArrowRight className="h-3 w-3" aria-hidden />
                </Link>
              </li>
            ))}
          </ul>
        ) : (
          <p className="p-6 text-center text-sm text-muted-foreground">
            No warning or critical account risks in view.
          </p>
        )}
        {accountRisks.total > accountRisks.rows.length && (
          <p className="border-t border-border px-4 py-3 text-xs text-muted-foreground">
            Showing the top {accountRisks.rows.length} of {accountRisks.total}. Open the Account
            Book to see every account.
          </p>
        )}
      </section>

      <section
        aria-labelledby="dashboard-workload-title"
        className="rounded-lg border border-border bg-card"
      >
        <div className="flex flex-wrap items-center justify-between gap-2 border-b border-border p-4">
          <div>
            <h3 id="dashboard-workload-title" className="text-sm font-semibold text-foreground">
              Team workload
            </h3>
            <p className="mt-0.5 text-xs text-muted-foreground">
              Open leads, overdue follow-ups and accounts owned, per rep.
            </p>
          </div>
          <Link
            to="/leads"
            className="inline-flex items-center gap-1 text-xs font-medium text-primary hover:underline"
          >
            Lead workspace
            <ArrowRight className="h-3.5 w-3.5" aria-hidden />
          </Link>
        </div>

        <div className="flex flex-wrap items-center justify-between gap-2 border-b border-border bg-secondary/40 px-4 py-3">
          <div>
            <p className="text-xs font-medium uppercase tracking-wide text-muted-foreground">
              Shared unassigned queue
            </p>
            <p className="mt-0.5 text-sm text-foreground">
              <span className="text-lg font-semibold">{teamWorkload.unassigned}</span>{' '}
              {teamWorkload.unassigned === 1 ? 'lead is' : 'leads are'} waiting for an owner.
            </p>
          </div>
          <Link
            to="/leads?view=unassigned"
            className="inline-flex items-center gap-1 text-xs font-medium text-primary hover:underline"
          >
            Open the queue
            <ArrowRight className="h-3 w-3" aria-hidden />
          </Link>
        </div>

        <div className="p-4">
          <DataTable
            columns={repColumns}
            rows={teamWorkload.reps}
            rowKey={(rep) => rep.id}
            caption="Team workload by rep"
            empty={
              <p className="rounded-lg border border-border p-6 text-center text-sm text-muted-foreground">
                No salesreps in this scope yet.
              </p>
            }
          />
        </div>
      </section>
    </div>
  )
}
