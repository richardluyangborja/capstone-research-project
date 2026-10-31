import { useCallback, useEffect, useState } from 'react'
import { Briefcase, Clock, Lock, RefreshCw, Truck, Users } from 'lucide-react'
import DataTable, { type DataTableColumn } from './DataTable'
import EmptyState from './EmptyState'
import FormCard from './FormCard'
import StatCard from './StatCard'
import StatusBadge, { type Tone } from './StatusBadge'
import { getCompanyDelivery } from '../../lib/deliveries'
import { formatDate, formatDateTime } from '../../lib/format'
import type {
  CompanyDelivery,
  DeliveryDeployment,
  DeliveryJobOrder,
  DeliveryPriority,
  DeploymentStatus,
  JobOrderStatus,
  TimesheetStatus,
} from '../../types'

interface CompanyDeliveryPanelProps {
  companyId: string
  /** Changes when the Service handoff is retried, so the feed is read again. */
  refreshKey?: string | number
}

const handoffMeta: Record<'sent' | 'failed' | 'none', { label: string; tone: Tone }> = {
  sent: { label: 'Accepted', tone: 'success' },
  failed: { label: 'Failed', tone: 'danger' },
  none: { label: 'Not requested', tone: 'neutral' },
}

const jobOrderMeta: Record<JobOrderStatus, { label: string; tone: Tone }> = {
  open: { label: 'Open', tone: 'counts' },
  partially_filled: { label: 'Partially filled', tone: 'warm' },
  filled: { label: 'Filled', tone: 'success' },
  closed: { label: 'Closed', tone: 'neutral' },
  cancelled: { label: 'Cancelled', tone: 'danger' },
}

const priorityMeta: Record<DeliveryPriority, { label: string; tone: Tone }> = {
  high: { label: 'High', tone: 'hot' },
  medium: { label: 'Medium', tone: 'warm' },
  low: { label: 'Low', tone: 'cold' },
}

const deploymentMeta: Record<DeploymentStatus, { label: string; tone: Tone }> = {
  pending: { label: 'Pending', tone: 'warm' },
  active: { label: 'Active', tone: 'success' },
  ended: { label: 'Ended', tone: 'neutral' },
}

const timesheetMeta: Record<TimesheetStatus, { label: string; tone: Tone }> = {
  current: { label: 'Current', tone: 'success' },
  pending: { label: 'Pending', tone: 'warm' },
  missing: { label: 'Missing', tone: 'danger' },
}

const formatHours = (hours: number): string =>
  `${new Intl.NumberFormat('en-PH', { maximumFractionDigits: 2 }).format(hours)} hrs`

const jobOrderColumns: DataTableColumn<DeliveryJobOrder>[] = [
  {
    key: 'reference',
    header: 'Reference',
    className: 'font-mono text-xs text-muted-foreground',
    render: (order) => order.reference,
  },
  {
    key: 'role',
    header: 'Role',
    render: (order) => <span className="font-medium text-foreground">{order.role}</span>,
  },
  {
    key: 'fill',
    header: 'Filled',
    className: 'text-right',
    headerClassName: 'text-right',
    render: (order) => `${order.filled} / ${order.requested}`,
  },
  {
    key: 'status',
    header: 'Status',
    render: (order) => (
      <StatusBadge tone={jobOrderMeta[order.status].tone}>
        {jobOrderMeta[order.status].label}
      </StatusBadge>
    ),
  },
  {
    key: 'priority',
    header: 'Priority',
    render: (order) => (
      <StatusBadge tone={priorityMeta[order.priority].tone}>
        {priorityMeta[order.priority].label}
      </StatusBadge>
    ),
  },
  {
    key: 'target',
    header: 'Target start',
    className: 'text-muted-foreground',
    render: (order) => formatDate(order.target_start_date) || '—',
  },
  {
    key: 'recruiter',
    header: 'Recruiter',
    className: 'text-muted-foreground',
    render: (order) => order.recruiter ?? '—',
  },
]

