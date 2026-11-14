import { fireEvent, render, screen, within } from '@testing-library/react'
import { MemoryRouter } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import Employees from './Employees'
import { listEmployees } from '../lib/employees'
import type { EmployeeDirectoryResponse } from '../lib/employees'

vi.mock('../lib/employees', () => ({ listEmployees: vi.fn() }))

const response: EmployeeDirectoryResponse = {
  data: [
    {
      id: '01JEMPLOYEEPUBLICID000000001',
      name: 'Sam Salesrep',
      role: 'salesrep',
      team: { id: '01JTEAMID000000000000000001', name: 'Business Development' },
      profile: null,
      quota: { monthly: 500000, quarterly: 500000 },
      quota_attainment: 250,
      can_update_quota: false,
      performance: {
        leads_handled: 4,
        deals_won: 2,
        won_value: 1250000,
        conversion_rate: 50,
        open_pipeline_count: 3,
        open_pipeline_value: 800000,
        follow_up_on_time: 90,
      },
      attendance: { attendance_rate: 96, late_arrivals_count: 1 },
    },
  ],
  links: { first: null, last: null, prev: null, next: null },
  meta: {
    current_page: 1,
    last_page: 1,
    per_page: 20,
    total: 1,
    source: 'Core 2 HR, Attendance & Payroll (read-only)',
    read_only: true,
    as_of: '2026-11-15T00:00:00Z',
    core2_available: true,
    range: '30d',
  },
}

const mockedListEmployees = vi.mocked(listEmployees)

const renderPage = () => render(<MemoryRouter><Employees /></MemoryRouter>)

describe('Employee directory', () => {
  beforeEach(() => {
    mockedListEmployees.mockReset()
    mockedListEmployees.mockResolvedValue(response)
  })

  it('shows employee, role, team, won revenue, quota placeholder and attendance with a profile link', async () => {
    renderPage()

    const name = await screen.findByRole('link', { name: 'Sam Salesrep' })
    expect(name).toHaveAttribute('href', '/employees/01JEMPLOYEEPUBLICID000000001')
    const row = name.closest('tr') as HTMLElement
    expect(within(row).getByText('Sales representative')).toBeInTheDocument()
    expect(within(row).getByText('Business Development')).toBeInTheDocument()
    expect(within(row).getByText('₱1,250,000.00')).toBeInTheDocument()
    expect(within(row).getByText('96%')).toBeInTheDocument()
    expect(within(row).getByText('250%')).toBeInTheDocument()
  })

  it('sends search, role and period filters to the API', async () => {
    renderPage()
    await screen.findByRole('link', { name: 'Sam Salesrep' })

    fireEvent.change(screen.getByRole('searchbox', { name: 'Search employees' }), { target: { value: 'Sam' } })
    fireEvent.change(screen.getByRole('combobox', { name: 'Filter by role' }), { target: { value: 'salesrep' } })
    fireEvent.change(screen.getByRole('combobox', { name: 'Performance period' }), { target: { value: 'quarter' } })

    expect(mockedListEmployees).toHaveBeenLastCalledWith({ search: 'Sam', role: 'salesrep', range: 'quarter', page: 1, per_page: 20 })
  })

  it('shows an unavailable banner and em dashes when Core 2 has no data', async () => {
    mockedListEmployees.mockResolvedValue({
      ...response,
      data: [{ ...response.data[0], attendance: null }],
      meta: { ...response.meta, core2_available: false },
    })
    renderPage()

    expect(await screen.findByText(/Core 2 HR Service Unavailable/)).toBeInTheDocument()
    const name = await screen.findByRole('link', { name: 'Sam Salesrep' })
    const row = name.closest('tr') as HTMLElement
    expect(within(row).getAllByText('—')).toHaveLength(1)
  })
})
