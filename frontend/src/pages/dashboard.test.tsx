import { fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import { MemoryRouter } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import DashboardHome from './DashboardHome'
import type { DashboardData, DashboardKpi } from '../lib/dashboard'

// Module 06 / spec 14 — the page consumes the three dashboard endpoints through `getDashboard`.
// These tests mock that accessor (the adapter has its own tests) and exercise the page states.

const { useAuth, getDashboard } = vi.hoisted(() => ({
  useAuth: vi.fn(),
  getDashboard: vi.fn(),
}))

vi.mock('../lib/auth-context', () => ({ useAuth }))

vi.mock('../lib/dashboard', () => ({
  DASHBOARD_RANGES: [
    { value: '30d', label: 'Last 30 days' },
    { value: 'quarter', label: 'This quarter' },
    { value: 'ytd', label: 'Year to date' },
  ],
  getDashboard,
}))

const session = (role: 'salesrep' | 'manager' | 'admin', name: string) => ({
  user: { id: 1, name, email: `${role}@primepower.test`, role },
  token: 'test-token',
  login: vi.fn(),
  logout: vi.fn(),
})

const kpi = (
  overrides: Partial<DashboardKpi> & Pick<DashboardKpi, 'id' | 'label'>,
): DashboardKpi => ({
  value: '0',
  hint: '',
  domain: 'counts',
  to: '/dashboard',
  ...overrides,
})

const managerDashboard: DashboardData = {
  range: '30d',
  updated_at: '24 Oct 2026, 12:00',
  scope: {
    role: 'manager',
    tier: 'team',
    label: 'Business Development team',
    description: "Business Development · the team's accounts, deals and answers.",
  },
  primary: [
    kpi({
      id: 'new-leads',
      label: 'New leads',
      value: '54',
      domain: 'counts',
      to: '/leads',
      hint: 'Captured in the period',
      trend: { direction: 'up', label: '+18% vs previous 30 days', tone: 'positive' },
    }),
    kpi({
      id: 'qualified',
      label: 'Qualified leads',
      value: '31',
      to: '/leads?view=qualification',
      trend: { direction: 'up', label: '+11% vs previous 30 days', tone: 'positive' },
    }),
    kpi({
      id: 'pipeline',
      label: 'Open pipeline',
      value: '₱4,120,000.00',
      domain: 'cost',
      to: '/pipeline',
      hint: 'Value of open deals',
      trend: { direction: 'up', label: '+9% vs previous 30 days', tone: 'positive' },
    }),
    kpi({
      id: 'won',
      label: 'Revenue won',
      value: '₱1,280,000.00',
      domain: 'cost',
      to: '/opportunities',
      trend: { direction: 'up', label: '+24% vs previous 30 days', tone: 'positive' },
    }),
  ],
  secondary: [
    kpi({
      id: 'win-rate',
      label: 'Win rate',
      value: '36%',
      to: '/opportunities',
      trend: { direction: 'up', label: '+4 pts vs previous 30 days', tone: 'positive' },
    }),
    kpi({
      id: 'clients',
      label: 'Active clients',
      value: '12',
      domain: 'people',
      to: '/accounts?view=clients',
      trend: { direction: 'up', label: '+3 accounts', tone: 'positive' },
    }),
    kpi({
      id: 'follow-ups',
      label: 'Follow-ups due',
      value: '9',
      domain: 'activity',
      to: '/follow-ups',
      trend: { direction: 'up', label: '6 overdue', tone: 'negative' },
    }),
    kpi({
      id: 'attention',
      label: 'Needs attention',
      value: '5',
      domain: 'people',
      to: '/satisfaction/responses?attention=true',
      trend: { direction: 'down', label: '2 fewer than previous 30 days', tone: 'positive' },
    }),
  ],
  line: {
    caption: 'Weekly, 25 Sep – 24 Oct',
    labels: ['25 Sep', '2 Oct', '9 Oct', '16 Oct', '23 Oct'],
    series: [
      { name: 'New leads', domain: 'counts', values: [12, 10, 14, 9, 9] },
      { name: 'Qualified', domain: 'people', values: [7, 6, 9, 5, 4] },
      { name: 'Won', domain: 'cost', values: [2, 3, 4, 1, 2] },
    ],
  },
  donut: {
    caption: 'Open pipeline by stage',
    total: '₱4,120,000.00',
    segments: [
      {
        label: 'Negotiation',
        value: 2_450_000,
        display: '₱2,450,000.00',
        domain: 'cost',
        to: '/pipeline',
      },
      {
        label: 'Proposal sent',
        value: 1_670_000,
        display: '₱1,670,000.00',
        domain: 'activity',
        to: '/pipeline',
      },
    ],
  },
  worklist: [
    {
      id: 'survey:response-1',
      kind: 'survey',
      subject: 'Sunrise Foods Inc',
      detail: 'CSAT 2 · NPS 4 on the post-deployment check-in',
      owner: 'Sam Salesrep',
      due: '14 Oct',
      severity: 'danger',
      action: 'Read answer',
      to: '/satisfaction/responses/response-1',
      minTier: 'personal',
    },
    {
      id: 'lead:lead-1:unassigned',
      kind: 'lead',
      subject: 'Metro Builders',
      detail: 'Unassigned lead awaiting first touch',
      owner: 'Unassigned',
      due: '3 days',
      severity: 'warning',
      action: 'Claim lead',
      to: '/leads?view=unassigned',
      minTier: 'team',
    },
  ],
  staff: [
    {
      id: '1',
      name: 'Maria Manager',
      job_title: 'Sales manager',
      team: 'Business Development',
      initials: 'MM',
      leads_handled: 22,
      qualified: 14,
      deals_won: 5,
      won_value: '₱1,840,000.00',
      conversion_rate: 41,
      follow_up_on_time: 96,
      csat: 4.6,
      attendance_rate: 99,
      leave_days: 1,
      late_arrivals: 0,
    },
    {
      id: '2',
      name: 'Sam Salesrep',
      job_title: 'Sales representative',
      team: 'Business Development',
      initials: 'SS',
      leads_handled: 86,
      qualified: 52,
      deals_won: 17,
      won_value: '₱3,240,000.00',
      conversion_rate: 38,
      follow_up_on_time: 91,
      csat: 4.1,
      attendance_rate: 94,
      leave_days: 3,
      late_arrivals: 2,
    },
    {
      id: '3',
      name: 'Liza Lopez',
      job_title: 'Sales representative',
      team: 'Business Development',
      initials: 'LL',
      leads_handled: 74,
      qualified: 46,
      deals_won: 15,
      won_value: '₱2,980,000.00',
      conversion_rate: 40,
      follow_up_on_time: 88,
      csat: 4.4,
      attendance_rate: 97,
      leave_days: 1.5,
      late_arrivals: 1,
    },
  ],
  staff_note:
    'CRM activity from leads, deals, follow-ups and survey answers. Attendance, leave and late arrivals come from core2 / performance management (HR).',
  ai: {
    generated_at: '24 Oct 2026, 12:00',
    model: 'Primepower rules v1',
    source: 'rules',
    headline: 'The team is closing well; two accounts are slipping.',
    narrative:
      'Business Development is at a 36% win rate with ₱4.12M open. Sunrise Foods needs a call this week.',
    insights: [
      {
        id: 'ai-attention',
        title: 'Sunrise is the loudest signal',
        body: 'A CSAT 2 / NPS 4 answer and an overdue rate-sheet follow-up.',
        domain: 'people',
        to: '/satisfaction/responses?attention=true',
        link_label: 'Read the answer',
      },
      {
        id: 'ai-pipeline',
        title: 'Negotiation is where the value sits',
        body: 'Negotiation holds ₱2.45M of the ₱4.12M open pipeline.',
        domain: 'cost',
        to: '/pipeline',
        link_label: 'Open the board',
      },
      {
        id: 'ai-follow-ups',
        title: 'The work still owed',
        body: '9 follow-ups are on the worklist; 6 already past due.',
        domain: 'activity',
        to: '/follow-ups',
        link_label: 'Review follow-ups',
      },
    ],
    actions: [
      {
        id: 'ai-action-call',
        label: 'Call Sunrise Foods',
        description: 'Acknowledge the 2/5 answer and re-send the rate card.',
        to: '/satisfaction/responses?attention=true',
      },
      {
        id: 'ai-action-board',
        label: 'Unstick negotiation',
        description: 'Review the three oldest negotiation deals.',
        to: '/pipeline',
      },
      {
        id: 'ai-action-follow-ups',
        label: 'Clear the overdue queue',
        description: 'Reschedule or complete what is past due.',
        to: '/follow-ups',
      },
      {
        id: 'ai-action-people',
        label: 'Review the staff scorecard',
        description: 'Compare CRM output with core2 attendance.',
        to: '/leads?view=team',
      },
    ],
  },
  alerts: [
    {
      id: 'alert-survey',
      variant: 'danger',
      title: '1 survey answer needs attention',
      body: 'Sunrise Foods rated the deployment 2/5 and would not recommend us.',
      link_label: 'Read the answer',
      to: '/satisfaction/responses?attention=true',
      minTier: 'personal',
    },
    {
      id: 'alert-contracts',
      variant: 'info',
      title: '2 contracts expire within 30 days',
      body: 'Harbor Logistics renews on 29 Oct; BPO follows in November.',
      link_label: 'Open clients',
      to: '/accounts?view=clients',
      minTier: 'team',
    },
  ],
  activity: [
    {
      id: 'act-stage',
      domain: 'cost',
      title: 'Deal moved',
      detail: 'BPO Solutions — 40-seat ramp → Negotiation',
      when: '4 hours ago',
      to: '/pipeline',
    },
    {
      id: 'act-qualified',
      domain: 'people',
      title: 'Lead qualified',
      detail: 'Metro Builders passed the minimum criteria',
      when: 'Yesterday',
      to: '/leads?view=qualification',
    },
  ],
}

const renderDashboard = () =>
  render(
    <MemoryRouter>
      <DashboardHome />
    </MemoryRouter>,
  )

describe('dashboard home', () => {
  beforeEach(() => {
    useAuth.mockReset()
    getDashboard.mockReset()
    useAuth.mockReturnValue(session('manager', 'Maria Manager'))
    getDashboard.mockResolvedValue(managerDashboard)
  })

  it('renders the manager scope, the KPI rows and both charts', async () => {
    renderDashboard()

    expect(await screen.findByText(/Good (morning|afternoon|evening), Maria/)).toBeInTheDocument()
    expect(screen.getByText(/Business Development team/)).toBeInTheDocument()
    expect(screen.getByText(/Updated 24 Oct 2026, 12:00/)).toBeInTheDocument()

    // The presentation-only simulation is gone from the product UI.
    expect(screen.queryByText(/simulated/i)).not.toBeInTheDocument()

    expect(screen.getByText('54')).toBeInTheDocument()
    const pipelineCard = screen
      .getAllByText('Open pipeline')
      .map((element) => element.closest('a'))
      .find((element) => element !== null)
    expect(pipelineCard).not.toBeNull()
    expect(within(pipelineCard as HTMLElement).getByText('₱4,120,000.00')).toBeInTheDocument()

    expect(
      screen.getByRole('img', { name: /Line chart: New leads, Qualified, Won/ }),
    ).toBeInTheDocument()
    expect(screen.getByRole('img', { name: /Open pipeline by stage/ })).toBeInTheDocument()
  })

  it('makes every KPI card a link to its module page', async () => {
    renderDashboard()

    const leadsCard = (await screen.findAllByText('New leads'))[0].closest('a')
    expect(leadsCard).toHaveAttribute('href', '/leads')

    const attentionCard = screen
      .getAllByText('Needs attention')
      .find((element) => element.closest('a'))
    expect(attentionCard?.closest('a')).toHaveAttribute(
      'href',
      '/satisfaction/responses?attention=true',
    )
  })

  it('shows the worklist with clickable rows and the team alerts', async () => {
    renderDashboard()

    const sunrise = await screen.findByRole('link', { name: 'Sunrise Foods Inc' })
    expect(sunrise).toHaveAttribute('href', '/satisfaction/responses/response-1')

    expect(screen.getByRole('link', { name: 'Metro Builders' })).toHaveAttribute(
      'href',
      '/leads?view=unassigned',
    )
    expect(screen.getByRole('link', { name: /Claim lead/ })).toHaveAttribute(
      'href',
      '/leads?view=unassigned',
    )
  })

  it('renders the AI report and links its insights and actions', async () => {
    renderDashboard()

    expect(await screen.findByText('AI management report')).toBeInTheDocument()
    expect(screen.getByText('Sunrise is the loudest signal')).toBeInTheDocument()
    expect(
      screen.getByText(/Generated 24 Oct 2026, 12:00 · Primepower rules v1/),
    ).toBeInTheDocument()

    const readLinks = screen.getAllByRole('link', { name: /Read the answer/ })
    expect(readLinks.length).toBeGreaterThan(0)
    expect(
      readLinks.every(
        (link) => link.getAttribute('href') === '/satisfaction/responses?attention=true',
      ),
    ).toBe(true)
    expect(screen.getByRole('link', { name: /Unstick negotiation/ })).toHaveAttribute(
      'href',
      '/pipeline',
    )
    expect(screen.getByRole('link', { name: /Open management reports/ })).toHaveAttribute(
      'href',
      '/reports',
    )
  })

  it('switches the reporting period and re-reads the endpoints', async () => {
    getDashboard.mockImplementation(async (_role, range) =>
      range === 'ytd'
        ? {
            ...managerDashboard,
            range: 'ytd',
            primary: [
              { ...managerDashboard.primary[0], value: '344' },
              ...managerDashboard.primary.slice(1),
            ],
          }
        : managerDashboard,
    )
    renderDashboard()

    fireEvent.click(await screen.findByRole('button', { name: 'Year to date' }))

    await waitFor(() => {
      expect(screen.getByRole('button', { name: 'Year to date' })).toHaveAttribute(
        'aria-pressed',
        'true',
      )
    })

    expect(await screen.findByText('344')).toBeInTheDocument()
    expect(screen.queryByText('54')).not.toBeInTheDocument()
    expect(getDashboard).toHaveBeenLastCalledWith('manager', 'ytd')
  })

  it('shows all three staff rows to a manager, with core2 HR figures', async () => {
    renderDashboard()

    const table = await screen.findByRole('table', {
      name: /Staff performance: CRM output and core2 HR figures/,
    })

    expect(within(table).getByText('Maria Manager')).toBeInTheDocument()
    expect(within(table).getByText('Sam Salesrep')).toBeInTheDocument()
    expect(within(table).getByText('Liza Lopez')).toBeInTheDocument()
    expect(within(table).getByText('Attendance')).toBeInTheDocument()
    expect(within(table).getByText('Leave (days)')).toBeInTheDocument()
    expect(within(table).getByText('96%')).toBeInTheDocument()
  })

  it('shows — rather than zero when core2 HR is unavailable', async () => {
    getDashboard.mockResolvedValue({
      ...managerDashboard,
      staff: managerDashboard.staff.map((row) => ({
        ...row,
        attendance_rate: null,
        leave_days: null,
        late_arrivals: null,
      })),
      staff_note:
        'CRM activity from leads, deals, follow-ups and survey answers. core2 HR figures are unavailable right now, so attendance, leave and late arrivals show as —.',
    })
    renderDashboard()

    const table = await screen.findByRole('table', {
      name: /Staff performance: CRM output and core2 HR figures/,
    })

    expect(within(table).getAllByText('—')).toHaveLength(9)
    expect(within(table).queryByText('0%')).not.toBeInTheDocument()
    expect(screen.getByText(/core2 HR figures are unavailable/)).toBeInTheDocument()
  })

  it('narrows a salesrep to their own book, scorecard and personal rows', async () => {
    useAuth.mockReturnValue(session('salesrep', 'Sam Salesrep'))
    getDashboard.mockResolvedValue({
      ...managerDashboard,
      scope: {
        role: 'salesrep',
        tier: 'personal',
        label: 'Your book',
        description: 'Leads, accounts, deals and answers you own.',
      },
      worklist: managerDashboard.worklist.filter((row) => row.minTier === 'personal'),
      staff: managerDashboard.staff.filter((row) => row.name === 'Sam Salesrep'),
    })
    renderDashboard()

    expect(await screen.findByText(/Your book/)).toBeInTheDocument()
    expect(screen.getByText(/Your scorecard/)).toBeInTheDocument()

    const table = screen.getByRole('table', {
      name: /Staff performance: CRM output and core2 HR figures/,
    })
    expect(within(table).getByText('Sam Salesrep')).toBeInTheDocument()
    expect(within(table).queryByText('Liza Lopez')).not.toBeInTheDocument()

    expect(screen.queryByRole('link', { name: 'Metro Builders' })).not.toBeInTheDocument()
    expect(getDashboard).toHaveBeenCalledWith('salesrep', '30d')
  })

  it('falls back to org scope for an admin', async () => {
    useAuth.mockReturnValue(session('admin', 'Ada Admin'))
    getDashboard.mockResolvedValue({
      ...managerDashboard,
      scope: {
        role: 'admin',
        tier: 'org',
        label: 'Primepower · CRM org-wide',
        description: 'Every team, every account, every answer.',
      },
      primary: [
        { ...managerDashboard.primary[0], value: '128' },
        ...managerDashboard.primary.slice(1),
      ],
    })
    renderDashboard()

    expect(await screen.findByText(/Primepower · CRM org-wide/)).toBeInTheDocument()
    expect(screen.getByText('128')).toBeInTheDocument()
    expect(screen.getByRole('link', { name: 'Metro Builders' })).toBeInTheDocument()
  })

  it('shows the loading state while the endpoints are in flight', () => {
    getDashboard.mockReturnValue(new Promise(() => {}))
    renderDashboard()

    expect(screen.getByRole('status')).toHaveTextContent('Loading the dashboard…')
    expect(screen.getByRole('button', { name: 'Last 30 days' })).toBeDisabled()
  })

  it('shows an error and retries the read', async () => {
    getDashboard.mockRejectedValueOnce(new Error('network down'))
    renderDashboard()

    expect(await screen.findByText('Dashboard unavailable')).toBeInTheDocument()
    expect(screen.getByText(/Check the API and try again/)).toBeInTheDocument()

    fireEvent.click(screen.getByRole('button', { name: 'Try again' }))

    expect(await screen.findByText('54')).toBeInTheDocument()
    expect(getDashboard).toHaveBeenCalledTimes(2)
  })

  it('renders the empty states when a block has nothing to show', async () => {
    getDashboard.mockResolvedValue({
      ...managerDashboard,
      worklist: [],
      alerts: [],
      activity: [],
      staff: [],
      donut: { ...managerDashboard.donut, total: '₱0.00', segments: [] },
    })
    renderDashboard()

    expect(
      await screen.findByText('Nothing needs attention — the book is clear.'),
    ).toBeInTheDocument()
    expect(screen.getByText('No open deals yet — the pipeline is clear.')).toBeInTheDocument()
    expect(screen.getByText('No alerts — nothing needs escalation.')).toBeInTheDocument()
    expect(screen.getByText('No recent activity in view yet.')).toBeInTheDocument()
    expect(screen.getByText('No staff in view.')).toBeInTheDocument()
  })
})
