import { fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import { MemoryRouter } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import Billing from './Billing'
import * as billingApi from '../lib/billing'
import type { BillingBook } from '../types'

const { book, auth } = vi.hoisted(() => {
  const book: BillingBook = {
    data: [
      {
        company: {
          id: 'company-1',
          reference: 'CO-2026-0002',
          name: 'BPO Solutions PH',
        },
        owner: { id: 2, name: 'Sam Salesrep' },
        account_status: 'overdue',
        outstanding: '935000.00',
        overdue: '935000.00',
        overdue_invoices: 1,
        invoice_count: 3,
        open_invoices: 1,
        last_invoice_date: '2026-10-03',
        next_due_date: '2026-11-02',
        credit_terms: 'Net 30',
        currency: 'PHP',
        aging: {
          current: '0.00',
          '1_30': '935000.00',
          '31_60': '0.00',
          '60_plus': '0.00',
        },
      },
    ],
    meta: {
      current_page: 1,
      per_page: 25,
      total: 1,
      last_page: 1,
      source: 'Finance (mocked)',
      read_only: true,
      as_of: '2026-11-01T08:00:00+00:00',
    },
    summary: {
      accounts: 1,
      outstanding: '935000.00',
      overdue: '935000.00',
      overdue_accounts: 1,
      open_invoices: 1,
      aging: {
        current: '0.00',
        '1_30': '935000.00',
        '31_60': '0.00',
        '60_plus': '0.00',
      },
    },
  }

  return { book, auth: { role: 'admin' as 'admin' | 'salesrep' } }
})

vi.mock('../lib/billing', () => ({
  listBilling: vi.fn().mockResolvedValue(book),
}))

vi.mock('../lib/companies', () => ({
  listCompanies: vi.fn().mockResolvedValue({
    data: [{ id: 'company-1', name: 'BPO Solutions PH' }],
    meta: { current_page: 1, last_page: 1, total: 1 },
  }),
}))

vi.mock('../lib/leads', () => ({
  getAssignableUsers: vi.fn().mockResolvedValue([{ id: 2, name: 'Sam Salesrep', team_id: null }]),
}))

vi.mock('../lib/auth-context', () => ({
  useAuth: () => ({
    user: { id: 1, name: 'Admin User', role: auth.role },
  }),
}))

describe('Billing Book (/billing)', () => {
  beforeEach(() => {
    auth.role = 'admin'
    vi.clearAllMocks()
  })

  it('renders the read-only AR feed, summary cards and account row', async () => {
    render(
      <MemoryRouter>
        <Billing />
      </MemoryRouter>,
    )

    expect((await screen.findAllByText('BPO Solutions PH')).length).toBeGreaterThan(0)
    expect(
      screen.getByText('Read-only Finance feed — no invoice or payment can be changed here'),
    ).toBeInTheDocument()
    expect(screen.getByText(/Finance \(mocked\)/)).toBeInTheDocument()
    expect(screen.getByText('Accounts with AR')).toBeInTheDocument()
    expect(screen.getAllByText('Outstanding').length).toBeGreaterThan(0)
    expect(screen.getAllByText('Overdue').length).toBeGreaterThan(0)
    expect(screen.getByText('Open invoices')).toBeInTheDocument()

    const table = screen.getByRole('table')
    expect(within(table).getByText('BPO Solutions PH')).toBeInTheDocument()
    expect(within(table).getByText('CO-2026-0002')).toBeInTheDocument()
    const row = within(table).getByText('CO-2026-0002').closest('tr')
    expect(row).not.toBeNull()
    expect(within(row as HTMLElement).getByText('Overdue')).toBeInTheDocument()
    expect(within(row as HTMLElement).getByText('Sam Salesrep')).toBeInTheDocument()
    expect(within(row as HTMLElement).getByText('1 invoice')).toBeInTheDocument()
    expect(within(row as HTMLElement).getByText('3 total')).toBeInTheDocument()
    expect(within(row as HTMLElement).getByText('Net 30')).toBeInTheDocument()

    const links = within(table).getAllByRole('link')
    expect(
      links.some(
        (link) =>
          link.getAttribute('href') === '/accounts/company-1?tab=commercial&section=billing',
      ),
    ).toBe(true)
    expect(
      screen.queryByRole('button', { name: /create|edit|pay|write off/i }),
    ).not.toBeInTheDocument()
  })

  it('sends search, status, aging, account, owner and overdue filters', async () => {
    render(
      <MemoryRouter>
        <Billing />
      </MemoryRouter>,
    )

    await screen.findAllByText('BPO Solutions PH')

    fireEvent.change(screen.getByPlaceholderText('Search account, reference, owner or terms…'), {
      target: { value: 'BPO' },
    })

    await waitFor(() => {
      expect(billingApi.listBilling).toHaveBeenLastCalledWith(
        expect.objectContaining({ search: 'BPO' }),
      )
    })

    fireEvent.change(screen.getByLabelText('Filter by AR status'), {
      target: { value: 'overdue' },
    })

    await waitFor(() => {
      expect(billingApi.listBilling).toHaveBeenLastCalledWith(
        expect.objectContaining({ status: 'overdue' }),
      )
    })

    fireEvent.change(screen.getByLabelText('Filter by aging bucket'), {
      target: { value: '1_30' },
    })

    await waitFor(() => {
      expect(billingApi.listBilling).toHaveBeenLastCalledWith(
        expect.objectContaining({ status: 'overdue', aging: '1_30' }),
      )
    })

    fireEvent.change(screen.getByLabelText('Filter by account'), {
      target: { value: 'company-1' },
    })

    await waitFor(() => {
      expect(billingApi.listBilling).toHaveBeenLastCalledWith(
        expect.objectContaining({ company: 'company-1' }),
      )
    })

    fireEvent.change(screen.getByLabelText('Filter by account owner'), {
      target: { value: '2' },
    })

    await waitFor(() => {
      expect(billingApi.listBilling).toHaveBeenLastCalledWith(
        expect.objectContaining({ owner: '2' }),
      )
    })

    fireEvent.click(screen.getByRole('button', { name: 'Overdue only' }))

    await waitFor(() => {
      expect(billingApi.listBilling).toHaveBeenLastCalledWith(
        expect.objectContaining({ overdue: true }),
      )
    })
  })

  it('keeps the AR book away from salesreps', async () => {
    auth.role = 'salesrep'

    render(
      <MemoryRouter>
        <Billing />
      </MemoryRouter>,
    )

    expect(screen.getByText('Manager access only')).toBeInTheDocument()
    expect(billingApi.listBilling).not.toHaveBeenCalled()
  })
})
