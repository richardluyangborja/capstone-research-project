import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import LeadConvert from './LeadConvert'
import type { ConversionPreview, Lead } from '../types'

vi.mock('../lib/auth-context', () => ({
  useAuth: () => ({ user: { id: 1, name: 'Sam Salesrep', role: 'salesrep' }, logout: vi.fn() }),
}))

const {
  preview,
  converted,
  convertLead,
  getConversionPreview,
  listCompanies,
  parseValidationError,
} = vi.hoisted(() => {
  const lead: Lead = {
    id: 'lead-1',
    reference: 'LD-2026-0001',
    company_name: 'Sunrise Foods Inc',
    contact_name: 'Ana Reyes',
    contact_email: 'ana@sunrisefoods.ph',
    contact_phone: '0917 555 0101',
    contact_position: 'HR Manager',
    source: 'walk_in',
    source_detail: null,
    industry: 'Food Manufacturing',
    location: 'Quezon City',
    service_interest: 'Warehouse staffing',
    estimated_headcount: 25,
    needed_roles: 'Warehouse staff',
    target_start_date: '2026-11-02',
    authority_confirmed: true,
    work_site: null,
    urgency: 'high',
    rating: 'hot',
    score: 85,
    qualification_notes: null,
    disqualification_reason: null,
    status: 'qualified',
    status_changed_at: '2026-10-16T02:00:00.000000Z',
    last_activity_at: '2026-10-16T02:00:00.000000Z',
    next_follow_up_at: null,
    next_follow_up: null,
    notes: null,
    owner: { id: 1, name: 'Sam Salesrep' },
    owner_id: 1,
    can_claim: false,
    can_reassign: true,
    can_update: true,
    allowed_transitions: ['disqualified', 'nurture'],
    is_archived: false,
    aging: null,
    days_since_activity: 1,
    budget_rate: '750.00',
    missing_criteria: [],
    meets_criteria: true,
    can_qualify: false,
    can_convert: true,
    is_converted: false,
    converted_company_id: null,
    converted_opportunity_id: null,
    created_at: '2026-10-15T03:51:40.000000Z',
    updated_at: '2026-10-16T02:00:00.000000Z',
  }

  const preview: ConversionPreview = {
    lead,
    company: {
      name: 'Sunrise Foods Inc',
      trade_name: null,
      industry: 'Food Manufacturing',
      size: null,
      website: null,
      description: null,
      owner_id: 1,
      owner: { id: 1, name: 'Sam Salesrep' },
    },
    contacts: [
      {
        name: 'Ana Reyes',
        position: 'HR Manager',
        role_type: 'other',
        email: 'ana@sunrisefoods.ph',
        phone: '0917 555 0101',
        mobile: null,
        is_primary: true,
      },
    ],
    opportunity: {
      name: 'Sunrise Foods Inc — Warehouse staffing',
      stage: 'qualified',
      amount: null,
      currency: 'PHP',
      probability: 20,
      expected_close_date: '2026-11-02',
      source: 'walk_in',
      owner_id: 1,
    },
    duplicates: [
      {
        id: 'company-9',
        reference: 'CO-2026-0009',
        name: 'Sunrise Foods Inc',
        trade_name: null,
        status: 'prospect',
        owner: { id: 1, name: 'Sam Salesrep' },
        matched_on: ['name'],
      },
    ],
    can_convert: true,
    can_set_amount: false,
    blocked_reason: null,
  }

  return {
    lead,
    preview,
    converted: {
      company: {
        id: 'company-1',
        reference: 'CO-2026-0001',
        name: 'Sunrise Foods Inc',
      },
      contacts: [],
      opportunity: { id: 'opp-1', reference: 'OPP-2026-0001' },
      lead: { ...lead, status: 'converted', reference: 'LD-2026-0001' },
      duplicates: [],
    },
    convertLead: vi.fn(),
    getConversionPreview: vi.fn(),
    listCompanies: vi.fn(),
    parseValidationError: vi.fn().mockReturnValue(null),
  }
})

vi.mock('../lib/leads', () => ({
  getConversionPreview,
  convertLead,
  parseValidationError,
}))

vi.mock('../lib/companies', () => ({
  listCompanies,
}))

function renderWizard() {
  return render(
    <MemoryRouter initialEntries={['/leads/lead-1/convert']}>
      <Routes>
        <Route path="/leads/:id/convert" element={<LeadConvert />} />
        <Route path="/accounts/:id" element={<p>Account opened</p>} />
      </Routes>
    </MemoryRouter>,
  )
}

const advanceToDeal = async () => {
  await screen.findByRole('heading', { name: 'Convert to client' })
  fireEvent.click(screen.getByRole('button', { name: /Continue/ }))
  fireEvent.click(screen.getByRole('button', { name: /Continue/ }))
}

