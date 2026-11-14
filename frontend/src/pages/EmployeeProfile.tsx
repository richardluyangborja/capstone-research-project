import { useCallback, useEffect, useState } from 'react'
import { Link, useParams } from 'react-router-dom'
import { ArrowLeft, CalendarClock, Clock3, RefreshCw, UserRound } from 'lucide-react'
import AlertItem from '../components/common/AlertItem'
import EmptyState from '../components/common/EmptyState'
import PageHeader from '../components/common/PageHeader'
import StatCard from '../components/common/StatCard'
import StatusBadge from '../components/common/StatusBadge'
import { formatCurrency, formatDateTime } from '../lib/format'
import {
  getEmployee,
  getEmployeeAppraisals,
  getEmployeeAttendance,
  getEmployeeCompensation,
  getEmployeePerformance,
  updateEmployeeQuota,
  type EmployeeAppraisal,
  type EmployeeAttendance,
  type EmployeeCompensation,
  type EmployeePerformance,
  type EmployeeRange,
  type EmployeeRow,
} from '../lib/employees'

type Tab = 'overview' | 'performance' | 'attendance' | 'appraisals' | 'compensation'

const tabs: { id: Tab; label: string }[] = [
  { id: 'overview', label: 'Overview' },
  { id: 'performance', label: 'CRM Performance' },
  { id: 'attendance', label: 'Core 2 Attendance' },
  { id: 'appraisals', label: 'HR Appraisals' },
  { id: 'compensation', label: 'Compensation' },
]

const rangeLabels: Record<EmployeeRange, string> = {
  '30d': 'Last 30 days',
  quarter: 'Quarter',
  ytd: 'Year to date',
}

const roleLabels: Record<EmployeeRow['role'], string> = {
  salesrep: 'Sales representative',
  manager: 'Sales manager',
}

const competencyLabel = (key: string) =>
  key.split('_').map((part) => `${part.charAt(0).toUpperCase()}${part.slice(1)}`).join(' ')

function Core2Badge() {
  return <StatusBadge tone="people">core2 · HR (read-only)</StatusBadge>
}

