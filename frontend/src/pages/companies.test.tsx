import { fireEvent, render, screen, within } from '@testing-library/react'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import Companies from './Companies'
import CompanyDetail from './CompanyDetail'
import * as companyApi from '../lib/companies'
import * as contractApi from '../lib/contracts'
import type { BillingSummary, Company, CompanySite, Contact, Contract, Opportunity } from '../types'

vi.mock('../lib/auth-context', () => ({
  useAuth: () => ({ user: { id: 1, name: 'Sam Salesrep', role: 'manager' }, logout: vi.fn() }),
}))

// `vi.mock` factories are hoisted above the imports, so the fixtures they close over
// must be created with `vi.hoisted`.
const { company, site, contact, deal, contract, billing, paginated } = vi.hoisted(() => {
  const company: Company = {
    id: 'company-1',
    reference: 'CO-2026-0001',
    name: 'Sunrise Foods Inc',
    trade_name: 'Sunrise Foods',
    industry: 'Food Manufacturing',
    size: 'medium',
    website: null,
    description: null,
    status: 'prospect',
    is_client: false,
    tier: 'b',
    client_since: null,
    churn_reason: null,
    churned_at: null,
    handoff: { status: null, reference: null, error: null, requested_at: null, can_retry: false },
    tags: ['walk-in'],
    parent_company_id: null,
    parent: null,
    source_lead_id: null,
    owner: { id: 1, name: 'Sam Salesrep' },
    owner_id: 1,
    counts: { sites: 1, branches: 0, contacts: 1, opportunities: 1, contracts: 1, activities: 2 },
    allowed_status_transitions: ['onboarding', 'active'],
    can_update: true,
    can_manage_sites: true,
    can_manage_parent: true,
    can_assign: true,
    can_delete: true,
    can_manage_lifecycle: true,
    can_view_billing: true,
    can_upload_contract: true,
    created_at: '2026-10-15T03:51:40.000000Z',
    updated_at: '2026-10-17T03:51:40.000000Z',
  }

  const site: CompanySite = {
    id: 'site-1',
    company_id: 'company-1',
    label: 'Head office',
    street_address: null,
    barangay: 'Barangay Sienna',
    city_municipality: 'Quezon City',
    province: 'Metro Manila',
    region: 'NCR',
    postal_code: '1103',
    is_primary: true,
    full_address: 'Barangay Sienna, Quezon City, Metro Manila, NCR, 1103',
    created_at: '2026-10-15T03:51:40.000000Z',
    updated_at: '2026-10-15T03:51:40.000000Z',
  }

  const contact: Contact = {
    id: 'contact-1',
    company_id: 'company-1',
    name: 'Ramon Villanueva',
    position: 'Plant HR Manager',
    role_type: 'hiring_manager',
    is_decision_maker: true,
    email: 'ramon.villanueva@sunrisefoods.ph',
    phone: '+63 2 8712 4455',
    mobile: '0917 555 2210',
    is_primary: true,
    status: 'active',
    notes: null,
    can_update: true,
    can_make_primary: false,
    can_delete: true,
    created_at: '2026-10-15T03:51:40.000000Z',
    updated_at: '2026-10-15T03:51:40.000000Z',
  }

  const deal: Opportunity = {
    id: 'opp-1',
    reference: 'OPP-2026-0001',
    company_id: 'company-1',
    lead_id: 'lead-1',
    lead: { id: 'lead-1', reference: 'LD-2026-0001' },
    name: 'Sunrise Foods Inc — Warehouse staffing',
    stage: 'proposal_sent',
    probability: 60,
    amount: '450000.00',
    currency: 'PHP',
    expected_close_date: '2026-11-30',
    actual_close_date: null,
    source: 'walk_in',
    primary_contact_id: 'contact-1',
    owner: { id: 1, name: 'Sam Salesrep' },
    owner_id: 1,
    lost_reason: null,
    is_open: true,
    is_won: false,
    is_lost: false,
    created_at: '2026-10-15T03:51:40.000000Z',
    updated_at: '2026-10-15T03:51:40.000000Z',
  }

  return {
    company,
    site,
    contact,
    deal,
    contract: {
      id: 'contract-1',
      reference: 'CON-2026-0001',
      company_id: 'company-1',
      opportunity_id: 'opp-1',
      renewal_of_contract_id: null,
      renewal_of: null,
      title: 'Warehouse staffing agreement',
      status: 'draft',
      start_date: '2026-10-01',
      end_date: '2027-10-01',
      signed_at: null,
      value: '450000.00',
      currency: 'PHP',
      terms: 'Net 30, seasonal headcount billed monthly.',
      notes: null,
      file: { present: true, name: 'agreement.pdf', size: 1200, mime: 'application/pdf' },
      days_until_expiry: 349,
      is_expiring_soon: false,
      is_signed: false,
      is_active: false,
      owner: { id: 1, name: 'Sam Salesrep' },
      owner_id: 1,
      allowed_transitions: ['sent', 'terminated'],
      can_update: true,
      can_mark_signed: true,
      can_delete: true,
      created_at: '2026-10-15T03:51:40.000000Z',
      updated_at: '2026-10-17T03:51:40.000000Z',
    } satisfies Contract,
    billing: {
      account_status: 'current',
      outstanding: '250000.00',
      overdue: '0.00',
      overdue_invoices: 0,
      last_invoice_date: '2026-10-01',
      next_due_date: '2026-11-01',
      credit_terms: 'Net 30',
      currency: 'PHP',
      aging: { current: '250000.00', '1_30': '0.00', '31_60': '0.00', '60_plus': '0.00' },
    } satisfies BillingSummary,
    paginated: {
      data: [company],
      meta: { current_page: 1, per_page: 25, total: 1, last_page: 1 },
      links: {},
    },
  }
})