describe('LeadConversion wizard', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    getConversionPreview.mockResolvedValue(preview)
    listCompanies.mockResolvedValue({
      data: [
        {
          id: 'company-9',
          reference: 'CO-2026-0009',
          name: 'Sunrise Foods Inc',
          status: 'prospect',
        },
      ],
      meta: { current_page: 1, per_page: 25, total: 1, last_page: 1 },
      links: {},
    })
    convertLead.mockResolvedValue(converted)
  })

  it('prefills the mapping from the lead and warns about a possible existing account', async () => {
    renderWizard()

    const name = await screen.findByLabelText(/Legal name/)
    expect(name).toHaveValue('Sunrise Foods Inc')
    expect(screen.getByLabelText(/^Industry/)).toHaveValue('Food Manufacturing')
    expect(screen.getByText(/Possible existing account/)).toBeInTheDocument()
    expect(screen.getByText(/matched on name/)).toBeInTheDocument()

    fireEvent.click(screen.getByRole('button', { name: /Continue/ }))

    expect(screen.getByDisplayValue('Ana Reyes')).toBeInTheDocument()
    expect(screen.getByLabelText(/Role type/)).toHaveValue('other')
  })

  it('converts a new company with its contact and deal in one call', async () => {
    renderWizard()
    await advanceToDeal()

    expect(screen.getByDisplayValue('Sunrise Foods Inc — Warehouse staffing')).toBeInTheDocument()
    expect(screen.getByText('Qualified', { exact: true })).toBeInTheDocument()

    fireEvent.click(screen.getByRole('button', { name: 'Convert to client' }))

    await waitFor(() => expect(convertLead).toHaveBeenCalledTimes(1))

    const [leadId, payload] = convertLead.mock.calls[0]
    expect(leadId).toBe('lead-1')
    expect(payload.link_company_id).toBeUndefined()
    expect(payload.company).toMatchObject({
      name: 'Sunrise Foods Inc',
      industry: 'Food Manufacturing',
    })
    expect(payload.contacts).toHaveLength(1)
    expect(payload.contacts[0]).toMatchObject({
      name: 'Ana Reyes',
      role_type: 'other',
      is_primary: true,
    })
    expect(payload.opportunity).toMatchObject({
      name: 'Sunrise Foods Inc — Warehouse staffing',
      expected_close_date: '2026-11-02',
    })
    // A salesrep never sets the deal value (ADR-0012).
    expect(payload.opportunity.amount).toBeUndefined()

    expect(await screen.findByText('Account opened')).toBeInTheDocument()
  })

  it('hides the deal amount from a salesrep', async () => {
    renderWizard()
    await advanceToDeal()

    expect(screen.queryByLabelText(/Amount/)).not.toBeInTheDocument()
    expect(screen.getByText(/managed by a manager/)).toBeInTheDocument()
  })

  it('offers the deal amount to a manager', async () => {
    getConversionPreview.mockResolvedValue({
      ...preview,
      can_set_amount: true,
      opportunity: { ...preview.opportunity, amount: '750.00' },
    })

    renderWizard()
    await advanceToDeal()

    expect(await screen.findByLabelText(/Amount/)).toHaveValue(750)
  })

  it('links an existing account instead of creating one', async () => {
    renderWizard()
    await screen.findByRole('heading', { name: 'Convert to client' })

    fireEvent.click(screen.getByLabelText(/Link to an existing account/))

    const option = await screen.findByRole('button', { name: /CO-2026-0009/ })
    fireEvent.click(option)
    fireEvent.click(screen.getByRole('button', { name: /Continue/ }))
    fireEvent.click(screen.getByRole('button', { name: /Continue/ }))
    fireEvent.click(screen.getByRole('button', { name: 'Convert to client' }))

    await waitFor(() => expect(convertLead).toHaveBeenCalledTimes(1))

    const [, payload] = convertLead.mock.calls[0]
    expect(payload.link_company_id).toBe('company-9')
    expect(payload.company).toBeUndefined()
  })

  it('can add a second contact and pick who leads', async () => {
    renderWizard()
    await screen.findByRole('heading', { name: 'Convert to client' })
    fireEvent.click(screen.getByRole('button', { name: /Continue/ }))

    fireEvent.click(screen.getByRole('button', { name: /Add contact/ }))

    const names = await screen.findAllByLabelText(/^Name/)
    fireEvent.change(names[1], { target: { value: 'Ramon Villanueva' } })

    const radios = screen.getAllByRole('radio', { name: /Primary contact/ })
    fireEvent.click(radios[1])

    fireEvent.click(screen.getByRole('button', { name: /Continue/ }))
    fireEvent.click(screen.getByRole('button', { name: 'Convert to client' }))

    await waitFor(() => expect(convertLead).toHaveBeenCalledTimes(1))

    const [, payload] = convertLead.mock.calls[0]
    expect(payload.contacts).toHaveLength(2)
    expect(payload.contacts[1]).toMatchObject({ name: 'Ramon Villanueva', is_primary: true })
    expect(payload.contacts[0].is_primary).toBe(false)
  })

  it('explains why a lead cannot be converted and offers no form', async () => {
    getConversionPreview.mockResolvedValue({
      ...preview,
      can_convert: false,
      blocked_reason: 'Only qualified leads can be converted — this one is new.',
    })

    renderWizard()

    expect(
      await screen.findByText('Only qualified leads can be converted — this one is new.'),
    ).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Convert to client' })).not.toBeInTheDocument()
  })

  it('surfaces a validation failure from the API', async () => {
    convertLead.mockRejectedValue({ response: { status: 422 } })
    parseValidationError.mockReturnValue({
      message: 'Please correct the highlighted fields.',
      errors: { 'opportunity.name': ['The opportunity name field is required.'] },
    })

    renderWizard()
    await advanceToDeal()
    fireEvent.click(screen.getByRole('button', { name: 'Convert to client' }))

    expect(await screen.findByText('Please correct the highlighted fields.')).toBeInTheDocument()
    expect(screen.getAllByText('The opportunity name field is required.').length).toBeGreaterThan(0)
  })
})
