import { ArrowRight, Users } from 'lucide-react'
import { Link } from 'react-router-dom'
import DataTable, { type DataTableColumn } from '../common/DataTable'
import type { StaffPerformanceRow } from '../../lib/dashboard'

interface StaffPerformanceTableProps {
  rows: StaffPerformanceRow[]
  note: string
  /** "Your scorecard" for a salesrep, "The team" for a manager, "Everyone" for an admin. */
  caption: string
}

const csatTone = (csat: number | null) => {
  if (csat === null) return 'text-muted-foreground'
  if (csat >= 4) return 'text-people'
  if (csat >= 3) return 'text-cost'
  return 'text-destructive'
}

const attendanceTone = (rate: number | null) => {
  if (rate === null) return 'text-muted-foreground'

  return rate >= 95 ? 'text-people' : 'text-cost'
}

/**
 * The staff scorecard (spec 14 §6): CRM output beside core2 HR figures. HR data is read through
 * the `HrGateway` mock (documented in spec 14 / ADR-0020); when it cannot answer, a field shows
 * `—` rather than a misleading `0`.
 */
export default function StaffPerformanceTable({ rows, note, caption }: StaffPerformanceTableProps) {
  const columns: DataTableColumn<StaffPerformanceRow>[] = [
    {
      key: 'person',
      header: 'Representative',
      render: (row) => (
        <div className="flex items-center gap-2">
          <span className="flex h-8 w-8 items-center justify-center rounded-full bg-people/15 text-xs font-semibold text-people">
            {row.initials}
          </span>
          <div className="flex flex-col">
            <span className="font-medium text-foreground">{row.name}</span>
            <span className="text-xs text-muted-foreground">
              {row.job_title} · {row.team}
            </span>
          </div>
        </div>
      ),
    },
    {
      key: 'leads',
      header: 'Leads',
      headerClassName: 'text-right',
      className: 'text-right',
      render: (row) => row.leads_handled,
    },
    {
      key: 'qualified',
      header: 'Qualified',
      headerClassName: 'text-right',
      className: 'text-right',
      render: (row) => row.qualified,
    },
    {
      key: 'won',
      header: 'Won',
      headerClassName: 'text-right',
      className: 'text-right',
      render: (row) => row.deals_won,
    },
    {
      key: 'won_value',
      header: 'Won value',
      headerClassName: 'text-right',
      className: 'text-right',
      render: (row) => <span className="font-medium text-cost">{row.won_value}</span>,
    },
    {
      key: 'conversion',
      header: 'Conversion',
      headerClassName: 'text-right',
      className: 'text-right',
      render: (row) => `${row.conversion_rate}%`,
    },
    {
      key: 'punctuality',
      header: 'On-time follow-ups',
      headerClassName: 'text-right',
      className: 'text-right',
      render: (row) => (
        <span className={row.follow_up_on_time >= 90 ? 'text-people' : 'text-cost'}>
          {row.follow_up_on_time}%
        </span>
      ),
    },
    {
      key: 'csat',
      header: 'CSAT',
      headerClassName: 'text-right',
      className: 'text-right',
      render: (row) => (
        <span className={csatTone(row.csat)}>{row.csat === null ? '—' : row.csat.toFixed(1)}</span>
      ),
    },
    {
      key: 'attendance',
      header: 'Attendance',
      headerClassName: 'text-right',
      className: 'text-right',
      render: (row) => (
        <span className={attendanceTone(row.attendance_rate)}>
          {row.attendance_rate === null ? '—' : `${row.attendance_rate}%`}
        </span>
      ),
    },
    {
      key: 'leave',
      header: 'Leave (days)',
      headerClassName: 'text-right',
      className: 'text-right',
      render: (row) => (row.leave_days === null ? '—' : row.leave_days),
    },
    {
      key: 'late',
      header: 'Late',
      headerClassName: 'text-right',
      className: 'text-right',
      render: (row) => {
        if (row.late_arrivals === null) return '—'
        if (row.late_arrivals === 0) return '0'

        return <span className="text-cost">{row.late_arrivals}</span>
      },
    },
  ]

  return (
    <section
      className="rounded-lg border border-border bg-card"
      aria-labelledby="staff-performance-title"
    >
      <div className="flex flex-wrap items-start justify-between gap-3 border-b border-border p-4">
        <div>
          <div className="flex items-center gap-2">
            <span className="rounded-md bg-people/15 p-1.5 text-people" aria-hidden>
              <Users className="h-4 w-4" />
            </span>
            <h3 id="staff-performance-title" className="text-sm font-semibold text-foreground">
              Staff performance
            </h3>
            <span className="rounded-full border border-people/40 bg-people/10 px-2 py-0.5 text-xs font-medium text-people">
              core2 · HR
            </span>
          </div>
          <p className="mt-1 text-xs text-muted-foreground">
            {caption} · CRM activity paired with core2 attendance and leave.
          </p>
        </div>
        <Link
          to="/assignment"
          className="inline-flex items-center gap-1 text-xs font-medium text-primary hover:underline"
        >
          Team workload
          <ArrowRight className="h-3.5 w-3.5" aria-hidden />
        </Link>
      </div>

      <div className="overflow-x-auto">
        <DataTable
          columns={columns}
          rows={rows}
          rowKey={(row) => row.id}
          caption="Staff performance: CRM output and core2 HR figures"
          empty={<p className="p-4 text-sm text-muted-foreground">No staff in view.</p>}
        />
      </div>

      <p className="border-t border-border px-4 py-3 text-xs text-muted-foreground">{note}</p>
    </section>
  )
}
