import { useCallback, useEffect, useMemo, useState } from 'react'
import { Link } from 'react-router-dom'
import { AlertTriangle, RefreshCw, Trophy } from 'lucide-react'
import AlertItem from '../components/common/AlertItem'
import DataTable, { type DataTableColumn } from '../components/common/DataTable'
import EmptyState from '../components/common/EmptyState'
import PageHeader from '../components/common/PageHeader'
import StatCard from '../components/common/StatCard'
import StatusBadge from '../components/common/StatusBadge'
import { formatCurrency } from '../lib/format'
import { getEmployeeLeaderboard, type EmployeeRange, type EmployeeRow } from '../lib/employees'

type SortKey = 'revenue' | 'attendance'
type Quadrant = 'top' | 'coaching' | 'burnout' | 'critical' | 'unrated'

const ranges: Record<EmployeeRange, string> = { '30d': 'Last 30 days', quarter: 'Quarter', ytd: 'Year to date' }
const quadrantLabels: Record<Quadrant, string> = {
  top: 'Top Performers',
  coaching: 'Coaching Candidates',
  burnout: 'Burnout Risks',
  critical: 'Critical PIP',
  unrated: 'Quota data unavailable',
}

interface BoardEmployee extends EmployeeRow {
  quadrant: Quadrant
}

