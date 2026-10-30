import { fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import { MemoryRouter } from 'react-router-dom'
import { describe, expect, it, vi } from 'vitest'
import Contracts from './Contracts'
import * as contractApi from '../lib/contracts'
import type { Contract, ContractsResponse } from '../types'

const { mockContract, mockResponse } = vi.hoisted(() => {
  const contract: Contract = {
    id: 'contract-1',
    reference: 'CON-2026-0001',
    company_id: 'company-1',
    company: {
      id: 'company-1',
      reference: 'CO-2026-0001',
      name: 'Harbor Logistics Corp',
      status: 'active',
    },
    opportunity_id: null,
    renewal_of_contract_id: null,
    renewal_of: null,
    title: 'Warehouse associate staffing agreement',
    status: 'signed',
    start_date: '2026-01-01',
    end_date: '2026-12-31',
    signed_at: '2026-01-01T08:00:00Z',
    value: '2400000.00',
    currency: 'PHP',
    payment_terms: 'Net 30',
    po_reference: 'PO-9981',
    auto_renew: true,
    notice_days: 60,
    signatory_name: 'John Doe',
    signatory_position: 'VP Operations',
    terms: 'Net 30 terms',
    notes: 'Standard renewal terms',
    rate_cards: [
      {
        id: 'rc-1',
        contract_id: 'contract-1',
        site_id: null,
        role_title: 'Forklift Operator',
        headcount: 5,
        billing_unit: 'hourly',
        bill_rate: '200.00',
        pay_rate: '150.00',
        units_per_period: '176.00',
        notes: null,
        position: 1,
        line_client_monthly: '176000.00',
        line_internal_monthly: '132000.00',
        line_placement_fee: '0.00',
        can_view_margin: true,
      },
    ],
    rate_cards_count: 1,
    commercial: {
      client_monthly_value: '176000.00',
      internal_monthly_cost: '132000.00',
      gross_margin: '44000.00',
      gross_margin_percent: 25,
      estimated_total_value: '2112000.00',
      can_view_margin: true,
    },
    file: {
      present: true,
      name: 'harbor-contract.pdf',
      size: 204800,
      mime: 'application/pdf',
    },
    days_until_expiry: 64,
    is_expiring_soon: false,
    is_signed: true,
    is_active: true,
    owner: { id: 1, name: 'Sam Salesrep' },
    owner_id: 1,
    allowed_transitions: ['expired', 'terminated'],
    can_update: true,
    can_mark_signed: false,
    can_delete: true,
    can_manage_rate_cards: true,
    created_at: '2026-01-01T08:00:00Z',
    updated_at: '2026-01-01T08:00:00Z',
  }

  const response: ContractsResponse = {
    data: [contract],
    meta: {
      current_page: 1,
      from: 1,
      last_page: 1,
      path: '/api/v1/contracts',
      per_page: 25,
      to: 1,
      total: 1,
    },
    summary: {
      all: 1,
      active: 1,
      draft: 0,
      sent: 0,
      expiring: 0,
      renewals: 0,
    },
  }

  return { mockContract: contract, mockResponse: response }
})

vi.mock('../lib/contracts', () => ({
  listContracts: vi.fn().mockImplementation(() => Promise.resolve(mockResponse)),
  downloadContractFile: vi.fn().mockResolvedValue(undefined),
}))

vi.mock('../lib/companies', () => ({
  listCompanies: vi.fn().mockResolvedValue({
    data: [{ id: 'company-1', name: 'Harbor Logistics Corp' }],
    meta: { current_page: 1, last_page: 1, total: 1 },
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

describe('Contracts Book (/contracts)', () => {
  it('renders the contracts list, summary cards and table rows', async () => {
    render(
      <MemoryRouter>
        <Contracts />
      </MemoryRouter>,
    )

    expect(await screen.findByText('Contracts')).toBeInTheDocument()
    expect(screen.getByText('Warehouse associate staffing agreement')).toBeInTheDocument()
    expect(screen.getByText('CON-2026-0001')).toBeInTheDocument()
    expect(within(screen.getByRole('table')).getByText('Harbor Logistics Corp')).toBeInTheDocument()
    expect(within(screen.getByRole('table')).getByText('Signed')).toBeInTheDocument()
    expect(screen.getByText('₱2,400,000.00')).toBeInTheDocument()
    expect(within(screen.getByRole('table')).getByText('1 role')).toBeInTheDocument()
    expect(within(screen.getByRole('table')).getByText('Sam Salesrep')).toBeInTheDocument()
  })

  it('triggers search when user types in search input', async () => {
    render(
      <MemoryRouter>
        <Contracts />
      </MemoryRouter>,
    )

    await screen.findByText('CON-2026-0001')

    const searchInput = screen.getByPlaceholderText('Search reference, title, account…')
    fireEvent.change(searchInput, { target: { value: 'Harbor' } })

    await waitFor(() => {
      expect(contractApi.listContracts).toHaveBeenCalledWith(
        expect.objectContaining({ search: 'Harbor' }),
      )
    })
  })

  it('filters the book by account and owner', async () => {
    render(
      <MemoryRouter>
        <Contracts />
      </MemoryRouter>,
    )

    await screen.findByText('CON-2026-0001')

    fireEvent.change(screen.getByLabelText('Filter by account'), {
      target: { value: 'company-1' },
    })

    await waitFor(() => {
      expect(contractApi.listContracts).toHaveBeenLastCalledWith(
        expect.objectContaining({ company: 'company-1' }),
      )
    })

    fireEvent.change(screen.getByLabelText('Filter by owner'), { target: { value: '1' } })

    await waitFor(() => {
      expect(contractApi.listContracts).toHaveBeenLastCalledWith(
        expect.objectContaining({ company: 'company-1', owner: '1' }),
      )
    })
  })

  it('downloads the attached contract file when clicked', async () => {
    render(
      <MemoryRouter>
        <Contracts />
      </MemoryRouter>,
    )

    await screen.findByText('CON-2026-0001')

    const downloadBtn = screen.getByTitle('Download signed document')
    fireEvent.click(downloadBtn)

    expect(contractApi.downloadContractFile).toHaveBeenCalledWith(mockContract)
  })
})
