import { fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import Opportunities from './Opportunities'
import OpportunityDetail from './OpportunityDetail'
import Pipeline from './Pipeline'
import * as opportunityApi from '../lib/opportunities'
import type { Activity, Opportunity, PipelineBoard } from '../types'

// `vi.mock` factories are hoisted above the imports, so the fixtures they close over
// must be created with `vi.hoisted`.
const { deal, wonDeal, board, history } = vi.hoisted(() => {
  const base = {
    id: 'opp-1',
    reference: 'OPP-2026-0001',
    company_id: 'company-1',
    company: { id: 'company-1', reference: 'CO-2026-0001', name: 'Sunrise Foods Inc' },
    lead_id: null,
    lead: null,
    name: 'Sunrise Foods — warehouse staffing',
    stage: 'discovery',
    probability: 30,
    amount: '450000.00',
    currency: 'PHP',
    expected_close_date: '2026-11-30',
    actual_close_date: null,
    next_step: 'Book the site visit',
    source: 'walk_in',
    primary_contact_id: null,
    owner: { id: 1, name: 'Sam Salesrep' },
    owner_id: 1,
    lost_reason: null,
    is_open: true,
    is_won: false,
    is_lost: false,
    stage_changed_at: '2026-10-12T00:00:00.000000Z',
    last_activity_at: '2026-10-16T00:00:00.000000Z',
    days_in_stage: 5,
    days_since_activity: 1,
    is_stale: true,
    weighted_value: 135000,
    allowed_transitions: [
      'new_inquiry',
      'qualified',
      'proposal_sent',
      'negotiation',
      'on_hold',
      'won',
      'lost',
    ],
    contracts: [],
    can_update: true,
    can_change_stage: true,
    can_set_amount: true,
    can_assign: true,
    can_delete: true,
    can_restore: false,
    created_at: '2026-10-01T00:00:00.000000Z',
    updated_at: '2026-10-16T00:00:00.000000Z',
  } satisfies Opportunity

  const deal: Opportunity = base

  const wonDeal: Opportunity = {
    ...base,
    stage: 'won',
    probability: 100,
    actual_close_date: '2026-10-15',
    is_open: false,
    is_won: true,
    stage_changed_at: '2026-10-15T00:00:00.000000Z',
    // Terminal deals only reopen (ADR-0015 §1).
    allowed_transitions: [
      'new_inquiry',
      'qualified',
      'discovery',
      'proposal_sent',
      'negotiation',
      'on_hold',
    ],
  }

  const board: PipelineBoard = {
    data: {
      columns: [
        { stage: 'new_inquiry', count: 0, value: 0, weighted_value: 0, deals: [] },
        { stage: 'qualified', count: 0, value: 0, weighted_value: 0, deals: [] },
        { stage: 'discovery', count: 1, value: 450000, weighted_value: 135000, deals: [deal] },
        { stage: 'proposal_sent', count: 0, value: 0, weighted_value: 0, deals: [] },
        { stage: 'negotiation', count: 0, value: 0, weighted_value: 0, deals: [] },
        { stage: 'on_hold', count: 0, value: 0, weighted_value: 0, deals: [] },
      ],
      closed: [],
    },
    meta: {
      open_count: 1,
      open_value: 450000,
      weighted_value: 135000,
      won_count: 0,
      won_value: 0,
      lost_count: 0,
      lost_value: 0,
      win_rate: 0,
      average_deal: 450000,
      stale_count: 1,
      by_stage: [{ stage: 'discovery', count: 1, value: 450000, weighted_value: 135000 }],
    },
  }

  const history: { data: Activity[] } = {
    data: [
      {
        id: 'activity-1',
        category: 'system',
        type: 'system',
        subject: 'Stage changed to Discovery',
        body: null,
        status: 'done',
        occurred_at: '2026-10-12T00:00:00.000000Z',
        owner: null,
        created_at: '2026-10-12T00:00:00.000000Z',
      },
    ],
  }

  return { deal, wonDeal, board, history }
})

vi.mock('../lib/opportunities', () => ({
  getPipeline: vi.fn(),
  moveOpportunityStage: vi.fn(),
  listOpportunities: vi.fn(),
  getOpportunity: vi.fn(),
  getOpportunityTimeline: vi.fn(),
  updateOpportunity: vi.fn(),
  deleteOpportunity: vi.fn(),
  logOpportunityActivity: vi.fn(),
  listCompanyOpportunities: vi.fn(),
  createOpportunity: vi.fn(),
  restoreOpportunity: vi.fn(),
}))

vi.mock('../lib/contacts', () => ({
  listCompanyContacts: vi.fn().mockResolvedValue([]),
}))

vi.mock('../lib/leads', () => ({
  getAssignableUsers: vi.fn().mockResolvedValue([{ id: 1, name: 'Sam Salesrep', team_id: null }]),
  parseValidationError: vi.fn().mockReturnValue(null),
}))

beforeEach(() => {
  vi.mocked(opportunityApi.getPipeline).mockResolvedValue(board)
  vi.mocked(opportunityApi.getOpportunityTimeline).mockResolvedValue({
    data: history.data,
    meta: {
      stage: 'discovery',
      stage_changed_at: '2026-10-12T00:00:00.000000Z',
      last_activity_at: '2026-10-16T00:00:00.000000Z',
      days_in_stage: 5,
      is_stale: true,
    },
  })
  vi.mocked(opportunityApi.getOpportunity).mockResolvedValue(deal)
  vi.mocked(opportunityApi.listOpportunities).mockResolvedValue({
    data: [deal],
    meta: { current_page: 1, per_page: 25, total: 1, last_page: 1 },
    links: {},
    summary: { all: 1, open: 1, won: 0, lost: 0 },
  })
})

const renderBoard = () =>
  render(
    <MemoryRouter>
      <Pipeline />
    </MemoryRouter>,
  )

describe('Pipeline board', () => {
  it('renders open stage columns and the forecast header', async () => {
    renderBoard()

    const card = await screen.findByRole('link', { name: 'Sunrise Foods — warehouse staffing' })
    expect(card).toBeInTheDocument()

    const column = screen.getByRole('region', { name: 'Discovery column' })
    expect(within(column).getByText('Stale')).toBeInTheDocument()
    expect(within(column).getAllByText(/450,000/).length).toBeGreaterThan(0)

    expect(screen.getByText('Open pipeline')).toBeInTheDocument()
    expect(screen.getByText('Weighted forecast')).toBeInTheDocument()
    expect(screen.getByText('Stalled deals')).toBeInTheDocument()
  })

  it('offers only the legal target stages in the Move-to menu', async () => {
    renderBoard()

    const select = await screen.findByLabelText('Move OPP-2026-0001 to another stage')
    expect(within(select).getByRole('option', { name: 'Won' })).toBeInTheDocument()
    expect(within(select).getByRole('option', { name: 'Lost' })).toBeInTheDocument()
    // The deal already sits in Discovery — moving to itself is not offered.
    expect(within(select).queryByRole('option', { name: 'Discovery' })).not.toBeInTheDocument()
  })

  it('asks for a reason before marking a deal lost', async () => {
    vi.mocked(opportunityApi.moveOpportunityStage).mockResolvedValue(deal)
    renderBoard()

    fireEvent.change(await screen.findByLabelText('Move OPP-2026-0001 to another stage'), {
      target: { value: 'lost' },
    })

    const dialog = await screen.findByRole('dialog', { name: 'Reason for loss' })
    expect(within(dialog).getByText(/Sunrise Foods — warehouse staffing/)).toBeInTheDocument()

    fireEvent.change(within(dialog).getByLabelText('Reason'), { target: { value: 'price' } })
    fireEvent.click(within(dialog).getByRole('button', { name: 'Mark lost' }))

    await waitFor(() =>
      expect(opportunityApi.moveOpportunityStage).toHaveBeenCalledWith('opp-1', {
        stage: 'lost',
        lost_reason: 'price',
      }),
    )
  })

  it('moves a deal to another open stage without a prompt', async () => {
    vi.mocked(opportunityApi.moveOpportunityStage).mockResolvedValue(deal)
    renderBoard()

    fireEvent.change(await screen.findByLabelText('Move OPP-2026-0001 to another stage'), {
      target: { value: 'negotiation' },
    })

    await waitFor(() =>
      expect(opportunityApi.moveOpportunityStage).toHaveBeenCalledWith('opp-1', {
        stage: 'negotiation',
        lost_reason: null,
      }),
    )
  })

  it('drags a card into another column and moves it', async () => {
    vi.mocked(opportunityApi.moveOpportunityStage).mockResolvedValue(deal)
    renderBoard()

    const card = (
      await screen.findByRole('link', { name: 'Sunrise Foods — warehouse staffing' })
    ).closest('article')
    const target = screen.getByRole('region', { name: 'Proposal sent column' })
    expect(card).not.toBeNull()

    fireEvent.dragStart(card as HTMLElement, {
      dataTransfer: { setData: vi.fn(), effectAllowed: 'none' },
    })
    fireEvent.dragOver(target)
    fireEvent.drop(target, { dataTransfer: { getData: () => 'opp-1' } })

    await waitFor(() =>
      expect(opportunityApi.moveOpportunityStage).toHaveBeenCalledWith('opp-1', {
        stage: 'proposal_sent',
        lost_reason: null,
      }),
    )
  })
})

describe('Deals list', () => {
  it('lists deals with their company and summary totals', async () => {
    render(
      <MemoryRouter>
        <Opportunities />
      </MemoryRouter>,
    )

    const link = await screen.findByRole('link', { name: 'Sunrise Foods — warehouse staffing' })
    expect(link).toHaveAttribute('href', '/opportunities/opp-1')

    const row = link.closest('tr') as HTMLElement
    expect(within(row).getByText('OPP-2026-0001')).toBeInTheDocument()
    expect(within(row).getByRole('link', { name: 'Sunrise Foods Inc' })).toBeInTheDocument()
    expect(within(row).getByText('Discovery')).toBeInTheDocument()
  })
})

describe('Deal detail', () => {
  const renderDetail = () =>
    render(
      <MemoryRouter initialEntries={['/opportunities/opp-1']}>
        <Routes>
          <Route path="/opportunities/:id" element={<OpportunityDetail />} />
        </Routes>
      </MemoryRouter>,
    )

  it('shows the deal timeline and the stage control', async () => {
    renderDetail()

    expect(await screen.findByLabelText('Move OPP-2026-0001 to another stage')).toBeInTheDocument()
    expect(screen.getByText('Stage changed to Discovery')).toBeInTheDocument()
    expect(
      screen.queryByRole('link', { name: /create contract from deal/i }),
    ).not.toBeInTheDocument()
  })

  it('offers Create contract from deal once the deal is won', async () => {
    vi.mocked(opportunityApi.getOpportunity).mockResolvedValue(wonDeal)
    renderDetail()

    const link = await screen.findByRole('link', { name: /create contract from deal/i })
    expect(link).toHaveAttribute('href', '/companies/company-1?tab=contracts&opportunity_id=opp-1')
  })
})
