import { fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import { MemoryRouter, Route, Routes, useLocation } from 'react-router-dom'
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
    primary_contact: {
      id: 'contact-1',
      name: 'Ramon Villanueva',
      position: 'Plant HR Manager',
      email: 'ramon.villanueva@sunrisefoods.ph',
      phone: '+63 2 8712 4455',
      mobile: '0917 555 2210',
    },
    owner: { id: 1, name: 'Sam Salesrep' },
    owner_id: 1,
    counts: {
      sites: 1,
      branches: 0,
      contacts: 1,
      opportunities: 1,
      open_opportunities: 1,
      contracts: 1,
      activities: 2,
      communications: 1,
    },
    next_follow_up_at: '2026-10-20T09:00:00.000000Z',
    next_follow_up: { state: 'upcoming', label: 'Upcoming', days: 3, overdue: false },
    last_activity_at: '2026-10-17T03:51:40.000000Z',
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
    next_step: 'Send the revised rate card',
    stage_changed_at: '2026-10-12T03:51:40.000000Z',
    last_activity_at: '2026-10-14T03:51:40.000000Z',
    days_in_stage: 5,
    days_since_activity: 3,
    is_stale: false,
    weighted_value: 270000,
    allowed_transitions: [
      'new_inquiry',
      'qualified',
      'discovery',
      'negotiation',
      'won',
      'lost',
      'on_hold',
    ],
    can_update: true,
    can_change_stage: true,
    can_set_amount: true,
    can_assign: true,
    can_delete: true,
    can_restore: true,
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
  getCompanyBilling: vi.fn().mockResolvedValue({
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

vi.mock('../lib/communications', () => ({
  listCommunicationsFor: vi.fn().mockResolvedValue({ data: [], meta: { total: 0 } }),
}))

vi.mock('../lib/surveys', () => ({
  listCompanySurveyInvitations: vi.fn().mockResolvedValue({
    data: [],
    meta: { current_page: 1, per_page: 25, total: 0, last_page: 1 },
  }),
  listSurveys: vi.fn().mockResolvedValue({
    data: [],
    meta: { current_page: 1, per_page: 25, total: 0, last_page: 1 },
  }),
  cancelSurveyInvitation: vi.fn(),
  resendSurveyInvitation: vi.fn(),
  sendSurveyInvitation: vi.fn(),
}))

vi.mock('../lib/satisfaction', () => ({
  getCompanySatisfaction: vi.fn().mockResolvedValue({
    responses: 0,
    csat: null,
    nps: null,
    promoters: 0,
    passives: 0,
    detractors: 0,
    answered: 0,
    attention: 0,
    invitations: 0,
    response_rate: null,
    latest: null,
    trend: [],
    recent: [],
  }),
}))

describe('Companies list', () => {
  beforeEach(() => {
    vi.clearAllMocks()
  })

  it('lists the companies the API returns with the book columns', async () => {
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
    expect(within(row as HTMLElement).getByText('Upcoming')).toBeInTheDocument()
    expect(within(row as HTMLElement).getByText(/Oct 17, 2026|17 Oct 2026/)).toBeInTheDocument()
    expect(screen.getByRole('columnheader', { name: 'Contracts' })).toBeInTheDocument()
    expect(screen.getByRole('columnheader', { name: 'Open deals' })).toBeInTheDocument()
    expect(screen.getByRole('columnheader', { name: 'Next follow-up' })).toBeInTheDocument()
    expect(screen.getByRole('columnheader', { name: 'Last activity' })).toBeInTheDocument()
  })

  it('opens the clients view from the URL', async () => {
    render(
      <MemoryRouter initialEntries={['/accounts?view=clients']}>
        <Companies />
      </MemoryRouter>,
    )

    await waitFor(() =>
      expect(companyApi.listCompanies).toHaveBeenLastCalledWith(
        expect.objectContaining({
          status: 'onboarding,active,at_risk,dormant,churned',
        }),
      ),
    )

    expect(screen.getByRole('button', { name: 'Clients' })).toBeInTheDocument()
    expect(screen.getByLabelText('Status')).toHaveValue('')
    expect(screen.getByRole('option', { name: 'All clients' })).toBeInTheDocument()
  })

  it('sends the owner and industry filters', async () => {
    render(
      <MemoryRouter>
        <Companies />
      </MemoryRouter>,
    )

    await waitFor(() => expect(companyApi.listCompanies).toHaveBeenCalled())

    fireEvent.change(screen.getByLabelText('Owner'), { target: { value: '1' } })
    fireEvent.change(screen.getByLabelText('Industry'), { target: { value: 'food' } })
    fireEvent.click(screen.getByRole('button', { name: 'Filter' }))

    await waitFor(() =>
      expect(companyApi.listCompanies).toHaveBeenLastCalledWith(
        expect.objectContaining({ owner_id: 1, industry: 'food' }),
      ),
    )
  })
})

describe('Company detail', () => {
  const renderDetail = (path = '/accounts/company-1') =>
    render(
      <MemoryRouter initialEntries={[path]}>
        <Routes>
          <Route path="/accounts/:id" element={<CompanyDetail />} />
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

  it('shows the header, the journey and the grouped tab counts', async () => {
    renderDetail()

    expect(await screen.findByRole('heading', { name: 'Sunrise Foods Inc' })).toBeInTheDocument()
    expect(screen.getByText('Primary contact')).toBeInTheDocument()
    expect(screen.getByText('Ramon Villanueva')).toBeInTheDocument()
    expect(screen.getByText('Journey')).toBeInTheDocument()
    expect(screen.getByText('Lead captured')).toBeInTheDocument()
    expect(screen.getAllByText('Created directly').length).toBeGreaterThan(0)
    expect(screen.getByRole('button', { name: /^People & Sites/ })).toHaveTextContent('2')
    expect(screen.getByRole('button', { name: /^Commercial/ })).toHaveTextContent('2')
    expect(screen.getByRole('button', { name: /^Engagement/ })).toHaveTextContent('3')
    // Lifecycle dates belong to the lifecycle panel, not the profile form.
    expect(screen.queryByLabelText('Client since')).not.toBeInTheDocument()
  })

  it('groups contacts, sites and branches under People & Sites', async () => {
    renderDetail()

    await screen.findByRole('heading', { name: 'Sunrise Foods Inc' })
    fireEvent.click(screen.getByRole('button', { name: /^People & Sites/ }))

    const matches = await screen.findAllByText('Ramon Villanueva')
    const row = matches.map((entry) => entry.closest('tr')).find((entry) => entry !== null)
    expect(row).not.toBeNull()
    expect(within(row as HTMLElement).getByText('Hiring manager')).toBeInTheDocument()
    expect(within(row as HTMLElement).getByText('Primary')).toBeInTheDocument()
    expect(within(row as HTMLElement).getByText('Plant HR Manager')).toBeInTheDocument()
    expect(screen.getByText('Head office')).toBeInTheDocument()
    expect(screen.getByText('Branch')).toBeInTheDocument()
  })

  it('groups deals, contracts and billing under Commercial', async () => {
    renderDetail()

    await screen.findByRole('heading', { name: 'Sunrise Foods Inc' })
    fireEvent.click(screen.getByRole('button', { name: /^Commercial/ }))

    const name = await screen.findByText('Sunrise Foods Inc — Warehouse staffing')
    const row = name.closest('tr')
    expect(row).not.toBeNull()
    expect(within(row as HTMLElement).getByText('Proposal sent')).toBeInTheDocument()
    expect(within(row as HTMLElement).getByText('60%')).toBeInTheDocument()
    expect(within(row as HTMLElement).getByText('₱450,000.00')).toBeInTheDocument()
    expect(
      within(row as HTMLElement).getByRole('link', { name: 'LD-2026-0001' }),
    ).toBeInTheDocument()

    const contractTitle = await screen.findByText('Warehouse staffing agreement')
    const contractRow = contractTitle.closest('tr')
    expect(contractRow).not.toBeNull()
    expect(within(contractRow as HTMLElement).getByText('CON-2026-0001')).toBeInTheDocument()
    expect(within(contractRow as HTMLElement).getByText('Draft')).toBeInTheDocument()

    expect(screen.getByText('Billing')).toBeInTheDocument()
    expect(await screen.findByText('₱250,000.00')).toBeInTheDocument()
    expect(screen.getByText('Net 30')).toBeInTheDocument()
  })

  it('offers the legal lifecycle moves on Overview', async () => {
    renderDetail()

    await screen.findByRole('heading', { name: 'Sunrise Foods Inc' })

    // The lifecycle panel only offers what the state machine allows (spec 09 §1).
    expect(screen.getByText('Client lifecycle')).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Move to Onboarding' })).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Move to Active' })).toBeInTheDocument()
    expect(screen.getByText('Every move is recorded on the account timeline.')).toBeInTheDocument()
    expect(screen.queryByText('Billing')).not.toBeInTheDocument()
  })

  it('hides the billing snapshot when the payload says so', async () => {
    company.can_view_billing = false

    renderDetail()

    await screen.findByRole('heading', { name: 'Sunrise Foods Inc' })
    fireEvent.click(screen.getByRole('button', { name: /^Commercial/ }))
    await screen.findByText('Sunrise Foods Inc — Warehouse staffing')
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
    fireEvent.click(screen.getByRole('button', { name: /^Commercial/ }))

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

  it.each([
    ['contacts', 'Plant HR Manager'],
    ['sites', 'Head office'],
    ['opportunities', 'Sunrise Foods Inc — Warehouse staffing'],
    ['contracts', 'Warehouse staffing agreement'],
    ['activities', 'No activity recorded yet.'],
  ])('maps the old %s tab onto its grouped tab', async (tab, expected) => {
    renderDetail(`/accounts/company-1?tab=${tab}`)

    await screen.findByRole('heading', { name: 'Sunrise Foods Inc' })
    expect(await screen.findByText(expected)).toBeInTheDocument()
  })

  it('groups communications, satisfaction and activities under Engagement', async () => {
    renderDetail('/accounts/company-1?tab=engagement')

    await screen.findByRole('heading', { name: 'Sunrise Foods Inc' })
    expect(await screen.findByText('Communication history')).toBeInTheDocument()
    expect(screen.getByText('Satisfaction')).toBeInTheDocument()
    expect(screen.getByText('Activities')).toBeInTheDocument()
  })

  it('shows the Service handoff and the read-only feed state on Delivery', async () => {
    renderDetail('/accounts/company-1?tab=delivery')

    await screen.findByRole('heading', { name: 'Sunrise Foods Inc' })
    expect(screen.getByText('Service handoff')).toBeInTheDocument()
    expect(screen.getByText('Not requested')).toBeInTheDocument()
    expect(screen.getByText('Feeds not connected yet')).toBeInTheDocument()
  })

  it('opens a deal-linked contract upload on Commercial', async () => {
    render(
      <MemoryRouter initialEntries={['/accounts/company-1?tab=contracts&opportunity_id=opp-1']}>
        <Routes>
          <Route
            path="/accounts/:id"
            element={
              <>
                <CompanyDetail />
                <LocationProbe />
              </>
            }
          />
        </Routes>
      </MemoryRouter>,
    )

    await screen.findByRole('heading', { name: 'Sunrise Foods Inc' })
    await waitFor(() => {
      expect(screen.getByTestId('company-location')).toHaveTextContent('tab=commercial')
      expect(screen.getByTestId('company-location')).toHaveTextContent('section=contracts')
      expect(screen.getByTestId('company-location')).toHaveTextContent('opportunity_id=opp-1')
    })
    expect(screen.getByRole('button', { name: 'Save contract' })).toBeInTheDocument()
    expect(screen.getByText(/From deal/)).toBeInTheDocument()
  })
})

function LocationProbe() {
  const location = useLocation()
  return <output data-testid="company-location">{location.search}</output>
}