export default function EmployeeLeaderboard() {
  const [employees, setEmployees] = useState<EmployeeRow[]>([])
  const [range, setRange] = useState<EmployeeRange>('30d')
  const [sort, setSort] = useState<SortKey>('revenue')
  const [core2Available, setCore2Available] = useState(true)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')

  const load = useCallback(async (selectedRange: EmployeeRange) => {
    setLoading(true)
    setError('')
    try {
      const response = await getEmployeeLeaderboard(selectedRange)
      setEmployees(response.data)
      setCore2Available(response.meta.core2_available)
    } catch (requestError) {
      const status = (requestError as { response?: { status?: number } }).response?.status
      setError(status === 403 ? 'The employee leaderboard is available to managers and administrators.' : 'Could not load the employee leaderboard. Please try again.')
    } finally {
      setLoading(false)
    }
  }, [])

  useEffect(() => { void load(range) }, [load, range])

  const rows = useMemo<BoardEmployee[]>(() => employees
    .filter((employee) => employee.role === 'salesrep')
    .map((employee) => ({ ...employee, quadrant: 'unrated' })), [employees])

  const ranked = useMemo(() => [...rows].sort((left, right) => {
    if (sort === 'attendance') return (right.attendance?.attendance_rate ?? -1) - (left.attendance?.attendance_rate ?? -1)
    return right.performance.won_value - left.performance.won_value
  }), [rows, sort])

  const columns: DataTableColumn<BoardEmployee>[] = [
    {
      key: 'employee',
      header: 'Representative',
      render: (employee) => <Link to={`/employees/${employee.id}`} className="font-medium text-primary hover:underline">{employee.name}</Link>,
    },
    {
      key: 'revenue',
      header: <button type="button" onClick={() => setSort('revenue')} className="hover:text-foreground">Won revenue ↕</button>,
      headerClassName: 'text-right',
      className: 'text-right font-medium text-cost',
      render: (employee) => formatCurrency(employee.performance.won_value),
    },
    {
      key: 'attendance',
      header: <button type="button" onClick={() => setSort('attendance')} className="hover:text-foreground">Attendance ↕</button>,
      headerClassName: 'text-right',
      className: 'text-right',
      render: (employee) => employee.attendance ? <span className={employee.attendance.attendance_rate >= 95 ? 'text-people' : 'text-cost'}>{employee.attendance.attendance_rate}% · {employee.attendance.late_arrivals_count} late</span> : '—',
    },
    {
      key: 'quota',
      header: 'Quota attainment',
      className: 'text-right text-muted-foreground',
      render: () => '—',
    },
    {
      key: 'quadrant',
      header: 'Classification',
      render: (employee) => <StatusBadge tone="neutral">{quadrantLabels[employee.quadrant]}</StatusBadge>,
    },
  ]

  const attendance = rows.filter((employee) => employee.attendance !== null)
  const averageAttendance = attendance.length === 0 ? null : attendance.reduce((sum, employee) => sum + (employee.attendance?.attendance_rate ?? 0), 0) / attendance.length

  return (
    <div className="space-y-6">
      <PageHeader title="Discipline vs. Output" description="Compare closed-won commercial output with read-only attendance performance across the team." />

      {!core2Available && <AlertItem variant="info" title="Core 2 HR Service Unavailable">Attendance figures are unavailable and excluded from reliability comparisons.</AlertItem>}
      {error && <div className="flex flex-wrap items-center justify-between gap-3"><AlertItem variant="danger">{error}</AlertItem><button type="button" onClick={() => void load(range)} className="inline-flex items-center gap-2 rounded-md border border-border px-3 py-2 text-sm font-medium hover:bg-muted"><RefreshCw className="h-4 w-4" aria-hidden="true" />Retry</button></div>}

      <div className="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-border bg-card p-4">
        <div><p className="text-sm font-medium text-foreground">Reporting period</p><p className="mt-1 text-xs text-muted-foreground">CRM closed-won value and Core 2 attendance are read-time projections.</p></div>
        <select value={range} onChange={(event) => setRange(event.target.value as EmployeeRange)} aria-label="Leaderboard period" className="rounded-md border border-input bg-background px-3 py-2 text-sm text-foreground focus:outline-none focus:ring-2 focus:ring-ring">{(Object.keys(ranges) as EmployeeRange[]).map((value) => <option key={value} value={value}>{ranges[value]}</option>)}</select>
      </div>

      <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
        <StatCard label="Sales representatives" value={rows.length} domain="counts" icon={Trophy} />
        <StatCard label="Average attendance" value={averageAttendance === null ? '—' : `${averageAttendance.toFixed(1)}%`} domain="people" />
        <StatCard label="Quota attainment" value="—" domain="activity" hint="No quota source configured yet" />
      </div>

      <section className="space-y-3" aria-labelledby="matrix-title">
        <div className="flex flex-wrap items-center justify-between gap-3"><h3 id="matrix-title" className="font-semibold text-foreground">Performance quadrant</h3><span className="text-xs text-muted-foreground">Attendance benchmark: 95% · quota axis unavailable</span></div>
        <div className="grid gap-3 sm:grid-cols-2">
          <QuadrantPanel title="Top Performers" subtitle="High attendance · high quota" tone="people" employees={[]} />
          <QuadrantPanel title="Coaching Candidates" subtitle="Lower attendance · high quota" tone="cost" employees={[]} />
          <QuadrantPanel title="Burnout Risks" subtitle="High attendance · lower quota" tone="activity" employees={[]} />
          <QuadrantPanel title="Critical PIP" subtitle="Lower attendance · lower quota" tone="danger" employees={[]} />
        </div>
        <div className="rounded-lg border border-dashed border-border bg-card p-4 text-sm text-muted-foreground" role="status">
          <div className="flex items-center gap-2 font-medium text-foreground"><AlertTriangle className="h-4 w-4 text-cost" aria-hidden="true" /> Quadrant classification unavailable</div>
          <p className="mt-1">Quota attainment cannot be calculated until the approved monthly quota source is delivered. Employees are not assigned to performance quadrants using guessed values.</p>
        </div>
      </section>

      <section className="space-y-3" aria-labelledby="ranking-title">
        <div className="flex flex-wrap items-center justify-between gap-3"><h3 id="ranking-title" className="font-semibold text-foreground">Team ranking</h3><span className="text-xs text-muted-foreground">Sorted by {sort === 'revenue' ? 'won revenue' : 'attendance'}</span></div>
        <DataTable columns={columns} rows={ranked} rowKey={(employee) => employee.id} loading={loading} loadingLabel="Loading leaderboard…" caption="Employee ranking by closed-won revenue and attendance" empty={<EmptyState title="No team performance yet" description="Representatives appear here when they are in your authorized team scope." />} />
      </section>

      <p className="text-xs text-muted-foreground">Source: CRM opportunity records and Core 2 HR, Attendance &amp; Payroll (read-only). PHP currency. Staff data is scoped by the API.</p>
    </div>
  )
}

function QuadrantPanel({ title, subtitle, tone, employees }: { title: string; subtitle: string; tone: 'people' | 'cost' | 'activity' | 'danger'; employees: BoardEmployee[] }) {
  const markerStyles = {
    people: 'border-people/40 bg-people/10',
    cost: 'border-cost/40 bg-cost/10',
    activity: 'border-activity/40 bg-activity/10',
    danger: 'border-destructive/40 bg-destructive/10',
  }
  return (
    <div className={`relative min-h-36 rounded-lg border p-4 ${markerStyles[tone]}`}>
      <h4 className="text-sm font-semibold text-foreground">{title}</h4>
      <p className="mt-1 text-xs text-muted-foreground">{subtitle}</p>
      <div className="mt-3 flex flex-wrap gap-2">
        {employees.map((employee) => <Link key={employee.id} to={`/employees/${employee.id}`} title={`${employee.name} · ${formatCurrency(employee.performance.won_value)} won · attendance ${employee.attendance?.attendance_rate ?? '—'}%`} className="rounded-full border border-border bg-card px-2.5 py-1 text-xs font-medium text-foreground hover:border-primary">{employee.name}</Link>)}
      </div>
    </div>
  )
}
