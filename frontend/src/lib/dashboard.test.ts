import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { api } from './api'
import { getDashboard } from './dashboard'

vi.mock('./api', () => ({ api: { get: vi.fn() } }))

const get = vi.mocked(api.get)

const summary = (overrides: Record<string, unknown> = {}) => ({
  range: '30d',
  updated_at: '2026-10-24T04:00:00Z',
  scope: {
    role: 'manager',
    tier: 'team',
    label: 'Business Development team',
    description: "Business Development · the team's accounts, deals and answers.",
  },
  primary: [
    {
      id: 'new-leads',
      label: 'New leads',
      value: 3,
      unit: 'count',
      domain: 'counts',
      trend: { direction: 'up', value: 200, unit: 'percent', tone: 'positive' },
      hint: 'Captured in the period',
      href: '/leads',
    },
    {
      id: 'pipeline',
      label: 'Open pipeline',
      value: 4_120_000,
      unit: 'currency',
      domain: 'cost',
      trend: { direction: 'up', value: 9, unit: 'percent', tone: 'positive' },
      hint: 'Value of open deals',
      href: '/pipeline',
    },
  ],
  secondary: [
    {
      id: 'win-rate',
      label: 'Win rate',
      value: 38.5,
      unit: 'percent',
      domain: 'counts',
      trend: { direction: 'down', value: 12.5, unit: 'points', tone: 'negative' },
      hint: 'Won ÷ closed deals',
      href: '/opportunities',
    },
    {
      id: 'clients',
      label: 'Active clients',
      value: 12,
      unit: 'count',
      domain: 'people',
      trend: { direction: 'up', value: 3, unit: 'count', tone: 'positive' },
      hint: 'Past prospect, not churned',
      href: '/accounts?view=clients',
    },
    {
      id: 'follow-ups',
      label: 'Follow-ups due',
      value: 10,
      unit: 'count',
      domain: 'activity',
      trend: { direction: 'up', value: 9, unit: 'count', tone: 'negative' },
      hint: 'Open tasks + scheduled sends',
      href: '/follow-ups',
    },
    {
      id: 'attention',
      label: 'Needs attention',
      value: 1,
      unit: 'count',
      domain: 'people',
      trend: { direction: 'down', value: 2, unit: 'count', tone: 'positive' },
      hint: 'Unhappy answers and at-risk accounts',
      href: '/satisfaction/responses?attention=true',
    },
  ],
  line: {
    caption: 'Weekly, 25 Sep – 24 Oct',
    labels: ['25 Sep', '2 Oct', '9 Oct', '16 Oct', '23 Oct'],
    series: [
      { name: 'New leads', domain: 'counts', values: [1, 0, 1, 1, 0] },
      { name: 'Qualified', domain: 'people', values: [0, 1, 0, 0, 0] },
      { name: 'Won', domain: 'cost', values: [0, 0, 0, 0, 0] },
    ],
  },
  donut: {
    caption: 'Open pipeline by stage',
    total: 4_120_000,
    segments: [
      { label: 'Negotiation', value: 2_450_000, domain: 'cost', href: '/pipeline' },
      { label: 'Proposal sent', value: 1_670_000, domain: 'activity', href: '/pipeline' },
    ],
  },
  alerts: [
    {
      id: 'alert-attention',
      variant: 'danger',
      title: '1 account needs attention',
      body: '1 unhappy answer needs a callback.',
      href: '/satisfaction/responses?attention=true',
      link_label: 'Read the answers',
      minTier: 'personal',
    },
  ],
  activity: [
    {
      id: 'activity-1',
      domain: 'activity',
      title: 'Follow-up completed',
      detail: 'Prepare the crew schedule · Sunrise Foods Inc',
      occurred_at: '2026-10-24T02:00:00Z',
      href: '/accounts/company-1',
    },
  ],
  ai: {
    headline: '1 account needs a human this week.',
    narrative: 'The team is at a 0% win rate.',
    insights: [
      {
        id: 'ai-attention',
        title: 'The unhappy queue',
        body: '1 unhappy answer needs a callback.',
        domain: 'people',
        href: '/satisfaction/responses?attention=true',
        link_label: 'Read the answers',
      },
    ],
    actions: [
      {
        id: 'ai-action-board',
        label: 'Unstick the pipeline',
        description: 'Review the open deals that have not moved.',
        href: '/pipeline',
      },
    ],
    model: 'Primepower rules v1',
    generated_at: '2026-10-24T04:00:00Z',
    source: 'rules',
  },
  ...overrides,
})

const worklist = (overrides: Record<string, unknown> = {}) => ({
  data: [
    {
      id: 'survey:response-1',
      kind: 'survey',
      subject: 'Sunrise Foods Inc',
      detail: 'CSAT 2 · NPS 4 on the post-deployment check-in',
      owner: { id: 2, name: 'Sam Salesrep' },
      due: { label: '14 Oct', severity: 'danger' },
      href: '/satisfaction/responses/response-1',
      action_label: 'Read answer',
      minTier: 'personal',
    },
    {
      id: 'lead:lead-1:unassigned',
      kind: 'lead',
      subject: 'Metro Builders Corp',
      detail: 'Unassigned lead awaiting first touch',
      owner: null,
      due: { label: '3 days', severity: 'warning' },
      href: '/leads/lead-1',
      action_label: 'Claim lead',
      minTier: 'team',
    },
  ],
  meta: { total: 2, range: '30d' },
  ...overrides,
})

