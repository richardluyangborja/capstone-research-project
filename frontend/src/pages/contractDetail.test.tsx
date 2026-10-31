import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { afterEach, describe, expect, it, vi } from 'vitest'
import ContractDetail from './ContractDetail'
import * as contractApi from '../lib/contracts'
import type { Contract } from '../types'

const { mockContract } = vi.hoisted(() => {
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
    renewals: [
      {
        id: 'contract-2',
        reference: 'CON-2027-0002',
        title: '2027 Staffing Extension',
        status: 'signed',
        start_date: '2027-01-01',
        end_date: '2027-12-31',
      },
    ],
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
    terms: 'Net 30 terms apply',
    notes: 'Standard contract notes',
    rate_cards: [
      {
        id: 'rc-1',
        contract_id: 'contract-1',
        site_id: 'site-1',
        site: { id: 'site-1', label: 'Manila HQ' },
        role_title: 'Forklift Operator',
        headcount: 5,
        billing_unit: 'hourly',
        bill_rate: '200.00',
        pay_rate: '150.00',
        units_per_period: '176.00',
        notes: 'Shift A operators',
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

  return { mockContract: contract }
})

vi.mock('../lib/contracts', () => ({
  getContract: vi.fn().mockImplementation(() => Promise.resolve(mockContract)),
  updateContract: vi.fn().mockResolvedValue(mockContract),
  replaceContractRateCards: vi.fn().mockResolvedValue(mockContract),
  markContractSigned: vi.fn().mockResolvedValue(mockContract),
  deleteContract: vi.fn().mockResolvedValue(undefined),
  downloadContractFile: vi.fn().mockResolvedValue(undefined),
}))

vi.mock('../lib/companies', () => ({
  listCompanySites: vi.fn().mockResolvedValue([
    { id: 'site-1', label: 'Manila HQ' },
    { id: 'site-2', label: 'Cebu Hub' },
  ]),
}))

vi.mock('../lib/auth-context', () => ({
  useAuth: () => ({
    user: { id: 1, name: 'Manager User', role: 'manager' },
  }),
}))

afterEach(() => {
  vi.restoreAllMocks()
})

describe('Contract Detail (/contracts/:id)', () => {
  it('renders contract header, rate cards, and renewal history', async () => {
    render(
      <MemoryRouter initialEntries={['/contracts/contract-1']}>
        <Routes>
          <Route path="/contracts/:id" element={<ContractDetail />} />
        </Routes>
      </MemoryRouter>,
    )

    expect(await screen.findByText('Warehouse associate staffing agreement')).toBeInTheDocument()
    expect(screen.getByText('CON-2026-0001')).toBeInTheDocument()
    expect(screen.getByText('Harbor Logistics Corp')).toBeInTheDocument()
    expect(screen.getByText('Forklift Operator')).toBeInTheDocument()
    expect(screen.getByText('Manila HQ')).toBeInTheDocument()
    expect(screen.getByText('Shift A operators')).toBeInTheDocument()
    expect(screen.getByText('PO-9981')).toBeInTheDocument()
    expect(screen.getByText('John Doe (VP Operations)')).toBeInTheDocument()
    expect(screen.getByText('Net 30')).toBeInTheDocument()
    expect(screen.getByText('2027 Staffing Extension')).toBeInTheDocument()
  })

  it('allows manager to open and edit rate cards modal', async () => {
    render(
      <MemoryRouter initialEntries={['/contracts/contract-1']}>
        <Routes>
          <Route path="/contracts/:id" element={<ContractDetail />} />
        </Routes>
      </MemoryRouter>,
    )

    await screen.findByText('Warehouse associate staffing agreement')

    const manageBtn = screen.getByRole('button', { name: /manage rate cards/i })
    fireEvent.click(manageBtn)

    expect(
      screen.getByText('Add, edit, or remove commercial staffing rate lines for CON-2026-0001.'),
    ).toBeInTheDocument()
  })

  it('renders the shared site and notes fields in the rate-card editor and saves them', async () => {
    render(
      <MemoryRouter initialEntries={['/contracts/contract-1']}>
        <Routes>
          <Route path="/contracts/:id" element={<ContractDetail />} />
        </Routes>
      </MemoryRouter>,
    )

    await screen.findByText('Warehouse associate staffing agreement')
    fireEvent.click(screen.getByRole('button', { name: /manage rate cards/i }))

    const site = await screen.findByLabelText('Site 1')
    expect(site).toHaveValue('site-1')
    expect(await screen.findByRole('option', { name: 'Cebu Hub' })).toBeInTheDocument()

    fireEvent.change(site, { target: { value: 'site-2' } })
    fireEvent.change(screen.getByLabelText('Notes 1'), { target: { value: 'Night shift' } })
    fireEvent.click(screen.getByRole('button', { name: 'Save rate cards' }))

    await waitFor(() =>
      expect(contractApi.replaceContractRateCards).toHaveBeenCalledWith('contract-1', [
        expect.objectContaining({ site_id: 'site-2', notes: 'Night shift' }),
      ]),
    )
  })

  it('exposes the legal status transitions in the edit-terms dialog', async () => {
    render(
      <MemoryRouter initialEntries={['/contracts/contract-1']}>
        <Routes>
          <Route path="/contracts/:id" element={<ContractDetail />} />
        </Routes>
      </MemoryRouter>,
    )

    await screen.findByText('Warehouse associate staffing agreement')
    fireEvent.click(screen.getByRole('button', { name: /edit terms/i }))

    fireEvent.change(screen.getByLabelText('Status'), { target: { value: 'expired' } })
    fireEvent.click(screen.getByRole('button', { name: 'Save Changes' }))

    await waitFor(() =>
      expect(contractApi.updateContract).toHaveBeenCalledWith(
        'contract-1',
        expect.objectContaining({ status: 'expired' }),
      ),
    )
  })

  it('retires the contract and returns to the contract book', async () => {
    vi.spyOn(window, 'confirm').mockReturnValue(true)

    render(
      <MemoryRouter initialEntries={['/contracts/contract-1']}>
        <Routes>
          <Route path="/contracts/:id" element={<ContractDetail />} />
          <Route path="/contracts" element={<div>Contract book</div>} />
        </Routes>
      </MemoryRouter>,
    )

    await screen.findByText('Warehouse associate staffing agreement')
    fireEvent.click(screen.getByRole('button', { name: /retire contract/i }))

    await waitFor(() => expect(contractApi.deleteContract).toHaveBeenCalledWith('contract-1'))
    expect(await screen.findByText('Contract book')).toBeInTheDocument()
  })

  it('downloads file when download button is clicked', async () => {
    render(
      <MemoryRouter initialEntries={['/contracts/contract-1']}>
        <Routes>
          <Route path="/contracts/:id" element={<ContractDetail />} />
        </Routes>
      </MemoryRouter>,
    )

    await screen.findByText('Warehouse associate staffing agreement')

    const downloadBtn = screen.getByRole('button', { name: /download document/i })
    fireEvent.click(downloadBtn)

    expect(contractApi.downloadContractFile).toHaveBeenCalledWith(mockContract)
  })
})