const deploymentColumns: DataTableColumn<DeliveryDeployment>[] = [
  {
    key: 'reference',
    header: 'Reference',
    render: (deployment) => (
      <div className="flex flex-col">
        <span className="font-mono text-xs text-foreground">{deployment.reference}</span>
        <span className="font-mono text-xs text-muted-foreground">
          {deployment.contract_reference}
        </span>
      </div>
    ),
  },
  {
    key: 'role',
    header: 'Role',
    render: (deployment) => (
      <div className="flex flex-col">
        <span className="font-medium text-foreground">{deployment.role}</span>
        <span className="text-xs text-muted-foreground">{deployment.site}</span>
        {deployment.operations_owner && (
          <span className="text-xs text-muted-foreground">{deployment.operations_owner}</span>
        )}
      </div>
    ),
  },
  {
    key: 'status',
    header: 'Status',
    render: (deployment) => (
      <StatusBadge tone={deploymentMeta[deployment.status].tone}>
        {deploymentMeta[deployment.status].label}
      </StatusBadge>
    ),
  },
  {
    key: 'headcount',
    header: 'Active / requested',
    className: 'text-right',
    headerClassName: 'text-right',
    render: (deployment) => `${deployment.active_headcount} / ${deployment.requested_headcount}`,
  },
  {
    key: 'fill',
    header: 'Fill',
    className: 'text-right',
    headerClassName: 'text-right',
    render: (deployment) => `${deployment.fill_rate}%`,
  },
  {
    key: 'term',
    header: 'Term',
    className: 'text-muted-foreground',
    render: (deployment) => (
      <span className="text-xs">
        {formatDate(deployment.start_date) || '—'} – {formatDate(deployment.end_date) || '—'}
      </span>
    ),
  },
  {
    key: 'timesheet',
    header: 'Timesheet',
    render: (deployment) =>
      deployment.timesheet === null ? (
        <span className="text-xs text-muted-foreground">—</span>
      ) : (
        <div className="flex flex-col gap-0.5">
          <StatusBadge tone={timesheetMeta[deployment.timesheet.status].tone}>
            {timesheetMeta[deployment.timesheet.status].label}
          </StatusBadge>
          <span className="text-xs text-muted-foreground">
            {formatHours(deployment.timesheet.approved_hours)} approved
          </span>
          {deployment.timesheet.pending_hours > 0 && (
            <span className="text-xs text-muted-foreground">
              {formatHours(deployment.timesheet.pending_hours)} pending
            </span>
          )}
        </div>
      ),
  },
]

/**
 * The Account 360 Delivery tab (module 01 refresh S9, spec 16 §Read-only external feeds).
 * It reads Recruitment, Deployment and Timekeeping through one endpoint and prints the
 * source/as-of line; feed data has no create/edit/delete action anywhere in this panel.
 */