vi.mock('../lib/opportunities', () => ({
  listCompanyOpportunities: vi.fn().mockResolvedValue({
    data: [deal],
    meta: { total: 1, open: 1 },
  }),
}))

vi.mock('../lib/contracts', () => ({
  listCompanyContracts: vi
    .fn()
    .mockResolvedValue({ data: [contract], meta: { total: 1, active: 0 } }),
  createContract: vi.fn(),
  updateContract: vi.fn(),
  markContractSigned: vi.fn().mockResolvedValue(contract),
  deleteContract: vi.fn(),
  restoreContract: vi.fn(),
  downloadContractFile: vi.fn(),
}))

vi.mock('../lib/companies', () => ({
  listCompanies: vi.fn().mockResolvedValue(paginated),
  listCompanySites: vi.fn().mockResolvedValue([site]),
  getCompanyTimeline: vi.fn().mockResolvedValue({ data: [], meta: { total: 0 } }),
  getCompany: vi.fn().mockResolvedValue(company),
  getCompanyBilling: vi
    .fn()
    .mockResolvedValue({
      data: billing,
      meta: { source: 'Finance (mocked)', read_only: true, as_of: '2026-10-17T00:00:00+00:00' },
    }),
  updateCompany: vi.fn(),
  updateCompanyLifecycle: vi.fn().mockResolvedValue(company),
  reactivateCompany: vi.fn().mockResolvedValue(company),
  retryCompanyHandoff: vi.fn().mockResolvedValue(company),
  createCompanySite: vi.fn(),
  updateCompanySite: vi.fn(),
  deleteCompanySite: vi.fn(),
  deleteCompany: vi.fn(),
  logCompanyActivity: vi.fn(),
}))

vi.mock('../lib/contacts', () => ({
  listCompanyContacts: vi.fn().mockResolvedValue([contact]),
  createCompanyContact: vi.fn(),
  updateContact: vi.fn(),
  makeContactPrimary: vi.fn(),
  deleteContact: vi.fn(),
}))

