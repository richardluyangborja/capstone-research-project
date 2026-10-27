import { fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import LeadsList from './LeadsList'
import * as leadApi from '../lib/leads'
import * as followUpApi from '../lib/followUps'
import type { Lead, LeadSummary, Paginated } from '../types'

const { session, lead, summary } = vi.hoisted(() => {
  const lead = {
    id: 'lead-1',
    reference: 'LEAD-2026-0001',
    company_name: 'Sunrise Foods Inc',
    contact_name: 'Rosa Dela Cruz',
    contact_email: 'rosa@sunrise.test',
    contact_phone: null,
    contact_position: null,
    source: 'email',
    source_detail: null,
    industry: null,
    location: null,
    service_interest: null,
    estimated_headcount: 40,
    needed_roles: 'Warehouse crew',
    target_start_date: null,
    authority_confirmed: false,
    work_site: null,
    urgency: null,
    rating: 'hot',
    score: 75,
    qualification_notes: null,
    disqualification_reason: null,
    status: 'qualified',
    status_changed_at: null,
    last_activity_at: null,
    next_follow_up_at: null,
    next_follow_up: null,
    notes: null,
    owner: { id: 2, name: 'Sam Salesrep' },
    owner_id: 2,
    can_claim: false,
    can_reassign: true,
    can_update: true,
    allowed_transitions: ['contacted', 'disqualified', 'archived'],
    is_archived: false,
    aging: null,
    days_since_activity: 0,
    missing_criteria: [],
    meets_criteria: true,
    can_qualify: false,
    can_convert: true,
    is_converted: false,
    converted_company_id: null,
    converted_opportunity_id: null,
    created_at: '2026-10-20T01:00:00.000000Z',
    updated_at: '2026-10-20T01:00:00.000000Z',
  } satisfies Lead

  const summary = {
    all: 8,
    mine: 3,
    unassigned: 2,
    team: 5,
    qualification: 2,
    aging: 1,
    conversion: 1,
    archived: 4,
  } satisfies LeadSummary

  const session = { role: 'manager' as 'manager' | 'salesrep' | 'admin' }

  return { session, lead, summary }
})

vi.mock('../lib/leads', () => ({
  listLeads: vi.fn(),
  getLeadSummary: vi.fn(),
  getAssignableUsers: vi.fn(),
  claimLead: vi.fn(),
  assignLead: vi.fn(),
  bulkAssignLeads: vi.fn(),
  bulkTransferLeads: vi.fn(),
}))

vi.mock('../lib/followUps', () => ({
  getFollowUpCounts: vi.fn(),
}))

vi.mock('../lib/auth-context', () => ({
  useAuth: () => ({
    user: { id: 1, name: 'Maria Manager', role: session.role },
    logout: vi.fn(),
  }),
}))

const page: Paginated<Lead> = {
  data: [lead],
  meta: { current_page: 1, per_page: 25, total: 1, last_page: 1 },
  links: {},
}

beforeEach(() => {
  vi.clearAllMocks()
  session.role = 'manager'
  vi.mocked(leadApi.listLeads).mockResolvedValue(page)
  vi.mocked(leadApi.getLeadSummary).mockResolvedValue(summary)
  vi.mocked(leadApi.getAssignableUsers).mockResolvedValue([
    { id: 2, name: 'Sam Salesrep', team_id: 'team-1', open_leads_count: 3 },
    { id: 3, name: 'Liza Lopez', team_id: 'team-1', open_leads_count: 1 },
  ])
  vi.mocked(followUpApi.getFollowUpCounts).mockResolvedValue({
    overdue: 2,
    today: 1,
    upcoming: 3,
    open: 6,
    done: 1,
  })
})

const renderWorkspace = (entry = '/leads') =>
  render(
    <MemoryRouter initialEntries={[entry]}>
      <Routes>
        <Route path="/leads" element={<LeadsList />} />
      </Routes>
    </MemoryRouter>,
  )

describe('Lead Workspace', () => {
  it('opens the needs-qualification saved view from the URL', async () => {
    renderWorkspace('/leads?view=qualification')

    await waitFor(() =>
      expect(leadApi.listLeads).toHaveBeenCalledWith(
        expect.objectContaining({ status: 'new,contacted', owner: undefined }),
      ),
    )

    expect(screen.getByRole('heading', { name: 'Lead Workspace' })).toBeInTheDocument()
    expect(
      screen.getByText('Leads still awaiting qualification (new and contacted).'),
    ).toBeInTheDocument()
  })

  it('switches to the shared unassigned queue and keeps the manager workload visible', async () => {
    renderWorkspace()

    await waitFor(() => expect(leadApi.listLeads).toHaveBeenCalled())

    fireEvent.click(screen.getByRole('button', { name: /^Unassigned/ }))

    await waitFor(() =>
      expect(leadApi.listLeads).toHaveBeenLastCalledWith(
        expect.objectContaining({ owner: 'unassigned' }),
      ),
    )

    expect(screen.getByText('Open leads')).toBeInTheDocument()
    expect(screen.getByText('Overdue follow-ups')).toBeInTheDocument()
    expect(screen.getByText('Unassigned queue')).toBeInTheDocument()
    const workloadCard = screen.getByRole('heading', { name: 'Workload by rep' }).closest('div')
    expect(workloadCard).not.toBeNull()
    expect(within(workloadCard as HTMLElement).getByText('Sam Salesrep')).toBeInTheDocument()
    expect(followUpApi.getFollowUpCounts).toHaveBeenCalledWith({ owner: 'team' })
  })

  it('hides the manager team view and workload from a salesrep', async () => {
    session.role = 'salesrep'
    renderWorkspace('/leads?view=team')

    await waitFor(() =>
      expect(leadApi.listLeads).toHaveBeenCalledWith(
        expect.objectContaining({ owner: undefined, status: undefined }),
      ),
    )

    expect(screen.queryByRole('button', { name: /^Team/ })).not.toBeInTheDocument()
    expect(screen.queryByText('Workload by rep')).not.toBeInTheDocument()
    expect(followUpApi.getFollowUpCounts).not.toHaveBeenCalled()
  })

  it('narrows the scoped book by rating and urgency', async () => {
    renderWorkspace()

    await waitFor(() => expect(leadApi.listLeads).toHaveBeenCalled())

    fireEvent.change(screen.getByLabelText('Rating'), { target: { value: 'hot' } })
    fireEvent.change(screen.getByLabelText('Urgency'), { target: { value: 'high' } })
    fireEvent.click(screen.getByRole('button', { name: 'Search' }))

    await waitFor(() =>
      expect(leadApi.listLeads).toHaveBeenLastCalledWith(
        expect.objectContaining({ rating: 'hot', urgency: 'high' }),
      ),
    )
  })

  it('offers the conversion wizard from the ready-to-convert view', async () => {
    renderWorkspace('/leads?view=conversion')

    await waitFor(() =>
      expect(leadApi.listLeads).toHaveBeenCalledWith(
        expect.objectContaining({ status: 'qualified' }),
      ),
    )

    expect(screen.getByRole('link', { name: 'Convert' })).toHaveAttribute(
      'href',
      '/leads/lead-1/convert',
    )
  })
})