export default function CompanyDeliveryPanel({
  companyId,
  refreshKey = '',
}: CompanyDeliveryPanelProps) {
  const [delivery, setDelivery] = useState<CompanyDelivery | null>(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')

  const load = useCallback(async () => {
    setLoading(true)
    setError('')

    try {
      setDelivery(await getCompanyDelivery(companyId))
    } catch {
      setError('Could not load the delivery feed.')
    } finally {
      setLoading(false)
    }
  }, [companyId])

  useEffect(() => {
    void load()
  }, [load, refreshKey])

  if (loading) {
    return (
      <div className="rounded-lg border border-border bg-card p-6 text-sm text-muted-foreground">
        Loading the delivery feed…
      </div>
    )
  }

  if (error || delivery === null) {
    return (
      <div className="rounded-lg border border-destructive/30 bg-destructive/10 p-6">
        <p className="text-sm font-medium text-destructive">
          {error || 'The feed is unavailable.'}
        </p>
        <p className="mt-1 text-xs text-muted-foreground">
          Recruitment, Deployment and Timekeeping are read-only feeds, so there is nothing to edit
          here.
        </p>
        <button
          type="button"
          onClick={() => void load()}
          className="mt-3 inline-flex items-center gap-2 rounded-md border border-border px-3 py-1.5 text-xs font-medium text-foreground hover:bg-muted"
        >
          <RefreshCw className="h-3.5 w-3.5" aria-hidden="true" />
          Retry feed
        </button>
      </div>
    )
  }

  const { data, meta } = delivery
  const handoff = data.handoff.status === null ? 'none' : data.handoff.status
  const activeDeployments = data.deployments.filter(
    (deployment) => deployment.status === 'active',
  ).length
  const filledOrders = data.job_orders.filter((order) => order.status === 'filled').length
  const requestedHeads = data.deployments.reduce(
    (total, deployment) => total + deployment.requested_headcount,
    0,
  )
  const activeHeads = data.deployments.reduce(
    (total, deployment) => total + deployment.active_headcount,
    0,
  )
  const overallFill = requestedHeads === 0 ? 0 : Math.round((activeHeads / requestedHeads) * 100)

  return (
    <div className="space-y-6">
      <div className="flex flex-wrap items-center justify-between gap-2 text-xs text-muted-foreground">
        <span className="inline-flex items-center gap-1.5">
          <Lock className="h-3.5 w-3.5" aria-hidden="true" />
          Read-only feed
        </span>
        <span>
          {meta.source} · As of {formatDateTime(meta.as_of)}
        </span>
      </div>

      <div className="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-4">
        <StatCard
          label="Service handoff"
          value={handoffMeta[handoff].label}
          domain={handoff === 'sent' ? 'people' : handoff === 'failed' ? 'cost' : 'counts'}
          icon={Truck}
          hint={data.handoff.reference ?? 'No handoff reference yet'}
        />
        <StatCard
          label="Job orders"
          value={data.job_orders.length}
          domain="counts"
          icon={Briefcase}
          hint={`${filledOrders} filled`}
        />
        <StatCard
          label="Active deployments"
          value={activeDeployments}
          domain="people"
          icon={Users}
          hint={`${overallFill}% overall fill · ${data.deployments.length} total`}
        />
        <StatCard
          label="Timesheets pending"
          value={data.timesheets.deployments_pending}
          domain="activity"
          icon={Clock}
          hint={`${formatHours(data.timesheets.approved_hours)} approved · ${formatHours(
            data.timesheets.pending_hours,
          )} pending`}
        />
      </div>

      {data.handoff.status === 'failed' && (
        <div className="rounded-lg border border-destructive/30 bg-destructive/10 p-4 text-sm text-destructive">
          {data.handoff.error ?? 'The Service desk did not acknowledge the handoff.'}{' '}
          <span className="text-xs">
            The signed contract stands; retry the handoff from the banner above.
          </span>
        </div>
      )}

      <FormCard
        title="Job orders"
        description="From Recruitment (mocked) — read-only. A placement line is a one-time fee, not a person to deploy."
      >
        {data.job_orders.length === 0 ? (
          <EmptyState
            title="No job orders yet"
            description="A job order is raised once the account has a signed agreement and the Service handoff is accepted."
          />
        ) : (
          <DataTable
            columns={jobOrderColumns}
            rows={data.job_orders}
            rowKey={(order) => order.reference}
            empty={<p className="text-sm text-muted-foreground">No job orders on this account.</p>}
          />
        )}
      </FormCard>

      <FormCard
        title="Deployments & timesheets"
        description="From Deployment and Timekeeping (mocked) — read-only. Hours are a period summary, never employee-level rows."
      >
        {data.deployments.length === 0 ? (
          <EmptyState
            title="No deployments yet"
            description="Deployments appear after a successful handoff. The CRM never creates a deployment or approves hours."
          />
        ) : (
          <DataTable
            columns={deploymentColumns}
            rows={data.deployments}
            rowKey={(deployment) => deployment.reference}
            empty={<p className="text-sm text-muted-foreground">No deployments on this account.</p>}
          />
        )}
      </FormCard>
    </div>
  )
}