vi.mock('../lib/leads', () => ({
  getAssignableUsers: vi.fn().mockResolvedValue([{ id: 1, name: 'Sam Salesrep', team_id: null }]),
  parseValidationError: vi.fn().mockReturnValue(null),
}))

describe('Companies list', () => {
  it('lists the companies the API returns', async () => {
    render(
      <MemoryRouter>
        <Companies />
      </MemoryRouter>,
    )

    const link = await screen.findByRole('link', { name: 'Sunrise Foods Inc' })
    const row = link.closest('tr')
    expect(row).not.toBeNull()
    expect(within(row as HTMLElement).getByText('CO-2026-0001')).toBeInTheDocument()
    expect(within(row as HTMLElement).getByText('Prospect')).toBeInTheDocument()
    expect(within(row as HTMLElement).getByText('Tier B')).toBeInTheDocument()
  })
})

describe('Company detail', () => {
  const renderDetail = () =>
    render(
      <MemoryRouter initialEntries={['/companies/company-1']}>
        <Routes>
          <Route path="/companies/:id" element={<CompanyDetail />} />
        </Routes>
      </MemoryRouter>,
    )

  beforeEach(() => {
    // The mocked payload is shared, so each test starts from the baseline account.
    Object.assign(company, {
      status: 'prospect',
      is_client: false,
      client_since: null,
      churn_reason: null,
      churned_at: null,
      handoff: { status: null, reference: null, error: null, requested_at: null, can_retry: false },
      allowed_status_transitions: ['onboarding', 'active'],
      can_manage_lifecycle: true,
      can_view_billing: true,
      can_upload_contract: true,
    })
    vi.clearAllMocks()
  })

  it('shows the profile and the tab counts from the roll-up', async () => {
    renderDetail()

    expect(await screen.findByRole('heading', { name: 'Sunrise Foods Inc' })).toBeInTheDocument()
    expect(screen.getByRole('button', { name: /^Contacts/ })).toHaveTextContent('1')
    expect(screen.getByRole('button', { name: /^Sites/ })).toHaveTextContent('1')
    expect(screen.getByRole('button', { name: /^Activities/ })).toHaveTextContent('2')
  })

  it('lists the account contacts with their decision-maker role', async () => {
    renderDetail()

    await screen.findByRole('heading', { name: 'Sunrise Foods Inc' })
    fireEvent.click(screen.getByRole('button', { name: /^Contacts/ }))

    const name = await screen.findByText('Ramon Villanueva')
    const row = name.closest('tr')
    expect(row).not.toBeNull()
    expect(within(row as HTMLElement).getByText('Hiring manager')).toBeInTheDocument()
    expect(within(row as HTMLElement).getByText('Primary')).toBeInTheDocument()
    expect(within(row as HTMLElement).getByText('Plant HR Manager')).toBeInTheDocument()
  })

  it('shows the account opportunities with their stage and value', async () => {
    renderDetail()

    await screen.findByRole('heading', { name: 'Sunrise Foods Inc' })
    expect(screen.getByRole('button', { name: /^Opportunities/ })).toHaveTextContent('1')
    fireEvent.click(screen.getByRole('button', { name: /^Opportunities/ }))

    const name = await screen.findByText('Sunrise Foods Inc — Warehouse staffing')
    const row = name.closest('tr')
    expect(row).not.toBeNull()
    expect(within(row as HTMLElement).getByText('Proposal sent')).toBeInTheDocument()
    expect(within(row as HTMLElement).getByText('60%')).toBeInTheDocument()
    expect(within(row as HTMLElement).getByText('₱450,000.00')).toBeInTheDocument()
    expect(
      within(row as HTMLElement).getByRole('link', { name: 'LD-2026-0001' }),
    ).toBeInTheDocument()
  })

  it('offers the legal lifecycle moves and the mocked billing snapshot', async () => {
    renderDetail()

    await screen.findByRole('heading', { name: 'Sunrise Foods Inc' })

    // The lifecycle panel only offers what the state machine allows (spec 09 §1).
    expect(screen.getByText('Client lifecycle')).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Move to Onboarding' })).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Move to Active' })).toBeInTheDocument()
    expect(screen.getByText('Every move is recorded on the account timeline.')).toBeInTheDocument()

    // Billing is a read-only, manager-visible snapshot from the mocked Finance service.
    expect(screen.getByText('Billing')).toBeInTheDocument()
    expect(await screen.findByText('₱250,000.00')).toBeInTheDocument()
    expect(screen.getByText('Net 30')).toBeInTheDocument()
  })

  it('hides the billing snapshot when the payload says so', async () => {
    company.can_view_billing = false

    renderDetail()

    await screen.findByRole('heading', { name: 'Sunrise Foods Inc' })
    expect(screen.queryByText('Billing')).not.toBeInTheDocument()
  })

  it('churns a client through the inline reason form', async () => {
    Object.assign(company, {
      status: 'active',
      is_client: true,
      client_since: '2025-04-01',
      allowed_status_transitions: ['at_risk', 'dormant', 'churned'],
    })

    renderDetail()

    await screen.findByRole('heading', { name: 'Sunrise Foods Inc' })
    fireEvent.click(screen.getByRole('button', { name: 'Move to Churned' }))

    // A churn without a reason is refused, so the button stays disabled (spec 09 §5).
    const submit = screen.getByRole('button', { name: 'Churn client' })
    expect(submit).toBeDisabled()

    fireEvent.change(screen.getByLabelText('Why did the client leave?'), {
      target: { value: 'moved in-house' },
    })
    fireEvent.click(submit)

    await screen.findByText('Client moved to churned.')
    expect(companyApi.updateCompanyLifecycle).toHaveBeenCalledWith('company-1', {
      status: 'churned',
      reason: 'moved in-house',
      note: '',
    })
  })

  it('offers a retry when the Service handoff failed', async () => {
    company.handoff = {
      status: 'failed',
      reference: null,
      error: 'The Service desk did not acknowledge the handoff (mocked failure).',
      requested_at: '2026-10-17T00:00:00+00:00',
      can_retry: true,
    }

    renderDetail()

    await screen.findByRole('heading', { name: 'Sunrise Foods Inc' })
    expect(screen.getByText('Service handoff failed')).toBeInTheDocument()
    expect(screen.getByText(/The signed contract stands/)).toBeInTheDocument()

    fireEvent.click(screen.getByRole('button', { name: 'Retry handoff' }))

    await screen.findByText(/Service accepted the handoff|The handoff failed again/)
    expect(companyApi.retryCompanyHandoff).toHaveBeenCalledWith('company-1')
  })

  it('lists the contracts of the account and signs one', async () => {
    renderDetail()

    await screen.findByRole('heading', { name: 'Sunrise Foods Inc' })
    fireEvent.click(screen.getByRole('button', { name: /^Contracts/ }))

    const title = await screen.findByText('Warehouse staffing agreement')
    const row = title.closest('tr')
    expect(row).not.toBeNull()
    expect(within(row as HTMLElement).getByText('CON-2026-0001')).toBeInTheDocument()
    expect(within(row as HTMLElement).getByText('Draft')).toBeInTheDocument()
    expect(within(row as HTMLElement).getByText('₱450,000.00')).toBeInTheDocument()
    expect(
      within(row as HTMLElement).getByRole('button', { name: 'agreement.pdf' }),
    ).toBeInTheDocument()

    fireEvent.click(within(row as HTMLElement).getByRole('button', { name: 'Mark signed' }))

    await screen.findByText(/CON-2026-0001 is signed/)
    expect(contractApi.markContractSigned).toHaveBeenCalledWith('contract-1')
  })
})
