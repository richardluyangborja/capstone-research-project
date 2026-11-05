import { fireEvent, render, screen, within } from '@testing-library/react'
import { MemoryRouter } from 'react-router-dom'
import { describe, expect, it, vi } from 'vitest'
import LeadJourney from './LeadJourney'
import type { Activity, Lead, LeadStatus, LeadTimeline } from '../../types'

const systemActivity = (subject: string): Activity => ({
  id: `activity-${subject}`,
  category: 'system',
  type: 'system',
  subject,
  body: null,
  status: 'done',
  is_follow_up: false,
  due_at: null,
  due: null,
  occurred_at: '2026-11-05T02:00:00.000000Z',
  owner: { id: 1, name: 'Sam Salesrep' },
  created_at: '2026-11-05T02:00:00.000000Z',
})

const makeTimeline = (status: LeadStatus, activities: Activity[] = []): LeadTimeline => ({
  data: activities,
  meta: {
    status,
    status_changed_at: '2026-11-05T02:00:00.000000Z',
    last_activity_at: '2026-11-05T02:00:00.000000Z',
    aging: null,
    days_since_activity: 0,
  },
})

const contactedTimeline = (status: LeadStatus) =>
  makeTimeline(status, [systemActivity('Status changed new → contacted')])

const makeLead = (overrides: Partial<Lead> = {}): Lead => ({
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
  ...overrides,
})

const stepStates = () =>
  within(screen.getByRole('list', { name: 'Lead journey' }))
    .getAllByRole('listitem')
    .map((item) => item.getAttribute('data-state'))

function renderJourney(
  lead: Lead,
  timeline: LeadTimeline | null,
  onStatus = vi.fn(),
  onCompleteChecklist = vi.fn(),
) {
  render(
    <MemoryRouter>
      <LeadJourney
        lead={lead}
        timeline={timeline}
        onStatus={onStatus}
        onCompleteChecklist={onCompleteChecklist}
      />
    </MemoryRouter>,
  )

  return { onStatus, onCompleteChecklist }
}

