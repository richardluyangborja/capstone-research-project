import { fireEvent, render, screen, within } from '@testing-library/react'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { describe, expect, it, vi } from 'vitest'
import Companies from './Companies'
import CompanyDetail from './CompanyDetail'
import type { Company, CompanySite, Contact, Opportunity } from '../types'

vi.mock('../lib/auth-context', () => ({
  useAuth: () => ({ user: { id: 1, name: 'Sam Salesrep', role: 'manager' }, logout: vi.fn() }),
}))

// `vi.mock` factories are hoisted above the imports, so the fixtures they close over
// must be created with `vi.hoisted`.
const { company, site, contact, deal, paginated } = vi.hoisted(() => {
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
    tags: ['walk-in'],
    parent_company_id: null,
    parent: null,
    source_lead_id: null,
    owner: { id: 1, name: 'Sam Salesrep' },
    owner_id: 1,
    counts: { sites: 1, branches: 0, contacts: 1, opportunities: 1, activities: 2 },
    can_update: true,
    can_manage_sites: true,
    can_manage_parent: true,
    can_assign: true,
    can_delete: true,
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

vi.mock('../lib/companies', () => ({
  listCompanies: vi.fn().mockResolvedValue(paginated),
  listCompanySites: vi.fn().mockResolvedValue([site]),
  getCompanyTimeline: vi.fn().mockResolvedValue({ data: [], meta: { total: 0 } }),
  getCompany: vi.fn().mockResolvedValue(company),
  updateCompany: vi.fn(),
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
})
