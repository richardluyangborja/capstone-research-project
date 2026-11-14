import { useCallback, useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { RefreshCw, Search, Users } from 'lucide-react'
import AlertItem from '../components/common/AlertItem'
import DataTable, { type DataTableColumn } from '../components/common/DataTable'
import EmptyState from '../components/common/EmptyState'
import PageHeader from '../components/common/PageHeader'
import StatCard from '../components/common/StatCard'
import StatusBadge from '../components/common/StatusBadge'
import { formatCurrency } from '../lib/format'
import { listEmployees, type EmployeeRange, type EmployeeRow } from '../lib/employees'

const rangeLabels: Record<EmployeeRange, string> = {
  '30d': 'Last 30 days',
  quarter: 'Quarter',
  ytd: 'Year to date',
}

const roleLabels: Record<EmployeeRow['role'], string> = {
  salesrep: 'Sales representative',
  manager: 'Sales manager',
}

interface Filters {
  search: string
  role: string
  range: EmployeeRange
}

export default function Employees() {
  const [employees, setEmployees] = useState<EmployeeRow[]>([])
  const [search, setSearch] = useState('')
  const [role, setRole] = useState('')
  const [range, setRange] = useState<EmployeeRange>('30d')
  const [page, setPage] = useState(1)
  const [lastPage, setLastPage] = useState(1)
  const [total, setTotal] = useState(0)
  const [core2Available, setCore2Available] = useState(true)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')

  const load = useCallback(async (targetPage: number, filters: Filters) => {
    setLoading(true)
    setError('')
    try {
      const response = await listEmployees({
        search: filters.search || undefined,
        role: filters.role || undefined,
        range: filters.range,
        page: targetPage,
        per_page: 20,
      })
      setEmployees(response.data)
      setPage(response.meta.current_page)
      setLastPage(response.meta.last_page)
      setTotal(response.meta.total)
      setCore2Available(response.meta.core2_available)
    } catch {
      setError('Could not load employee profiles. Please try again.')
    } finally {
      setLoading(false)
    }
  }, [])

  useEffect(() => {
    void load(1, { search: '', role: '', range: '30d' })
  }, [load])

  const filters: Filters = { search, role, range }
  const columns: DataTableColumn<EmployeeRow>[] = [
    {
      key: 'employee',
      header: 'Employee',
      render: (employee) => (
        <Link
          to={`/employees/${employee.id}`}
          className="font-medium text-primary hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
        >
          {employee.name}
        </Link>
      ),
    },
    {
      key: 'role',
      header: 'Role',
      render: (employee) => <StatusBadge tone="people">{roleLabels[employee.role]}</StatusBadge>,
    },
    {
      key: 'team',
      header: 'Team',
      className: 'text-muted-foreground',
      render: (employee) => employee.team?.name ?? 'Unassigned',
    },
    {
      key: 'won',
      header: 'Won revenue',
      headerClassName: 'text-right',
      className: 'text-right font-medium text-cost',
      render: (employee) => formatCurrency(employee.performance.won_value),
    },
    {
      key: 'quota',
      header: 'Quota attainment',
      headerClassName: 'text-right',
      className: 'text-right text-muted-foreground',
      render: (employee) => employee.quota_attainment === null
        ? <span title="Quota is zero; attainment is not defined">—</span>
        : <span>{employee.quota_attainment}%</span>,
    },
    {
      key: 'attendance',
      header: 'Attendance',
      headerClassName: 'text-right',
      className: 'text-right',
      render: (employee) => {
        const value = employee.attendance?.attendance_rate
        if (value === undefined) return <span className="text-muted-foreground">—</span>
        return <span className={value >= 95 ? 'text-people' : 'text-cost'}>{value}%</span>
      },
    },
  ]

  const roleOptions: { value: string; label: string }[] = [
    { value: '', label: 'All roles' },
    { value: 'salesrep', label: 'Sales representatives' },
    { value: 'manager', label: 'Managers' },
  ]

  return (
    <div className="space-y-6">
      <PageHeader
        title="Employees"
        description="CRM commercial output alongside read-only Core 2 attendance projections."
      />

      <div className="flex flex-wrap items-center justify-between gap-2 text-xs text-muted-foreground">
        <span className="inline-flex items-center gap-1.5">
          <Users className="h-3.5 w-3.5" aria-hidden="true" />
          Read-only workforce directory · scope is enforced by the API
        </span>
        <span>Core 2 HR, Attendance &amp; Payroll · read-only</span>
      </div>

      {!core2Available && (
        <AlertItem variant="info">
          Core 2 HR Service Unavailable. Attendance figures are not available; CRM performance is unaffected.
        </AlertItem>
      )}
      {error && (
        <div className="flex flex-wrap items-center justify-between gap-3">
          <AlertItem variant="danger">{error}</AlertItem>
          <button
            type="button"
            onClick={() => void load(page, filters)}
            className="inline-flex items-center gap-2 rounded-md border border-border px-3 py-1.5 text-sm font-medium hover:bg-muted"
          >
            <RefreshCw className="h-3.5 w-3.5" aria-hidden="true" /> Retry
          </button>
        </div>
      )}

      <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
        <StatCard label="Employees in view" value={total} domain="counts" icon={Users} />
        <StatCard label="Sales representatives" value={employees.filter((item) => item.role === 'salesrep').length} domain="people" />
        <StatCard label="Reporting period" value={rangeLabels[range]} domain="activity" />
      </div>

      <form
        className="flex flex-wrap items-center gap-3 rounded-lg border border-border bg-card p-4"
        onSubmit={(event) => {
          event.preventDefault()
          void load(1, filters)
        }}
      >
        <div className="relative min-w-[14rem] flex-1">
          <Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" aria-hidden="true" />
          <input
            type="search"
            value={search}
            onChange={(event) => setSearch(event.target.value)}
            placeholder="Search employee name…"
            aria-label="Search employees"
            className="w-full rounded-md border border-input bg-background py-2 pl-9 pr-3 text-sm text-foreground outline-none focus:ring-2 focus:ring-ring"
          />
        </div>
        <select
          value={role}
          onChange={(event) => setRole(event.target.value)}
          aria-label="Filter by role"
          className="rounded-md border border-input bg-background px-3 py-2 text-sm text-foreground outline-none focus:ring-2 focus:ring-ring"
        >
          {roleOptions.map((option) => <option key={option.value} value={option.value}>{option.label}</option>)}
        </select>
        <select
          value={range}
          onChange={(event) => {
            const next = event.target.value as EmployeeRange
            setRange(next)
            void load(1, { ...filters, range: next })
          }}
          aria-label="Performance period"
          className="rounded-md border border-input bg-background px-3 py-2 text-sm text-foreground outline-none focus:ring-2 focus:ring-ring"
        >
          {(Object.keys(rangeLabels) as EmployeeRange[]).map((value) => <option key={value} value={value}>{rangeLabels[value]}</option>)}
        </select>
        <button type="submit" className="rounded-md border border-border bg-background px-3 py-2 text-sm font-medium text-foreground hover:bg-muted">Filter</button>
      </form>

      <DataTable
        columns={columns}
        rows={employees}
        rowKey={(employee) => employee.id}
        loading={loading}
        loadingLabel="Loading employees…"
        caption="Employee directory with CRM performance and attendance"
        pagination={lastPage > 1 ? {
          currentPage: page,
          lastPage,
          total,
          onPageChange: (next) => void load(next, filters),
        } : undefined}
        empty={<EmptyState title="No employees found" description="Try changing your search or role filter." />}
      />
    </div>
  )
}