describe('LeadJourney', () => {
  it('shows Captured done and Contacted current for a new lead, and marks it contacted', () => {
    const { onStatus } = renderJourney(makeLead({ status: 'new', can_qualify: true }), null)

    expect(stepStates()).toEqual(['done', 'current', 'pending', 'pending'])
    expect(
      screen.getByText(
        'Contacted comes first. Log an outbound activity below, or mark this lead contacted now.',
      ),
    ).toBeInTheDocument()

    fireEvent.click(screen.getByRole('button', { name: 'Mark contacted' }))
    expect(onStatus).toHaveBeenCalledWith('contacted')
  })

  it('names missing criteria and only points at the checklist when a contacted lead is incomplete', () => {
    const { onStatus, onCompleteChecklist } = renderJourney(
      makeLead({
        status: 'contacted',
        meets_criteria: false,
        missing_criteria: ['estimated_headcount', 'needed_roles', 'contact'],
      }),
      null,
    )

    expect(stepStates()).toEqual(['done', 'done', 'current', 'pending'])
    expect(
      screen.getByText(
        'Qualification opens once the checklist is complete. Still missing: Estimated headcount, Needed roles, Contact channel.',
      ),
    ).toBeInTheDocument()
    expect(screen.getByText('Estimated headcount')).toBeInTheDocument()
    expect(screen.getByText('Needed roles')).toBeInTheDocument()
    expect(screen.getByText('Contact channel')).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Mark qualified' })).not.toBeInTheDocument()
    expect(onStatus).not.toHaveBeenCalled()

    fireEvent.click(screen.getByRole('button', { name: 'Complete the checklist' }))
    expect(onCompleteChecklist).toHaveBeenCalledTimes(1)
  })

  it('offers qualification once a contacted lead meets every criterion', () => {
    const { onStatus } = renderJourney(
      makeLead({
        status: 'contacted',
        meets_criteria: true,
        missing_criteria: [],
      }),
      null,
    )

    expect(stepStates()).toEqual(['done', 'done', 'current', 'pending'])
    expect(screen.queryByLabelText('Missing qualification criteria')).not.toBeInTheDocument()

    fireEvent.click(screen.getByRole('button', { name: 'Mark qualified' }))
    expect(onStatus).toHaveBeenCalledWith('qualified')
  })

  it('links a qualified lead to the existing conversion wizard', () => {
    renderJourney(
      makeLead({
        status: 'qualified',
        meets_criteria: true,
        missing_criteria: [],
        can_convert: true,
      }),
      null,
    )

    expect(stepStates()).toEqual(['done', 'done', 'done', 'current'])
    expect(screen.getByRole('link', { name: 'Convert to client' })).toHaveAttribute(
      'href',
      '/leads/lead-1/convert',
    )
  })

  it('links a converted lead to its account and first deal', () => {
    renderJourney(
      makeLead({
        status: 'converted',
        meets_criteria: true,
        missing_criteria: [],
        can_convert: false,
        is_converted: true,
        converted_company_id: 'company-1',
        converted_opportunity_id: 'opp-1',
      }),
      null,
    )

    expect(stepStates()).toEqual(['done', 'done', 'done', 'done'])
    expect(screen.getByRole('link', { name: 'Open account' })).toHaveAttribute(
      'href',
      '/accounts/company-1',
    )
    expect(screen.getByRole('link', { name: 'View first deal' })).toHaveAttribute(
      'href',
      '/opportunities/opp-1',
    )
  })

  it('shows nurture as a branch with re-engagement actions', () => {
    const { onStatus } = renderJourney(
      makeLead({
        status: 'nurture',
        meets_criteria: true,
        missing_criteria: [],
      }),
      null,
    )

    expect(stepStates()).toEqual(['done', 'done', 'pending', 'pending'])
    expect(
      screen.getByText(/Paused in nurture — re-engage or qualify when ready/),
    ).toBeInTheDocument()
    expect(screen.getByRole('alert')).toHaveTextContent('Nurture')

    fireEvent.click(screen.getByRole('button', { name: 'Re-engage as contacted' }))
    fireEvent.click(screen.getByRole('button', { name: 'Mark qualified' }))

    expect(onStatus).toHaveBeenNthCalledWith(1, 'contacted')
    expect(onStatus).toHaveBeenNthCalledWith(2, 'qualified')
  })

  it('does not fake Contacted on a disqualified lead that was never contacted', () => {
    const { onStatus } = renderJourney(
      makeLead({
        status: 'disqualified',
        disqualification_reason: 'no_budget',
      }),
      null,
    )

    expect(stepStates()).toEqual(['done', 'pending', 'pending', 'pending'])
    expect(screen.getByText(/Stopped — re-open through Contacted/)).toBeInTheDocument()
    expect(screen.getByRole('alert')).toHaveTextContent('Disqualified')
    expect(screen.getByRole('alert')).toHaveTextContent('No budget')

    fireEvent.click(screen.getByRole('button', { name: 'Re-open as contacted' }))
    expect(onStatus).toHaveBeenCalledWith('contacted')
  })

  it('recovers Contacted from the timeline on a branch status', () => {
    renderJourney(
      makeLead({ status: 'disqualified', disqualification_reason: 'unresponsive' }),
      contactedTimeline('disqualified'),
    )

    expect(stepStates()).toEqual(['done', 'done', 'pending', 'pending'])
  })

  it('does not fake Contacted on an archived lead that was never contacted', () => {
    const { onStatus } = renderJourney(makeLead({ status: 'archived' }), null)

    expect(stepStates()).toEqual(['done', 'pending', 'pending', 'pending'])
    expect(screen.getByText(/Archived — restore to New to restart the journey/)).toBeInTheDocument()
    expect(screen.getByRole('alert')).toHaveTextContent('Archived')

    fireEvent.click(screen.getByRole('button', { name: 'Restore to New' }))
    expect(onStatus).toHaveBeenCalledWith('new')
  })

  it('recovers Contacted from the timeline on an archived lead', () => {
    renderJourney(makeLead({ status: 'archived' }), contactedTimeline('archived'))

    expect(stepStates()).toEqual(['done', 'done', 'pending', 'pending'])
  })

  it('stays read-only when the caller may not update the lead', () => {
    renderJourney(makeLead({ status: 'new', can_update: false }), null)

    expect(screen.queryByRole('button', { name: 'Mark contacted' })).not.toBeInTheDocument()
  })
})
