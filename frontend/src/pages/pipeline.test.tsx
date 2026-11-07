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
    estimated_headcount: 25,
    service_type: 'temporary_staffing',
    target_start_date: '2026-11-15',
    win_note: null,
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
    next_follow_up_at: null,
    next_follow_up: null,
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
        is_follow_up: false,
        due_at: null,
        due: null,
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
    expect(within(column).getByText('25 workers · Temporary Staffing')).toBeInTheDocument()
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

  it('shows the deal follow-up state on a card', async () => {
    vi.mocked(opportunityApi.getPipeline).mockResolvedValue({
      ...board,
      data: {
        ...board.data,
        columns: board.data.columns.map((column) =>
          column.stage === 'discovery'
            ? {
                ...column,
                deals: [
                  {
                    ...deal,
                    next_follow_up_at: '2026-10-16T02:00:00.000000Z',
                    next_follow_up: {
                      state: 'today',
                      label: 'Due today',
                      days: 0,
                      overdue: false,
                    },
                  },
                ],
              }
            : column,
        ),
      },
    })
    renderBoard()

    expect(await screen.findByText('Due today')).toBeInTheDocument()
  })

  it('warns when an active deal has no follow-up scheduled', async () => {
    vi.mocked(opportunityApi.getPipeline).mockResolvedValue({
      ...board,
      data: {
        ...board.data,
        columns: board.data.columns.map((column) => ({
          ...column,
          count: column.stage === 'proposal_sent' ? 1 : 0,
          deals:
            column.stage === 'proposal_sent' ? [{ ...deal, stage: 'proposal_sent' }] : [],
        })),
      },
    })
    renderBoard()

    expect(await screen.findByText('No follow-up scheduled')).toBeInTheDocument()
  })

  it('asks for a reason — and optional notes — before marking a deal lost', async () => {
    vi.mocked(opportunityApi.moveOpportunityStage).mockResolvedValue(deal)
    renderBoard()

    fireEvent.change(await screen.findByLabelText('Move OPP-2026-0001 to another stage'), {
      target: { value: 'lost' },
    })

    const dialog = await screen.findByRole('dialog', { name: 'Reason for loss' })
    expect(within(dialog).getByText(/Sunrise Foods — warehouse staffing/)).toBeInTheDocument()

    fireEvent.change(within(dialog).getByLabelText('Reason'), { target: { value: 'price' } })
    fireEvent.change(within(dialog).getByLabelText(/Notes/), {
      target: { value: 'Client chose a competitor 10% cheaper.' },
    })
    fireEvent.click(within(dialog).getByRole('button', { name: 'Mark lost' }))

    await waitFor(() =>
      expect(opportunityApi.moveOpportunityStage).toHaveBeenCalledWith('opp-1', {
        stage: 'lost',
        lost_reason: 'price',
        note: 'Client chose a competitor 10% cheaper.',
      }),
    )
  })

  it('confirms the won outcome through the WonConfirmDialog', async () => {
    const wonWithContract: Opportunity = {
      ...wonDeal,
      contracts: [
        {
          id: 'con-1',
          reference: 'CON-2026-0007',
          title: 'Warehouse staffing agreement',
          status: 'signed',
        },
      ],
    }
    vi.mocked(opportunityApi.moveOpportunityStage).mockResolvedValue(wonWithContract)
    renderBoard()

    fireEvent.change(await screen.findByLabelText('Move OPP-2026-0001 to another stage'), {
      target: { value: 'won' },
    })

    const dialog = await screen.findByRole('dialog', { name: 'Confirm won deal' })
    expect(within(dialog).getByText(/Core Transaction 3/)).toBeInTheDocument()
    expect(within(dialog).getByText(/Core Transaction 2/)).toBeInTheDocument()

    const closeDate = new Date().toISOString().slice(0, 10)
    fireEvent.change(within(dialog).getByLabelText('Actual close date'), {
      target: { value: closeDate },
    })
    fireEvent.change(within(dialog).getByLabelText(/Win notes/), {
      target: { value: 'Signed after the site visit; 25 operators.' },
    })
    fireEvent.click(within(dialog).getByRole('button', { name: 'Mark won' }))

    await waitFor(() =>
      expect(opportunityApi.moveOpportunityStage).toHaveBeenCalledWith('opp-1', {
        stage: 'won',
        lost_reason: null,
        actual_close_date: closeDate,
        win_note: 'Signed after the site visit; 25 operators.',
        amount: 450000,
      }),
    )

    // ADR-0027 — the won notice links the read-only contract projection, never creation.
    expect(
      await screen.findByText(/Linked contract:/),
    ).toBeInTheDocument()
    const contractLink = screen.getByRole('link', { name: 'CON-2026-0007 (Signed)' })
    expect(contractLink).toHaveAttribute('href', '/contracts/con-1')
    expect(screen.queryByText(/Create its contract/i)).not.toBeInTheDocument()
  })

  it('points a won deal without a projection at Contract Management', async () => {
    vi.mocked(opportunityApi.moveOpportunityStage).mockResolvedValue(wonDeal)
    renderBoard()

    fireEvent.change(await screen.findByLabelText('Move OPP-2026-0001 to another stage'), {
      target: { value: 'won' },
    })
    fireEvent.click(
      await screen.findByRole('button', { name: 'Mark won', }),
    )

    // A manager+ caller confirms the pre-filled amount; the note fields stay unset.
    await waitFor(() =>
      expect(opportunityApi.moveOpportunityStage).toHaveBeenCalledWith(
        'opp-1',
        expect.objectContaining({
          stage: 'won',
          lost_reason: null,
          actual_close_date: new Date().toISOString().slice(0, 10),
          amount: 450000,
        }),
      ),
    )
    expect(
      await screen.findByText(
        /is won\. Contracts are managed in Contract Management \(Core Transaction 3\)\./,
      ),
    ).toBeInTheDocument()
  })

  it('shows the read-only contract badge on a won card in the Closed column', async () => {
    const wonOnBoard: PipelineBoard = {
      ...board,
      data: {
        ...board.data,
        closed: [
          {
            ...wonDeal,
            contracts: [
              {
                id: 'con-1',
                reference: 'CON-2026-0007',
                title: 'Warehouse staffing agreement',
                status: 'signed',
              },
            ],
          },
        ],
      },
      meta: { ...board.meta, won_count: 1, won_value: 450000 },
    }
    vi.mocked(opportunityApi.getPipeline).mockResolvedValue(wonOnBoard)
    renderBoard()

    const closed = await screen.findByRole('region', { name: 'Closed deals column' })
    const badge = await within(closed).findByRole('link', {
      name: 'CON-2026-0007 · Signed',
    })
    expect(badge).toHaveAttribute('href', '/contracts/con-1')
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
    expect(within(row).getByText('25 workers · Temporary Staffing')).toBeInTheDocument()
  })

  it('shows the deal follow-up state in the table', async () => {
    vi.mocked(opportunityApi.listOpportunities).mockResolvedValue({
      data: [
        {
          ...deal,
          next_follow_up_at: '2026-10-16T02:00:00.000000Z',
          next_follow_up: {
            state: 'today',
            label: 'Due today',
            days: 0,
            overdue: false,
          },
        },
      ],
      meta: { current_page: 1, per_page: 25, total: 1, last_page: 1 },
      links: {},
      summary: { all: 1, open: 1, won: 0, lost: 0 },
    })
    render(
      <MemoryRouter>
        <Opportunities />
      </MemoryRouter>,
    )

    const link = await screen.findByRole('link', { name: 'Sunrise Foods — warehouse staffing' })
    const row = link.closest('tr') as HTMLElement
    expect(within(row).getByText('Due today')).toBeInTheDocument()
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

  it('shows and edits the deal staffing demand', async () => {
    renderDetail()

    expect(await screen.findByLabelText('Estimated headcount')).toHaveValue(25)
    expect(screen.getByLabelText('Service type')).toHaveValue('temporary_staffing')
    expect(screen.getByLabelText('Target start date')).toHaveValue('2026-11-15')
    expect(screen.getByText('25 workers · Temporary Staffing')).toBeInTheDocument()
  })

  it('explains that contracts are managed by the external contract system', async () => {
    vi.mocked(opportunityApi.getOpportunity).mockResolvedValue(wonDeal)
    renderDetail()

    expect(
      await screen.findByText(
        'No linked projection — contracts are managed in Contract Management (Core Transaction 3).',
      ),
    ).toBeInTheDocument()
  })
})