export default function EmployeeProfile() {
  const { id = '' } = useParams()
  const [employee, setEmployee] = useState<EmployeeRow | null>(null)
  const [performance, setPerformance] = useState<EmployeePerformance | null>(null)
  const [attendance, setAttendance] = useState<EmployeeAttendance | null>(null)
  const [appraisals, setAppraisals] = useState<EmployeeAppraisal[] | null>(null)
  const [compensation, setCompensation] = useState<EmployeeCompensation | null>(null)
  const [compensationUnavailable, setCompensationUnavailable] = useState(false)
  const [compensationForbidden, setCompensationForbidden] = useState(false)
  const [core2Available, setCore2Available] = useState(true)
  const [range, setRange] = useState<EmployeeRange>('30d')
  const [tab, setTab] = useState<Tab>('overview')
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')
  const [monthlyQuota, setMonthlyQuota] = useState('')
  const [quarterlyQuota, setQuarterlyQuota] = useState('')
  const [quotaSaving, setQuotaSaving] = useState(false)
  const [quotaMessage, setQuotaMessage] = useState('')
  const canManageQuota = employee?.can_update_quota ?? false

  const load = useCallback(async (selectedRange: EmployeeRange) => {
    setLoading(true)
    setError('')
    try {
      const [profileResponse, performanceResponse, attendanceResponse, appraisalResponse] = await Promise.all([
        getEmployee(id),
        getEmployeePerformance(id, selectedRange),
        getEmployeeAttendance(id, selectedRange),
        getEmployeeAppraisals(id),
      ])
      setEmployee(profileResponse.data)
      setMonthlyQuota(String(profileResponse.data.quota.monthly))
      setQuarterlyQuota(String(profileResponse.data.quota.quarterly))
      try {
        const compensationResponse = await getEmployeeCompensation(id, selectedRange)
        setCompensation(compensationResponse.data ? {
          ...compensationResponse.data,
          commission_rate_percent: compensationResponse.meta.commission_rate_percent,
          commission_base: compensationResponse.meta.commission_base,
          earned_commission: compensationResponse.meta.earned_commission,
        } : null)
        setCompensationUnavailable(!compensationResponse.meta.core2_available)
        setCompensationForbidden(false)
      } catch (compensationError) {
        const status = (compensationError as { response?: { status?: number } }).response?.status
        if (status === 403) {
          setCompensation(null)
          setCompensationUnavailable(false)
          setCompensationForbidden(true)
        } else {
          setCompensationUnavailable(true)
          setCompensationForbidden(false)
        }
      }
      setPerformance(performanceResponse.data)
      setAttendance(attendanceResponse.data)
      setAppraisals(appraisalResponse.data)
      setCore2Available(profileResponse.meta.core2_available && attendanceResponse.meta.core2_available && appraisalResponse.meta.core2_available)
    } catch (requestError) {
      const status = (requestError as { response?: { status?: number } }).response?.status
      setError(status === 403
        ? 'You do not have permission to view this employee profile or its HR sections.'
        : status === 404
          ? 'This employee could not be found in your directory scope.'
          : 'Could not load the employee profile. Please try again.')
    } finally {
      setLoading(false)
    }
  }, [id])

  useEffect(() => {
    void load(range)
  }, [id, load])

  const selectRange = (next: EmployeeRange) => {
    setRange(next)
    void load(next)
  }

  if (loading && employee === null) {
    return <div className="rounded-lg border border-border bg-card p-8 text-center text-sm text-muted-foreground">Loading employee profile…</div>
  }

  if (error && employee === null) {
    return (
      <div className="space-y-4">
        <AlertItem variant="danger">{error}</AlertItem>
        <button type="button" onClick={() => void load(range)} className="inline-flex items-center gap-2 rounded-md border border-border px-3 py-2 text-sm font-medium hover:bg-muted">
          <RefreshCw className="h-4 w-4" aria-hidden="true" /> Retry
        </button>
        <Link to="/employees" className="ml-3 text-sm text-primary hover:underline">Back to Employees</Link>
      </div>
    )
  }

  if (employee === null) return null
  const profile = employee.profile
  const currentPerformance = performance ?? employee.performance

  return (
    <div className="space-y-6">
      <Link to="/employees" className="inline-flex items-center gap-2 text-sm font-medium text-primary hover:underline">
        <ArrowLeft className="h-4 w-4" aria-hidden="true" /> Employees
      </Link>

      <PageHeader
        title={employee.name}
        description={`${roleLabels[employee.role]} · ${employee.team?.name ?? 'Unassigned'}`}
        actions={<Core2Badge />}
      />

      <section className="rounded-lg border border-border bg-card p-5" aria-label="Core 2 employee details">
        <div className="flex flex-wrap items-start justify-between gap-4">
          <div className="flex items-start gap-3">
            <span className="flex h-11 w-11 items-center justify-center rounded-full bg-people/15 text-people">
              <UserRound className="h-5 w-5" aria-hidden="true" />
            </span>
            <div>
              <h3 className="font-semibold text-foreground">{profile?.official_title ?? roleLabels[employee.role]}</h3>
              <p className="mt-1 text-sm text-muted-foreground">{profile?.department ?? employee.team?.name ?? '—'}</p>
              <p className="mt-1 font-mono text-xs text-muted-foreground">{profile?.employee_reference ?? '—'}</p>
            </div>
          </div>
          <div className="grid gap-3 text-sm sm:grid-cols-3">
            <div><p className="text-xs text-muted-foreground">Employment status</p><p className="mt-1 capitalize text-foreground">{profile?.employment_status ?? '—'}</p></div>
            <div><p className="text-xs text-muted-foreground">Tenure</p><p className="mt-1 text-foreground">{profile ? `${profile.tenure_months} months` : '—'}</p></div>
            <div><p className="text-xs text-muted-foreground">Direct manager</p><p className="mt-1 text-foreground">{profile?.direct_manager ?? '—'}</p></div>
          </div>
        </div>
        <div className="mt-4 flex items-center gap-2 border-t border-border pt-4 text-sm text-muted-foreground">
          <Clock3 className="h-4 w-4" aria-hidden="true" /> Shift: {profile?.shift_schedule ?? '—'}
        </div>
      </section>

      {!core2Available && (
        <AlertItem variant="info" title="Core 2 HR Service Unavailable">
          HR profile, attendance or appraisal data could not be retrieved. CRM performance remains available; unavailable values are shown as —.
        </AlertItem>
      )}
      {error && <AlertItem variant="danger">{error}</AlertItem>}

      <div className="flex flex-wrap items-center justify-between gap-3 border-b border-border">
        <div className="flex flex-wrap gap-1" role="tablist" aria-label="Employee profile sections">
          {tabs.map((item) => (
            <button key={item.id} type="button" role="tab" aria-selected={tab === item.id} onClick={() => setTab(item.id)} className={`border-b-2 px-3 py-2 text-sm font-medium ${tab === item.id ? 'border-primary text-primary' : 'border-transparent text-muted-foreground hover:text-foreground'}`}>
              {item.label}
            </button>
          ))}
        </div>
        {(tab === 'overview' || tab === 'performance') && (
          <select value={range} onChange={(event) => selectRange(event.target.value as EmployeeRange)} aria-label="Performance period" className="mb-2 rounded-md border border-input bg-background px-3 py-2 text-sm text-foreground focus:outline-none focus:ring-2 focus:ring-ring">
            {(Object.keys(rangeLabels) as EmployeeRange[]).map((value) => <option key={value} value={value}>{rangeLabels[value]}</option>)}
          </select>
        )}
      </div>

      {tab === 'overview' && (
        <div className="space-y-4" role="tabpanel">
          <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <StatCard label="Leads handled" value={currentPerformance.leads_handled} domain="counts" />
            <StatCard label="Deals won" value={currentPerformance.deals_won} domain="people" />
            <StatCard label="Won revenue" value={formatCurrency(currentPerformance.won_value)} domain="cost" />
            <StatCard label="Attendance" value={attendance ? `${attendance.attendance_rate}%` : '—'} domain="people" />
          </div>
          <section className="rounded-lg border border-border bg-card p-4">
            <h3 className="font-semibold text-foreground">Quota targets</h3>
            <dl className="mt-3 grid grid-cols-2 gap-4 text-sm">
              <Metric label="Monthly quota" value={formatCurrency(employee.quota.monthly)} />
              <Metric label="Quarterly quota" value={formatCurrency(employee.quota.quarterly)} />
              <Metric label={`Attainment · ${rangeLabels[range]}`} value={employee.quota_attainment === null ? '—' : `${employee.quota_attainment}%`} />
            </dl>
            {canManageQuota && <form className="mt-4 grid gap-3 border-t border-border pt-4 sm:grid-cols-2" onSubmit={async (event) => {
              event.preventDefault()
              setQuotaSaving(true)
              setQuotaMessage('')
              try {
                const quota = await updateEmployeeQuota(id, { monthly_quota: Number(monthlyQuota), quarterly_quota: Number(quarterlyQuota) })
                setEmployee({ ...employee, quota })
                setQuotaMessage('Quota targets saved.')
                await load(range)
              } catch {
                setQuotaMessage('Could not save quota targets. Check the values and try again.')
              } finally {
                setQuotaSaving(false)
              }
            }}>
              <label className="text-sm text-foreground">Monthly quota (PHP)<input type="number" min="0" max="1000000000" step="0.01" value={monthlyQuota} onChange={(event) => setMonthlyQuota(event.target.value)} className="mt-1 w-full rounded-md border border-input bg-background px-3 py-2" required /></label>
              <label className="text-sm text-foreground">Quarterly quota (PHP)<input type="number" min="0" max="1000000000" step="0.01" value={quarterlyQuota} onChange={(event) => setQuarterlyQuota(event.target.value)} className="mt-1 w-full rounded-md border border-input bg-background px-3 py-2" required /></label>
              <button type="submit" disabled={quotaSaving} className="w-fit rounded-md bg-primary px-3 py-2 text-sm font-medium text-primary-foreground disabled:opacity-50">{quotaSaving ? 'Saving…' : 'Save quota'}</button>
              {quotaMessage && <p role="status" className="self-center text-sm text-muted-foreground">{quotaMessage}</p>}
            </form>}
          </section>
          <div className="grid gap-4 lg:grid-cols-2">
            <section className="rounded-lg border border-border bg-card p-4">
              <div className="mb-4 flex items-center justify-between"><h3 className="font-semibold text-foreground">CRM performance</h3><StatusBadge tone="activity">{rangeLabels[range]}</StatusBadge></div>
              <dl className="grid grid-cols-2 gap-4 text-sm">
                <Metric label="Open pipeline" value={`${currentPerformance.open_pipeline_count} deals`} />
                <Metric label="Pipeline value" value={formatCurrency(currentPerformance.open_pipeline_value)} />
                <Metric label="Closed-deal conversion" value={`${currentPerformance.conversion_rate}%`} />
                <Metric label="Follow-ups on time" value={`${currentPerformance.follow_up_on_time}%`} />
              </dl>
            </section>
            <section className="rounded-lg border border-border bg-card p-4">
              <div className="mb-4 flex items-center justify-between"><h3 className="font-semibold text-foreground">Attendance &amp; reliability</h3><Core2Badge /></div>
              {attendance ? <dl className="grid grid-cols-2 gap-4 text-sm">
                <Metric label="Days present" value={attendance.days_present} />
                <Metric label="Absences" value={attendance.absences_count} />
                <Metric label="Late arrivals" value={`${attendance.late_arrivals_count} · ${attendance.late_arrivals_minutes} min`} />
                <Metric label="Undertime" value={`${attendance.undertime_hours} hours`} />
              </dl> : <p className="text-sm text-muted-foreground">Attendance data: —</p>}
            </section>
          </div>
        </div>
      )}

      {tab === 'performance' && (
        <section className="space-y-4" role="tabpanel" aria-label="CRM performance">
          <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <StatCard label="Leads handled" value={currentPerformance.leads_handled} domain="counts" />
            <StatCard label="Deals won" value={currentPerformance.deals_won} domain="people" />
            <StatCard label="Won revenue" value={formatCurrency(currentPerformance.won_value)} domain="cost" />
            <StatCard label="Open pipeline" value={formatCurrency(currentPerformance.open_pipeline_value)} domain="cost" hint={`${currentPerformance.open_pipeline_count} open deals`} />
          </div>
          <section className="rounded-lg border border-border bg-card p-4">
            <h3 className="mb-4 font-semibold text-foreground">Activity and conversion · {rangeLabels[range]}</h3>
            <dl className="grid gap-4 sm:grid-cols-3 text-sm">
              <Metric label="Closed-deal conversion" value={`${currentPerformance.conversion_rate}%`} />
              <Metric label="Follow-ups on time" value={`${currentPerformance.follow_up_on_time}%`} />
              <Metric label="Leads handled" value={currentPerformance.leads_handled} />
            </dl>
          </section>
        </section>
      )}

      {tab === 'attendance' && (
        <section className="space-y-4" role="tabpanel" aria-label="Core 2 attendance">
          <div className="flex flex-wrap items-center justify-between gap-3"><h3 className="font-semibold text-foreground">Timekeeping summary · {rangeLabels[range]}</h3><Core2Badge /></div>
          {attendance ? <>
            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
              <StatCard label="Attendance rate" value={`${attendance.attendance_rate}%`} domain={attendance.attendance_rate >= 95 ? 'people' : 'cost'} />
              <StatCard label="Late arrivals" value={attendance.late_arrivals_count} domain="activity" hint={`${attendance.late_arrivals_minutes} minutes total`} />
              <StatCard label="Undertime" value={`${attendance.undertime_hours} hrs`} domain="cost" />
              <StatCard label="Absences" value={attendance.absences_count} domain="activity" />
            </div>
            <section className="rounded-lg border border-border bg-card p-4">
              <h3 className="mb-4 font-semibold text-foreground">Remaining leave balance</h3>
              <div className="grid gap-5 sm:grid-cols-3">
                <LeaveMeter label="Vacation leave" value={attendance.leave_balances.vacation_leave_remaining} max={15} />
                <LeaveMeter label="Sick leave" value={attendance.leave_balances.sick_leave_remaining} max={15} />
                <LeaveMeter label="Emergency leave" value={attendance.leave_balances.emergency_leave_remaining} max={5} />
              </div>
            </section>
            <p className="text-xs text-muted-foreground">{getProvenanceLine()}</p>
          </> : <EmptyState title="Attendance unavailable" description="Core 2 did not return timekeeping data for this period." />}
        </section>
      )}

      {tab === 'compensation' && (
        <section className="space-y-4" role="tabpanel" aria-label="Compensation">
          <div className="flex flex-wrap items-center justify-between gap-3">
            <h3 className="font-semibold text-foreground">Compensation &amp; salary</h3>
            <Core2Badge />
          </div>
          {compensationForbidden ? (
            <AlertItem variant="warning">Compensation is restricted to the employee, their authorized manager, and administrators.</AlertItem>
          ) : compensationUnavailable ? (
            <EmptyState title="Compensation unavailable" description="Core 2 did not return compensation data. Values are not estimated or replaced with zero." />
          ) : compensation ? (
            <>
              <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <StatCard
                  label="Base salary"
                  value={compensation.is_masked ? '₱ ••••••' : formatCurrency(compensation.base_salary)}
                  domain="cost"
                  hint={compensation.is_masked ? 'Hidden by manager salary policy' : 'Monthly · PHP'}
                />
                <StatCard
                  label="Fixed allowances"
                  value={compensation.is_masked ? '₱ ••••••' : formatCurrency(compensation.fixed_allowances)}
                  domain="cost"
                  hint={compensation.is_masked ? 'Hidden by manager salary policy' : 'Monthly · PHP'}
                />
              </div>
              <section className="rounded-lg border border-border bg-card p-4">
                <div className="flex flex-wrap items-start justify-between gap-3">
                  <div>
                    <h4 className="font-semibold text-foreground">CRM closed-won deal commissions</h4>
                    <p className="mt-1 text-sm text-muted-foreground">{currentPerformance.deals_won} deals · {formatCurrency(compensation.commission_base)} closed-won value in {rangeLabels[range]}</p>
                  </div>
                  <StatusBadge tone="people">{compensation.commission_rate_percent}% commission rate</StatusBadge>
                </div>
                <p className="mt-3 text-sm text-foreground">Earned CRM commission: <span className="font-semibold text-cost">{formatCurrency(compensation.earned_commission)}</span></p>
              </section>
              <section className="rounded-lg border border-border bg-card p-4">
                <h4 className="mb-4 font-semibold text-foreground">Recent payslips</h4>
                {compensation.recent_payslips.length === 0 ? (
                  <p className="text-sm text-muted-foreground">No payslip records were returned.</p>
                ) : (
                  <div className="overflow-x-auto">
                    <table className="w-full text-left text-sm">
                      <thead className="border-b border-border text-xs uppercase text-muted-foreground"><tr><th className="px-3 py-2">Period</th><th className="px-3 py-2 text-right">Gross pay</th><th className="px-3 py-2 text-right">Net pay</th><th className="px-3 py-2">Status</th></tr></thead>
                      <tbody>{compensation.recent_payslips.map((payslip) => <tr key={payslip.period} className="border-b border-border last:border-0"><td className="px-3 py-3">{payslip.period}</td><td className="px-3 py-3 text-right">{payslip.gross_pay === null ? '—' : formatCurrency(payslip.gross_pay)}</td><td className="px-3 py-3 text-right">{payslip.net_pay === null ? '—' : formatCurrency(payslip.net_pay)}</td><td className="px-3 py-3 capitalize">{payslip.status}</td></tr>)}</tbody>
                    </table>
                  </div>
                )}
              </section>
              <p className="text-xs text-muted-foreground">Source: Core 2 HR, Attendance &amp; Payroll (read-only). Compensation data is an external projection.</p>
            </>
          ) : (
            <EmptyState title="Compensation unavailable" description="No compensation data was returned." />
          )}
        </section>
      )}

      {tab === 'appraisals' && (
        <section className="space-y-4" role="tabpanel" aria-label="HR appraisals">
          <div className="flex items-center justify-between gap-3"><h3 className="font-semibold text-foreground">Formal performance appraisals</h3><Core2Badge /></div>
          {appraisals === null ? <EmptyState title="Appraisals unavailable" description="Core 2 did not return appraisal data." /> : appraisals.length === 0 ? <EmptyState title="No appraisals available" description="No formal appraisal records were returned." /> : appraisals.map((appraisal) => (
            <article key={appraisal.appraisal_id} className="space-y-4 rounded-lg border border-border bg-card p-5">
              <div className="flex flex-wrap items-start justify-between gap-3">
                <div><h4 className="font-semibold text-foreground">{appraisal.period_label}</h4><p className="mt-1 text-xs text-muted-foreground">{appraisal.appraisal_id} · {formatDateTime(appraisal.conducted_at)}</p></div>
                <StatusBadge tone={appraisal.overall_rating >= 4 ? 'people' : appraisal.overall_rating >= 3 ? 'cost' : 'danger'}>{appraisal.overall_rating.toFixed(1)} / 5</StatusBadge>
              </div>
              <div className="grid gap-4 sm:grid-cols-2">{Object.entries(appraisal.competencies).map(([key, value]) => <div key={key} className="rounded-md bg-muted/50 p-3"><div className="flex justify-between gap-3 text-sm"><span className="text-foreground">{competencyLabel(key)}</span><span className="font-semibold text-people">{value.toFixed(1)} / 5</span></div><div className="mt-2 h-2 overflow-hidden rounded-full bg-secondary"><div className="h-full rounded-full bg-people" style={{ width: `${Math.min(100, Math.max(0, value / 5 * 100))}%` }} /></div></div>)}</div>
              <div className="border-t border-border pt-3"><p className="text-xs font-medium text-muted-foreground">Evaluator notes</p><p className="mt-1 text-sm text-foreground">{appraisal.evaluator_notes || '—'}</p></div>
              <p className="text-xs text-muted-foreground">{getProvenanceLine()}</p>
            </article>
          ))}
        </section>
      )}

      <div className="flex items-center gap-2 text-xs text-muted-foreground"><CalendarClock className="h-3.5 w-3.5" aria-hidden="true" /> HR data is a read-only projection; Core 2 remains the system of record.</div>
    </div>
  )
}

function Metric({ label, value }: { label: string; value: string | number }) {
  return <div><dt className="text-xs text-muted-foreground">{label}</dt><dd className="mt-1 font-medium text-foreground">{value}</dd></div>
}

function LeaveMeter({ label, value, max }: { label: string; value: number; max: number }) {
  const percent = Math.min(100, Math.max(0, value / max * 100))
  return <div>
    <div className="flex justify-between gap-2 text-sm"><span className="text-foreground">{label}</span><span className="font-medium text-people">{value} days</span></div>
    <div className="mt-2 h-2 overflow-hidden rounded-full bg-secondary" role="progressbar" aria-label={label} aria-valuenow={value} aria-valuemin={0} aria-valuemax={max}><div className="h-full rounded-full bg-people" style={{ width: `${percent}%` }} /></div>
  </div>
}

function getProvenanceLine(): string {
  return 'Source: Core 2 HR, Attendance & Payroll (read-only)'
}
