import { render, screen, waitFor } from '@testing-library/react'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { describe, expect, it, vi } from 'vitest'
import ContractDetail from './ContractDetail'
import * as contractApi from '../lib/contracts'

const contract = {
  id: 'contract-1',
  reference: 'CON-2026-0001',
  company_id: 'company-1',
  company: { id: 'company-1', reference: 'CO-2026-0001', name: 'Harbor Logistics', status: 'active' as const },
  opportunity_id: null,
  renewal_of_contract_id: null,
  renewal_of: null,
  renewals: [],
  title: 'Warehouse agreement',
  status: 'signed' as const,
  start_date: '2026-01-01',
  end_date: '2026-12-31',
  signed_at: '2026-01-01',
  value: '2400000',
  currency: 'PHP',
  payment_terms: 'Net 30',
  po_reference: null,
  auto_renew: false,
  notice_days: null,
  signatory_name: null,
  signatory_position: null,
  terms: null,
  notes: null,
  rate_cards: [],
  commercial: {
    client_monthly_value: '200000',
    internal_monthly_cost: null,
    gross_margin: null,
    gross_margin_percent: null,
    estimated_total_value: '2400000',
    can_view_margin: false,
  },
  source: 'Contract Management (mocked, read-only)',
  read_only: true,
  as_of: '2026-10-17',
  days_until_expiry: 60,
  is_expiring_soon: false,
  is_signed: true,
  is_active: true,
  owner: { id: 1, name: 'Sam' },
  owner_id: 1,
  created_at: '2026-01-01',
  updated_at: '2026-01-01',
} satisfies import('../types').Contract

vi.mock('../lib/contracts', () => ({ getContract: vi.fn() }))

describe('Contract detail read-only projection', () => {
  it('shows provenance and no mutation or document actions', async () => {
    vi.mocked(contractApi.getContract).mockResolvedValue(contract)
    render(
      <MemoryRouter initialEntries={['/contracts/contract-1']}>
        <Routes>
          <Route path="/contracts/:id" element={<ContractDetail />} />
        </Routes>
      </MemoryRouter>,
    )
    await waitFor(() => expect(screen.getByText('Contract Management (mocked, read-only)')).toBeInTheDocument())
    expect(screen.getByText(/Read-only projection/)).toBeInTheDocument()
    expect(screen.queryByRole('button')).not.toBeInTheDocument()
    for (const label of ['Download Document', 'Edit Terms', 'Mark Signed', 'Renew Contract', 'Retire Contract']) {
      expect(screen.queryByText(label)).not.toBeInTheDocument()
    }
  })
})