const staff = (overrides: Record<string, unknown> = {}) => ({
  data: [
    {
      user: {
        id: 2,
        name: 'Sam Salesrep',
        job_title: 'Sales representative',
        team: 'Business Development',
      },
      leads_handled: 86,
      qualified: 52,
      deals_won: 17,
      won_value: 3_240_000,
      conversion_rate: 38,
      follow_up_on_time: 91,
      csat: 4.25,
      hr: { attendance_rate: 94, leave_days: 3, late_arrivals: 2, source: 'core2' },
      minTier: 'personal',
    },
  ],
  meta: { period: '30d', hr_available: true },
  ...overrides,
})

const respond = (summaryBody = summary(), worklistBody = worklist(), staffBody = staff()) => {
  get
    .mockResolvedValueOnce({ data: { data: summaryBody } } as never)
    .mockResolvedValueOnce({ data: worklistBody } as never)
    .mockResolvedValueOnce({ data: staffBody } as never)
}

describe('getDashboard', () => {
  beforeEach(() => {
    get.mockReset()
    vi.useFakeTimers()
    vi.setSystemTime(new Date('2026-10-24T04:00:00Z'))
  })

  afterEach(() => {
    vi.useRealTimers()
  })

  it('reads the three endpoints with the requested period and formats their raw values', async () => {
    respond()

    const data = await getDashboard('manager', '30d')

    expect(get).toHaveBeenNthCalledWith(1, '/dashboard', { params: { range: '30d' } })
    expect(get).toHaveBeenNthCalledWith(2, '/dashboard/worklist', { params: { range: '30d' } })
    expect(get).toHaveBeenNthCalledWith(3, '/dashboard/staff-performance', {
      params: { range: '30d' },
    })

    expect(data.updated_at).toMatch(/(24 Oct 2026|Oct 24, 2026)/)
    expect(data.updated_at).toContain('12:00')
    expect(data.scope).toEqual({
      role: 'manager',
      tier: 'team',
      label: 'Business Development team',
      description: "Business Development · the team's accounts, deals and answers.",
    })

    expect(data.primary[1].value).toBe('₱4,120,000.00')
    expect(data.primary[0].trend).toEqual({
      direction: 'up',
      label: '+200% vs previous 30 days',
      tone: 'positive',
    })
    expect(data.secondary[0].value).toBe('38.5%')
    expect(data.secondary[0].trend?.label).toBe('−12.5 pts vs previous 30 days')
    expect(data.secondary[1].trend?.label).toBe('+3 accounts')
    expect(data.secondary[2].trend?.label).toBe('9 overdue')
    expect(data.secondary[3].trend?.label).toBe('2 fewer than previous 30 days')

    expect(data.donut.total).toBe('₱4,120,000.00')
    expect(data.donut.segments[0]).toMatchObject({
      label: 'Negotiation',
      display: '₱2,450,000.00',
      to: '/pipeline',
    })

    expect(data.worklist[0]).toMatchObject({
      owner: 'Sam Salesrep',
      due: '14 Oct',
      severity: 'danger',
      action: 'Read answer',
      to: '/satisfaction/responses/response-1',
    })
    expect(data.worklist[1].owner).toBe('Unassigned')

    expect(data.staff[0]).toMatchObject({
      id: '2',
      name: 'Sam Salesrep',
      initials: 'SS',
      won_value: '₱3,240,000.00',
      csat: 4.25,
      attendance_rate: 94,
      leave_days: 3,
      late_arrivals: 2,
    })
    expect(data.staff_note).toContain('core2 / performance management (HR)')

    expect(data.ai).toMatchObject({
      model: 'Primepower rules v1',
      source: 'rules',
      headline: '1 account needs a human this week.',
    })
    expect(data.ai.generated_at).toContain('12:00')
    expect(data.ai.insights[0].to).toBe('/satisfaction/responses?attention=true')
    expect(data.ai.actions[0].to).toBe('/pipeline')
    expect(data.alerts[0].to).toBe('/satisfaction/responses?attention=true')
    expect(data.activity[0].when).toBe('2 hours ago')
  })

  it('shows an unavailable HR block as null figures, never zero', async () => {
    const missingHr = staff({
      data: [
        {
          ...staff().data[0],
          hr: null,
        },
      ],
      meta: { period: '30d', hr_available: false },
    })
    respond(summary(), worklist(), missingHr)

    const data = await getDashboard('salesrep', '30d')

    expect(data.staff[0].attendance_rate).toBeNull()
    expect(data.staff[0].leave_days).toBeNull()
    expect(data.staff[0].late_arrivals).toBeNull()
    expect(data.staff_note).toContain('unavailable')
  })

  it('rejects when any of the three reads fails', async () => {
    get.mockImplementation(() => Promise.reject(new Error('dashboard unavailable')))

    await expect(getDashboard('admin', 'ytd')).rejects.toThrow('dashboard unavailable')
  })
})
