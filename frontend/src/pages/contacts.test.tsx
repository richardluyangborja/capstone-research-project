import { render, screen, within } from '@testing-library/react'
import { MemoryRouter } from 'react-router-dom'
import { describe, expect, it, vi } from 'vitest'
import Contacts from './Contacts'
import type { Company, Contact } from '../types'

// `vi.mock` factories are hoisted above the imports, so the fixtures they close over
// must be created with `vi.hoisted`.
const { paginated, companies } = vi.hoisted(() => {
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
    tags: [],
    parent_company_id: null,
    parent: null,
    source_lead_id: null,
    owner: { id: 1, name: 'Sam Salesrep' },
    owner_id: 1,
    counts: { sites: 1, branches: 0, contacts: 1, activities: 0 },
    can_update: true,
    can_manage_sites: true,
    can_manage_parent: true,
    can_assign: true,
    can_delete: true,
    created_at: '2026-10-15T03:51:40.000000Z',
    updated_at: '2026-10-15T03:51:40.000000Z',
  }

  const contact: Contact = {
    id: 'contact-1',
    company_id: 'company-1',
    company: {
      id: company.id,
      reference: company.reference,
      name: company.name,
      status: company.status,
    },
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

  return {
    companies: {
      data: [company],
      meta: { current_page: 1, per_page: 25, total: 1, last_page: 1 },
      links: {},
    },
    paginated: {
      data: [contact],
      meta: { current_page: 1, per_page: 25, total: 1, last_page: 1 },
      links: {},
    },
  }
})

vi.mock('../lib/contacts', () => ({
  listContacts: vi.fn().mockResolvedValue(paginated),
}))

vi.mock('../lib/companies', () => ({
  listCompanies: vi.fn().mockResolvedValue(companies),
}))

describe('Contacts book', () => {
  it('lists a contact with its company and decision-maker role', async () => {
    render(
      <MemoryRouter>
        <Contacts />
      </MemoryRouter>,
    )

    const name = await screen.findByText('Ramon Villanueva')
    const row = name.closest('tr')
    expect(row).not.toBeNull()
    expect(
      within(row as HTMLElement).getByRole('link', { name: 'Sunrise Foods Inc' }),
    ).toHaveAttribute('href', '/companies/company-1')
    expect(within(row as HTMLElement).getByText('Hiring manager')).toBeInTheDocument()
    expect(within(row as HTMLElement).getByText('Primary')).toBeInTheDocument()
    expect(
      within(row as HTMLElement).getByText('ramon.villanueva@sunrisefoods.ph'),
    ).toBeInTheDocument()
  })

  it('offers role, status and company filters', async () => {
    render(
      <MemoryRouter>
        <Contacts />
      </MemoryRouter>,
    )

    await screen.findByText('Ramon Villanueva')
    expect(screen.getByRole('option', { name: 'All roles' })).toBeInTheDocument()
    expect(screen.getByRole('option', { name: 'All statuses' })).toBeInTheDocument()
    expect(screen.getByRole('option', { name: 'Sunrise Foods Inc' })).toBeInTheDocument()
  })
})
