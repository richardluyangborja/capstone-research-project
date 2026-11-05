import { fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import { MemoryRouter } from 'react-router-dom'
import { describe, expect, it, vi } from 'vitest'
import Deployments from './Deployments'
import * as deliveryApi from '../lib/deliveries'
import type { DeploymentBook } from '../types'

const { book } = vi.hoisted(() => {
  const book: DeploymentBook = {
    data: [
      {
        reference: 'DEP-2026-0001',
        contract_id: 'contract-1',
        contract_reference: 'CON-2026-0001',
        role: 'Warehouse associate',
        site: 'Manila South Harbor office',
        status: 'active',
        active_headcount: 4,
        requested_headcount: 4,
        start_date: '2026-10-01',
        end_date: '2027-10-01',
        fill_rate: 100,
        timesheet: {
          period_start: '2026-10-01',
          period_end: '2026-10-31',
          approved_hours: 704,
          pending_hours: 0,
          last_submitted_at: '2026-10-29T08:00:00+00:00',
          status: 'current',
        },
        operations_owner: 'Deployment operations (mocked)',
        last_updated_at: '2026-10-31T08:00:00+00:00',
        company: {
          id: 'company-1',
          reference: 'CO-2026-0001',
          name: 'Harbor Logistics Corp',
        },
        owner: { id: 1, name: 'Sam Salesrep' },
      },
    ],
    meta: {
      current_page: 1,
      per_page: 25,
      total: 1,
      last_page: 1,
      source: 'Deployment / Timekeeping (mocked, read-only)',
      read_only: true,
      as_of: '2026-10-31T08:00:00+00:00',
    },
    summary: {
      total: 1,
      active: 1,
      pending: 0,
      ended: 0,
      fill_rate: 100,
      deployments_pending: 0,
      pending_timesheets: 0,
      approved_hours: 704,
      pending_hours: 0,
    },
  }

  return { book }
})

vi.mock('../lib/deliveries', () => ({
  listDeployments: vi.fn().mockResolvedValue(book),
}))

vi.mock('../lib/companies', () => ({
  listCompanies: vi.fn().mockResolvedValue({
    data: [{ id: 'company-1', name: 'Harbor Logistics Corp' }],
    meta: { current_page: 1, last_page: 1, total: 1 },
  }),
}))

vi.mock('../lib/contracts', () => ({
  listContracts: vi.fn().mockResolvedValue({
    data: [
      {
        id: 'contract-1',
        reference: 'CON-2026-0001',
        title: 'Warehouse associate staffing agreement',
        company: { id: 'company-1', name: 'Harbor Logistics Corp' },
      },
    ],
  }),
}))

vi.mock('../lib/leads', () => ({
  getAssignableUsers: vi.fn().mockResolvedValue([{ id: 1, name: 'Sam Salesrep', team_id: null }]),
}))

vi.mock('../lib/auth-context', () => ({
  useAuth: () => ({
    user: { id: 1, name: 'Admin User', role: 'admin' },
  }),
}))

describe('Deployment Book (/deployments)', () => {
  it('renders the read-only feed, summary cards and joined account rows', async () => {
    render(
      <MemoryRouter>
        <Deployments />
      </MemoryRouter>,
    )

    expect(await screen.findByText('DEP-2026-0001')).toBeInTheDocument()
    expect(
      screen.getByText('Read-only feed — no deployment or timesheet can be changed here'),
    ).toBeInTheDocument()
    expect(screen.getByText(/Deployment \/ Timekeeping \(mocked, read-only\)/)).toBeInTheDocument()
    expect(screen.getByText('Total deployments')).toBeInTheDocument()
    expect(screen.getByText('Overall fill rate')).toBeInTheDocument()
    expect(screen.getByText('704 hrs approved · 0 hrs pending · deployment count')).toBeInTheDocument()

    const table = screen.getByRole('table')
    expect(within(table).getByText('Harbor Logistics Corp')).toBeInTheDocument()
    expect(within(table).getByText('Sam Salesrep')).toBeInTheDocument()
    expect(within(table).getByText('CON-2026-0001')).toBeInTheDocument()
    expect(within(table).getByText('Warehouse associate')).toBeInTheDocument()
    expect(within(table).getByText('Active')).toBeInTheDocument()
    expect(within(table).getByText('Current')).toBeInTheDocument()
    expect(within(table).getByText('100% filled')).toBeInTheDocument()

    expect(screen.queryByRole('button', { name: /complete|approve|edit/i })).not.toBeInTheDocument()
  })

  it('sends the search term to the feed', async () => {
    render(
      <MemoryRouter>
        <Deployments />
      </MemoryRouter>,
    )

    await screen.findByText('DEP-2026-0001')

    fireEvent.change(
      screen.getByPlaceholderText('Search deployment, role, site, account or contract…'),
      { target: { value: 'Harbor' } },
    )

    await waitFor(() => {
      expect(deliveryApi.listDeployments).toHaveBeenLastCalledWith(
        expect.objectContaining({ search: 'Harbor' }),
      )
    })
  })

  it('filters by status, account, contract and account owner', async () => {
    render(
      <MemoryRouter>
        <Deployments />
      </MemoryRouter>,
    )

    await screen.findByText('DEP-2026-0001')

    fireEvent.change(screen.getByLabelText('Filter by deployment status'), {
      target: { value: 'active' },
    })

    await waitFor(() => {
      expect(deliveryApi.listDeployments).toHaveBeenLastCalledWith(
        expect.objectContaining({ status: 'active' }),
      )
    })

    fireEvent.change(screen.getByLabelText('Filter by account'), {
      target: { value: 'company-1' },
    })

    await waitFor(() => {
      expect(deliveryApi.listDeployments).toHaveBeenLastCalledWith(
        expect.objectContaining({ status: 'active', company: 'company-1' }),
      )
    })

    fireEvent.change(screen.getByLabelText('Filter by contract'), {
      target: { value: 'contract-1' },
    })

    await waitFor(() => {
      expect(deliveryApi.listDeployments).toHaveBeenLastCalledWith(
        expect.objectContaining({ company: 'company-1', contract: 'contract-1' }),
      )
    })

    fireEvent.change(screen.getByLabelText('Filter by account owner'), { target: { value: '1' } })

    await waitFor(() => {
      expect(deliveryApi.listDeployments).toHaveBeenLastCalledWith(
        expect.objectContaining({ contract: 'contract-1', owner: '1' }),
      )
    })
  })
})
