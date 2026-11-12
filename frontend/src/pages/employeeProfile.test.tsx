import { fireEvent, render, screen } from '@testing-library/react'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import EmployeeProfile from './EmployeeProfile'
import type { EmployeeAppraisal, EmployeeAttendance, EmployeeDetailResponse, EmployeePerformance } from '../lib/employees'

vi.mock('../lib/employees', () => ({
  getEmployee: vi.fn(),
  getEmployeePerformance: vi.fn(),
  getEmployeeAttendance: vi.fn(),
  getEmployeeAppraisals: vi.fn(),
  getEmployeeCompensation: vi.fn(),
}))

import { getEmployee, getEmployeeAppraisals, getEmployeeAttendance, getEmployeeCompensation, getEmployeePerformance } from '../lib/employees'

const profile: EmployeeDetailResponse = {
  data: {
    id: '01JEMPLOYEEPUBLICID000000001',
    name: 'Sam Salesrep',
    role: 'salesrep',
    team: { id: 'team-1', name: 'Business Development' },
    profile: {
      employee_reference: 'EMP-2026-0042',
      official_title: 'Senior Account Executive',
      department: 'Business Development & Sales',
      employment_status: 'regular',
      tenure_months: 18,
      shift_schedule: '08:00 - 17:00 PHT, Mon-Fri',
      direct_manager: 'Maria Santos',
    },
    performance: { leads_handled: 4, deals_won: 2, won_value: 1250000, conversion_rate: 50, open_pipeline_count: 3, open_pipeline_value: 800000, follow_up_on_time: 90 },
    attendance: { attendance_rate: 96, late_arrivals_count: 1 },
  },
  meta: { source: 'Core 2 HR, Attendance & Payroll (read-only)', read_only: true, as_of: '2026-11-15T00:00:00Z', core2_available: true },
}
const performance: EmployeePerformance = profile.data.performance
const attendance: EmployeeAttendance = {
  user_id: profile.data.id,
  attendance_rate: 96,
  days_present: 21,
  late_arrivals_count: 2,
  late_arrivals_minutes: 18,
  undertime_hours: 1.5,
  absences_count: 1,
  leave_balances: { vacation_leave_remaining: 8, sick_leave_remaining: 6, emergency_leave_remaining: 2 },
}
const appraisals: EmployeeAppraisal[] = [{
  appraisal_id: 'APR-2026-Q3-0042',
  period_label: '2026 Q3 Performance Appraisal',
  overall_rating: 4.2,
  competencies: { client_negotiation: 4.5, attendance_punctuality: 4.0 },
  evaluator_notes: 'Strong client service and consistent follow-through.',
  conducted_at: '2026-10-15T01:00:00Z',
}]

const mockedGetEmployee = vi.mocked(getEmployee)
const mockedPerformance = vi.mocked(getEmployeePerformance)
const mockedAttendance = vi.mocked(getEmployeeAttendance)
const mockedAppraisals = vi.mocked(getEmployeeAppraisals)
const mockedCompensation = vi.mocked(getEmployeeCompensation)

const renderPage = () => render(
  <MemoryRouter initialEntries={[`/employees/${profile.data.id}`]}>
    <Routes><Route path="/employees/:id" element={<EmployeeProfile />} /></Routes>
  </MemoryRouter>,
)

