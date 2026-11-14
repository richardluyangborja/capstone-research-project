import { fireEvent, render, screen, within } from '@testing-library/react'
import { MemoryRouter } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import EmployeeLeaderboard from './EmployeeLeaderboard'
import { getEmployeeLeaderboard } from '../lib/employees'
import type { EmployeeDirectoryResponse } from '../lib/employees'

vi.mock('../lib/employees', () => ({ getEmployeeLeaderboard: vi.fn() }))

const response: EmployeeDirectoryResponse = {
  data: [
    {
      id: 'employee-1', name: 'Sam Salesrep', role: 'salesrep', team: { id: 'team-1', name: 'Sales' }, profile: null, quota: { monthly: 500000, quarterly: 1500000 }, quota_attainment: null, can_update_quota: false,
      performance: { leads_handled: 5, deals_won: 2, won_value: 1200000, conversion_rate: 50, open_pipeline_count: 1, open_pipeline_value: 500000, follow_up_on_time: 100 },
      attendance: { attendance_rate: 97, late_arrivals_count: 1 },
    },
    {
      id: 'employee-2', name: 'Liza Salesrep', role: 'salesrep', team: { id: 'team-1', name: 'Sales' }, profile: null, quota: { monthly: 500000, quarterly: 1500000 }, quota_attainment: null, can_update_quota: false,
      performance: { leads_handled: 3, deals_won: 1, won_value: 600000, conversion_rate: 50, open_pipeline_count: 2, open_pipeline_value: 900000, follow_up_on_time: 80 },
      attendance: { attendance_rate: 90, late_arrivals_count: 3 },
    },
    {
      id: 'employee-3', name: 'Maria Manager', role: 'manager', team: { id: 'team-1', name: 'Sales' }, profile: null, quota: { monthly: 500000, quarterly: 1500000 }, quota_attainment: null, can_update_quota: false,
      performance: { leads_handled: 0, deals_won: 0, won_value: 0, conversion_rate: 0, open_pipeline_count: 0, open_pipeline_value: 0, follow_up_on_time: 100 },
      attendance: { attendance_rate: 98, late_arrivals_count: 0 },
    },
  ],
  links: { first: null, last: null, prev: null, next: null },
  meta: { current_page: 1, last_page: 1, per_page: 100, total: 3, source: 'Core 2 HR, Attendance & Payroll (read-only)', read_only: true, as_of: '2026-11-15T00:00:00Z', core2_available: true, range: '30d' },
}

const mockedLeaderboard = vi.mocked(getEmployeeLeaderboard)

describe('Employee leaderboard', () => {
  beforeEach(() => {
    mockedLeaderboard.mockReset().mockResolvedValue(response)
  })

  it('shows revenue, attendance, and honest unavailable quota quadrants', async () => {
    render(<MemoryRouter><EmployeeLeaderboard /></MemoryRouter>)
    const sam = await screen.findByRole('link', { name: 'Sam Salesrep' })
    const row = sam.closest('tr') as HTMLElement
    expect(within(row).getByText('₱1,200,000.00')).toBeInTheDocument()
    expect(within(row).getByText('97% · 1 late')).toBeInTheDocument()
    expect(within(row).getByText('Quota data unavailable')).toBeInTheDocument()
    expect(screen.getByRole('status')).toHaveTextContent(/not assigned to performance quadrants/i)
  })

  it('allows sorting by attendance and requests the selected period', async () => {
    render(<MemoryRouter><EmployeeLeaderboard /></MemoryRouter>)
    await screen.findByRole('link', { name: 'Sam Salesrep' })
    fireEvent.click(screen.getByRole('button', { name: 'Attendance ↕' }))
    const selector = screen.getByRole('combobox', { name: 'Leaderboard period' })
    fireEvent.change(selector, { target: { value: 'quarter' } })
    expect(mockedLeaderboard).toHaveBeenLastCalledWith('quarter')
  })

  it('shows retryable access and load errors', async () => {
    mockedLeaderboard.mockRejectedValueOnce({ response: { status: 403 } })
    render(<MemoryRouter><EmployeeLeaderboard /></MemoryRouter>)
    expect(await screen.findByText(/available to managers and administrators/i)).toBeInTheDocument()
    expect(screen.getByRole('button', { name: /retry/i })).toBeInTheDocument()
  })
})
