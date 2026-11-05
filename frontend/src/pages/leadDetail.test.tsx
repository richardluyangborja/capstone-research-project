import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import LeadDetail from './LeadDetail'
import type { Lead, LeadTimeline } from '../types'

const { leadApi } = vi.hoisted(() => ({
  leadApi: {
    getLead: vi.fn(),
    getAssignableUsers: vi.fn(),
    getLeadTimeline: vi.fn(),
    updateLeadStatus: vi.fn(),
    updateQualification: vi.fn(),
    rateLead: vi.fn(),
    logLeadActivity: vi.fn(),
    claimLead: vi.fn(),
    assignLead: vi.fn(),
    archiveLead: vi.fn(),
    parseValidationError: vi.fn(),
  },
}))

vi.mock('../lib/leads', () => leadApi)

vi.mock('../components/common/CommunicationList', () => ({
  default: () => <div>Communications</div>,
}))

const { lead, timeline } = vi.hoisted(() => {
  const lead: Lead = {
    id: 'lead-1',
    reference: 'LEAD-2026-0001',
    company_name: 'Sunrise Foods Inc',
    contact_name: 'Rosa Dela Cruz',
    contact_email: 'rosa@sunrise.test',
    contact_phone: null,
    contact_position: 'HR Manager',
    source: 'email',
    source_detail: null,
    industry: 'Food Manufacturing',
    location: 'Quezon City',
    service_interest: null,
    estimated_headcount: 40,
    needed_roles: 'Warehouse crew',
    target_start_date: null,
    authority_confirmed: false,
    work_site: null,
    urgency: null,
    rating: 'warm',
    score: 60,
    qualification_notes: null,
    disqualification_reason: null,
    status: 'new',
    status_changed_at: null,
    last_activity_at: null,
    next_follow_up_at: null,
    next_follow_up: null,
    notes: null,
    owner: { id: 1, name: 'Sam Salesrep' },
    owner_id: 1,
    can_claim: false,
    can_reassign: false,
    can_update: true,
    allowed_transitions: ['contacted', 'disqualified', 'archived'],
    is_archived: false,
    aging: null,
    days_since_activity: 0,
    missing_criteria: ['estimated_headcount', 'needed_roles', 'contact'],
    meets_criteria: false,
    can_qualify: true,
    can_convert: false,
    is_converted: false,
    converted_company_id: null,
    converted_opportunity_id: null,
    created_at: '2026-11-05T01:00:00.000000Z',
    updated_at: '2026-11-05T01:00:00.000000Z',
  }

  const timeline: LeadTimeline = {
    data: [],
    meta: {
      status: 'new',
      status_changed_at: null,
      last_activity_at: null,
      aging: null,
      days_since_activity: 0,
    },
  }

  return { lead, timeline }
})

beforeEach(() => {
  vi.clearAllMocks()
  Object.assign(lead, {
    status: 'new',
    missing_criteria: ['estimated_headcount', 'needed_roles', 'contact'],
    meets_criteria: false,
    can_convert: false,
  })

  leadApi.getLead.mockResolvedValue(lead)
  leadApi.getAssignableUsers.mockResolvedValue([])
  leadApi.getLeadTimeline.mockResolvedValue(timeline)
  leadApi.updateLeadStatus.mockResolvedValue({ ...lead, status: 'contacted' })
  leadApi.parseValidationError.mockReturnValue(null)
})

function renderDetail() {
  return render(
    <MemoryRouter initialEntries={['/leads/lead-1']}>
      <Routes>
        <Route path="/leads/:id" element={<LeadDetail />} />
      </Routes>
    </MemoryRouter>,
  )
}

describe('Lead detail journey', () => {
  it('marks a new lead contacted through the existing status endpoint', async () => {
    renderDetail()

    fireEvent.click(await screen.findByRole('button', { name: 'Mark contacted' }))

    await waitFor(() =>
      expect(leadApi.updateLeadStatus).toHaveBeenCalledWith('lead-1', {
        status: 'contacted',
      }),
    )

    expect(await screen.findByText('Status updated to Contacted.')).toBeInTheDocument()
  })

  it('keeps the Lifecycle controls beside the derived journey', async () => {
    renderDetail()

    expect(await screen.findByText('Journey')).toBeInTheDocument()
    expect(screen.getByText('Lifecycle')).toBeInTheDocument()
    expect(screen.getByText('Change status')).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Archive lead' })).toBeInTheDocument()
  })
})