describe('Employee 360 profile', () => {
  beforeEach(() => {
    mockedGetEmployee.mockReset().mockResolvedValue(profile)
    mockedPerformance.mockReset().mockResolvedValue({ data: performance, meta: { range: '30d' } })
    mockedAttendance.mockReset().mockResolvedValue({ data: attendance, meta: { source: profile.meta.source, read_only: true, as_of: profile.meta.as_of, core2_available: true, range: '30d' } })
    mockedAppraisals.mockReset().mockResolvedValue({ data: appraisals, meta: { source: profile.meta.source, read_only: true, as_of: profile.meta.as_of, core2_available: true } })
    mockedCompensation.mockReset().mockResolvedValue({ data: { user_id: profile.data.id, base_salary: 45000, fixed_allowances: 5000, is_masked: false, recent_payslips: [{ period: '2026-10', gross_pay: 50000, net_pay: 46000, status: 'released' }] }, meta: { source: profile.meta.source, read_only: true, as_of: profile.meta.as_of, core2_available: true } })
  })

  it('shows the Core 2 identity details and overview metrics', async () => {
    renderPage()
    expect(await screen.findByText('Senior Account Executive')).toBeInTheDocument()
    expect(screen.getByText('EMP-2026-0042')).toBeInTheDocument()
    expect(screen.getByText('18 months')).toBeInTheDocument()
    expect(screen.getAllByText('core2 · HR (read-only)').length).toBeGreaterThan(0)
    expect(screen.getByText('₱1,250,000.00')).toBeInTheDocument()
  })

  it('renders late arrivals, undertime and leave meters on the attendance tab', async () => {
    renderPage()
    await screen.findByText('Senior Account Executive')
    fireEvent.click(screen.getByRole('tab', { name: 'Core 2 Attendance' }))
    expect(screen.getByText('Late arrivals')).toBeInTheDocument()
    expect(screen.getByText('18 minutes total')).toBeInTheDocument()
    expect(screen.getByText('1.5 hrs')).toBeInTheDocument()
    expect(screen.getByText('8 days')).toBeInTheDocument()
    expect(screen.getByRole('progressbar', { name: 'Vacation leave' })).toHaveAttribute('aria-valuenow', '8')
  })

  it('renders appraisal competency ratings and evaluator notes', async () => {
    renderPage()
    await screen.findByText('Senior Account Executive')
    fireEvent.click(screen.getByRole('tab', { name: 'HR Appraisals' }))
    expect(screen.getByText('2026 Q3 Performance Appraisal')).toBeInTheDocument()
    expect(screen.getByText('Client Negotiation')).toBeInTheDocument()
    expect(screen.getByText('Strong client service and consistent follow-through.')).toBeInTheDocument()
  })

  it('shows graceful em-dash fallbacks and an unavailable banner when Core 2 is down', async () => {
    mockedGetEmployee.mockResolvedValue({ ...profile, data: { ...profile.data, profile: null, attendance: null }, meta: { ...profile.meta, core2_available: false } })
    mockedAttendance.mockResolvedValue({ data: null, meta: { source: profile.meta.source, read_only: true, as_of: profile.meta.as_of, core2_available: false, range: '30d' } })
    mockedAppraisals.mockResolvedValue({ data: null, meta: { source: profile.meta.source, read_only: true, as_of: profile.meta.as_of, core2_available: false } })
    renderPage()
    expect(await screen.findByText('Core 2 HR Service Unavailable')).toBeInTheDocument()
    expect(screen.getAllByText('—').length).toBeGreaterThan(0)
    fireEvent.click(screen.getByRole('tab', { name: 'Core 2 Attendance' }))
    expect(screen.getByText('Attendance unavailable')).toBeInTheDocument()
  })

  it('renders compensation, CRM won-deal summary and PHP payslips', async () => {
    renderPage()
    await screen.findByText('Senior Account Executive')
    fireEvent.click(screen.getByRole('tab', { name: 'Compensation' }))
    expect(screen.getByText('₱45,000.00')).toBeInTheDocument()
    expect(screen.getByText(/2 deals · ₱1,250,000.00 closed-won value/)).toBeInTheDocument()
    expect(screen.getByText('Commission rate unavailable')).toBeInTheDocument()
    expect(screen.getByText('₱50,000.00')).toBeInTheDocument()
    expect(screen.getByText('₱46,000.00')).toBeInTheDocument()
  })

  it('renders masked manager salary and keeps payslip amounts masked', async () => {
    mockedCompensation.mockResolvedValue({ data: { user_id: profile.data.id, base_salary: null, fixed_allowances: null, is_masked: true, recent_payslips: [{ period: '2026-10', gross_pay: null, net_pay: null, status: 'released' }] }, meta: { source: profile.meta.source, read_only: true, as_of: profile.meta.as_of, core2_available: true } })
    renderPage()
    await screen.findByText('Senior Account Executive')
    fireEvent.click(screen.getByRole('tab', { name: 'Compensation' }))
    expect(screen.getAllByText('₱ ••••••')).toHaveLength(2)
    expect(screen.getAllByText('—').length).toBeGreaterThan(0)
  })

  it('explains forbidden or out-of-scope employee profiles', async () => {
    mockedGetEmployee.mockRejectedValue({ response: { status: 403 } })
    renderPage()
    expect(await screen.findByText(/do not have permission/i)).toBeInTheDocument()
  })
})
