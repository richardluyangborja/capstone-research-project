import { formatCurrency } from '../lib/format'
import type {
  DashboardActivityRow,
  DashboardAiReport,
  DashboardAlert,
  DashboardData,
  DashboardDonut,
  DashboardKpi,
  DashboardLine,
  DashboardRange,
  DashboardScope,
  DashboardTier,
  DashboardTrend,
  DashboardWorklistRow,
  StaffPerformanceRow,
} from '../lib/dashboard'
import type { Role } from '../types'

/**
 * SIMULATED dashboard fixtures (spec 14, ADR-0020).
 *
 * This file is the only place dashboard numbers live until the backend endpoints exist. Every
 * block mirrors the contract in `src/lib/dashboard.ts`; swapping the fixtures for HTTP keeps the
 * components untouched. Rules the fixtures follow:
 *
 *  1. Numbers are deterministic — no randomness, no faker.
 *  2. Names and accounts are the seeded demo records (Sunrise, BPO, Harbor, Greenfield), so the
 *     story reads like the rest of the demo.
 *  3. No record ids appear in links — fixtures link to module pages (or a filtered book), so a
 *     mocked row can never 404. The real API will send record hrefs.
 *  4. HR figures (attendance, leave, late arrivals) stand in for core2 / performance management
 *     and are marked as such in the UI; the real read will go through an `HrGateway` mock
 *     (ADR-0005), never a direct HR database query.
 */

const tierForRole = (role: Role): DashboardTier => {
  if (role === 'salesrep') return 'personal'
  if (role === 'manager') return 'team'
  return 'org'
}

const scopeFor = (role: Role, tier: DashboardTier): DashboardScope => {
  const scopes: Record<DashboardTier, Omit<DashboardScope, 'role' | 'tier'>> = {
    personal: {
      label: 'Your book',
      description: 'Leads, accounts, deals and answers you own.',
    },
    team: {
      label: 'Business Development team',
      description: "Maria Manager's team · Sam Salesrep and Liza Lopez.",
    },
    org: {
      label: 'Primepower · CRM org-wide',
      description: 'Every team, every account, every answer.',
    },
  }

  return { role, tier, ...scopes[tier] }
}

interface KpiSpec {
  id: string
  label: string
  domain: DashboardKpi['domain']
  to: string
  hint: string
  format: (value: number) => string
}

const percent = (value: number) => `${value}%`

const kpiSpecs: KpiSpec[] = [
  {
    id: 'new-leads',
    label: 'New leads',
    domain: 'counts',
    to: '/leads',
    hint: 'Captured in the period',
    format: (value) => `${value}`,
  },
  {
    id: 'qualified',
    label: 'Qualified leads',
    domain: 'counts',
    to: '/qualification',
    hint: 'Passed the minimum criteria',
    format: (value) => `${value}`,
  },
  {
    id: 'pipeline',
    label: 'Open pipeline',
    domain: 'cost',
    to: '/pipeline',
    hint: 'Weighted value of open deals',
    format: formatCurrency,
  },
  {
    id: 'won',
    label: 'Revenue won',
    domain: 'cost',
    to: '/opportunities',
    hint: 'Closed-won in the period',
    format: formatCurrency,
  },
  {
    id: 'win-rate',
    label: 'Win rate',
    domain: 'counts',
    to: '/opportunities',
    hint: 'Won ÷ closed deals',
    format: percent,
  },
  {
    id: 'clients',
    label: 'Active clients',
    domain: 'people',
    to: '/clients',
    hint: 'Past prospect, not churned',
    format: (value) => `${value}`,
  },
  {
    id: 'follow-ups',
    label: 'Follow-ups due',
    domain: 'activity',
    to: '/follow-ups',
    hint: 'Open tasks + scheduled sends',
    format: (value) => `${value}`,
  },
  {
    id: 'attention',
    label: 'Needs attention',
    domain: 'people',
    to: '/satisfaction/responses?attention=true',
    hint: 'Unhappy answers and at-risk accounts',
    format: (value) => `${value}`,
  },
]

/** Values align with `kpiSpecs`, in order. Open pipeline is a snapshot — same in every range. */
const kpiValues: Record<DashboardTier, Record<DashboardRange, number[]>> = {
  personal: {
    '30d': [18, 11, 1_450_000, 420_000, 33, 5, 4, 2],
    quarter: [52, 31, 1_450_000, 1_180_000, 37, 5, 5, 3],
    ytd: [116, 68, 1_450_000, 3_240_000, 40, 5, 7, 3],
  },
  team: {
    '30d': [54, 31, 4_120_000, 1_280_000, 36, 12, 9, 5],
    quarter: [158, 92, 4_120_000, 3_610_000, 39, 12, 12, 6],
    ytd: [344, 196, 4_120_000, 9_840_000, 42, 12, 15, 7],
  },
  org: {
    '30d': [128, 74, 8_640_000, 2_720_000, 38, 27, 19, 8],
    quarter: [372, 211, 8_640_000, 7_940_000, 41, 27, 24, 11],
    ytd: [812, 468, 8_640_000, 21_600_000, 43, 27, 31, 14],
  },
}

/** Trends align with `kpiSpecs`; they describe the period, so they do not vary by tier. */
const kpiTrends: Record<DashboardRange, (DashboardTrend | null)[]> = {
  '30d': [
    { direction: 'up', label: '+18% vs previous 30 days', tone: 'positive' },
    { direction: 'up', label: '+11% vs previous 30 days', tone: 'positive' },
    { direction: 'up', label: '+9% vs last month', tone: 'positive' },
    { direction: 'up', label: '+24% vs previous 30 days', tone: 'positive' },
    { direction: 'up', label: '+4 pts vs previous 30 days', tone: 'positive' },
    { direction: 'up', label: '+3 accounts', tone: 'positive' },
    { direction: 'up', label: '6 overdue', tone: 'negative' },
    { direction: 'down', label: '2 fewer than last month', tone: 'positive' },
  ],
  quarter: [
    { direction: 'up', label: '+22% vs last quarter', tone: 'positive' },
    { direction: 'up', label: '+15% vs last quarter', tone: 'positive' },
    { direction: 'up', label: '+11% vs last quarter', tone: 'positive' },
    { direction: 'up', label: '+31% vs last quarter', tone: 'positive' },
    { direction: 'up', label: '+3 pts vs last quarter', tone: 'positive' },
    { direction: 'up', label: '+5 accounts', tone: 'positive' },
    { direction: 'up', label: '8 overdue', tone: 'negative' },
    { direction: 'flat', label: 'In line with last quarter', tone: 'neutral' },
  ],
  ytd: [
    { direction: 'up', label: '+27% vs same period last year', tone: 'positive' },
    { direction: 'up', label: '+19% vs same period last year', tone: 'positive' },
    { direction: 'up', label: '+14% vs same period last year', tone: 'positive' },
    { direction: 'up', label: '+36% vs same period last year', tone: 'positive' },
    { direction: 'up', label: '+5 pts vs same period last year', tone: 'positive' },
    { direction: 'up', label: '+9 accounts', tone: 'positive' },
    { direction: 'up', label: '11 overdue', tone: 'negative' },
    { direction: 'up', label: '+2 vs last month', tone: 'negative' },
  ],
}

const buildKpis = (tier: DashboardTier, range: DashboardRange): DashboardKpi[] =>
  kpiSpecs.map((spec, index) => {
    const raw = kpiValues[tier][range][index]
    const trend = kpiTrends[range][index]

    return {
      id: spec.id,
      label: spec.label,
      value: spec.format(raw),
      hint: spec.hint,
      domain: spec.domain,
      to: spec.to,
      ...(trend ? { trend } : {}),
    }
  })

/** One org shape per range, scaled by tier — documented so the real series can replace it. */
const lineShapes: Record<
  DashboardRange,
  { labels: string[]; leads: number[]; qualified: number[]; won: number[]; caption: string }
> = {
  '30d': {
    labels: ['22 Sep', '29 Sep', '6 Oct', '13 Oct', '20 Oct'],
    leads: [20, 28, 26, 29, 25],
    qualified: [12, 16, 15, 17, 14],
    won: [3, 5, 4, 6, 5],
    caption: 'Weekly, 22 Sep – 20 Oct',
  },
  quarter: {
    labels: ['Jul', 'Aug', 'Sep', 'Oct'],
    leads: [88, 96, 102, 86],
    qualified: [50, 55, 58, 48],
    won: [12, 14, 16, 11],
    caption: 'Monthly, Q3 – Q4 2026',
  },
  ytd: {
    labels: ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct'],
    leads: [64, 71, 78, 82, 86, 91, 88, 84, 86, 82],
    qualified: [34, 38, 44, 47, 49, 52, 51, 49, 52, 52],
    won: [6, 7, 8, 9, 10, 11, 10, 9, 10, 9],
    caption: 'Monthly, January – October 2026',
  },
}

const tierFactor: Record<DashboardTier, number> = { org: 1, team: 0.42, personal: 0.14 }

const scale = (values: number[], factor: number) =>
  values.map((value) => Math.max(1, Math.round(value * factor)))

const buildLine = (tier: DashboardTier, range: DashboardRange): DashboardLine => {
  const shape = lineShapes[range]
  const factor = tierFactor[tier]

  return {
    labels: shape.labels,
    caption: shape.caption,
    series: [
      { name: 'New leads', domain: 'counts', values: scale(shape.leads, factor) },
      { name: 'Qualified', domain: 'people', values: scale(shape.qualified, factor) },
      { name: 'Won', domain: 'cost', values: scale(shape.won, factor) },
    ],
  }
}

/** The donut splits the same open-pipeline snapshot the KPI card shows — no second total. */
const pipelineValues: Record<DashboardTier, number> = {
  personal: 1_450_000,
  team: 4_120_000,
  org: 8_640_000,
}

const buildDonut = (tier: DashboardTier): DashboardDonut => {
  const total = pipelineValues[tier]
  const weights = [
    { label: 'New inquiry', weight: 120, domain: 'counts' as const },
    { label: 'Qualified', weight: 260, domain: 'people' as const },
    { label: 'Proposal sent', weight: 210, domain: 'activity' as const },
    { label: 'Negotiation', weight: 274, domain: 'cost' as const },
  ]
  const weightSum = weights.reduce((sum, segment) => sum + segment.weight, 0)

  // Round to 10k, then let the last segment absorb the remainder so the parts add up exactly.
  let allocated = 0
  const segments = weights.map((segment, index) => {
    const value =
      index === weights.length - 1
        ? total - allocated
        : Math.round((total * segment.weight) / weightSum / 10_000) * 10_000
    allocated += value

    return {
      label: segment.label,
      value,
      display: formatCurrency(value),
      domain: segment.domain,
      to: '/pipeline',
    }
  })

  return {
    caption: 'Open pipeline by stage',
    total: formatCurrency(total),
    segments,
  }
}

/**
 * minTier: personal = everyone sees it, team = manager+, org = admin/superadmin only.
 * Every `to` is an existing route (never a fixture id).
 */
const worklistRows: DashboardWorklistRow[] = [
  {
    id: 'wl-sunrise-survey',
    kind: 'survey',
    subject: 'Sunrise Foods Inc',
    detail: 'CSAT 2 · NPS 4 on the post-deployment check-in',
    owner: 'Sam Salesrep',
    due: '19 Oct',
    severity: 'danger',
    action: 'Read answer',
    to: '/satisfaction/responses?attention=true',
    minTier: 'personal',
  },
  {
    id: 'wl-sunrise-follow-up',
    kind: 'follow_up',
    subject: 'Sunrise Foods — rate-sheet follow-up',
    detail: 'Rate card sent; no reply yet',
    owner: 'Sam Salesrep',
    due: '2 days overdue',
    severity: 'danger',
    action: 'Open follow-ups',
    to: '/follow-ups',
    minTier: 'personal',
  },
  {
    id: 'wl-bpo-ramp',
    kind: 'opportunity',
    subject: 'BPO Solutions — 40-seat ramp',
    detail: '₱1.25M · 12 days in negotiation',
    owner: 'Sam Salesrep',
    due: 'Stalled',
    severity: 'warning',
    action: 'Open board',
    to: '/pipeline',
    minTier: 'personal',
  },
  {
    id: 'wl-bpo-handoff',
    kind: 'company',
    subject: 'BPO Solutions PH',
    detail: 'Service handoff failed — retry pending',
    owner: 'Sam Salesrep',
    due: 'Blocked',
    severity: 'danger',
    action: 'Open accounts',
    to: '/clients',
    minTier: 'personal',
  },
  {
    id: 'wl-metro-lead',
    kind: 'lead',
    subject: 'Metro Builders',
    detail: 'Unassigned lead awaiting first touch',
    owner: 'Unassigned',
    due: '3 days',
    severity: 'warning',
    action: 'Claim lead',
    to: '/assignment',
    minTier: 'team',
  },
  {
    id: 'wl-harbor-renewal',
    kind: 'contract',
    subject: 'Harbor Logistics — rate agreement',
    detail: 'Renewal due 29 Oct',
    owner: 'Liza Lopez',
    due: '9 days',
    severity: 'warning',
    action: 'Open clients',
    to: '/clients',
    minTier: 'team',
  },
  {
    id: 'wl-cebu-proposal',
    kind: 'opportunity',
    subject: 'Greenfield Hotels Cebu — housekeeping crew',
    detail: 'Proposal sent 16 days ago; no reply',
    owner: 'Liza Lopez',
    due: 'Stalled',
    severity: 'warning',
    action: 'Open deals',
    to: '/opportunities',
    minTier: 'team',
  },
  {
    id: 'wl-greenfield-churn',
    kind: 'company',
    subject: 'Greenfield Hotels',
    detail: 'Churned in July — reactivation candidate',
    owner: 'Sam Salesrep',
    due: '3 months',
    severity: 'info',
    action: 'Review lifecycle',
    to: '/lifecycle',
    minTier: 'team',
  },
]

/** `won_value` stays numeric here and is formatted only when a row is built. */
type StaffBase = Omit<StaffPerformanceRow, 'won_value'> & { won_value: number }

const staffBase: StaffBase[] = [
  {
    id: 'staff-maria',
    name: 'Maria Manager',
    job_title: 'Sales manager',
    team: 'Business Development',
    initials: 'MM',
    leads_handled: 22,
    qualified: 14,
    deals_won: 5,
    won_value: 1_840_000,
    conversion_rate: 41,
    follow_up_on_time: 96,
    csat: 4.6,
    attendance_rate: 99,
    leave_days: 1,
    late_arrivals: 0,
  },
  {
    id: 'staff-sam',
    name: 'Sam Salesrep',
    job_title: 'Sales representative',
    team: 'Business Development',
    initials: 'SS',
    leads_handled: 86,
    qualified: 52,
    deals_won: 17,
    won_value: 3_240_000,
    conversion_rate: 38,
    follow_up_on_time: 91,
    csat: 4.1,
    attendance_rate: 94,
    leave_days: 3,
    late_arrivals: 2,
  },
  {
    id: 'staff-liza',
    name: 'Liza Lopez',
    job_title: 'Sales representative',
    team: 'Business Development',
    initials: 'LL',
    leads_handled: 74,
    qualified: 46,
    deals_won: 15,
    won_value: 2_980_000,
    conversion_rate: 40,
    follow_up_on_time: 88,
    csat: 4.4,
    attendance_rate: 97,
    leave_days: 1.5,
    late_arrivals: 1,
  },
]

/** Activity counts scale with the range; attendance and CSAT are point-in-time reads. */
const staffRangeFactor: Record<DashboardRange, number> = { '30d': 1, quarter: 2.9, ytd: 6.4 }
const leaveRangeFactor: Record<DashboardRange, number> = { '30d': 1, quarter: 2.5, ytd: 5 }

const buildStaff = (tier: DashboardTier, range: DashboardRange): StaffPerformanceRow[] => {
  const visible =
    tier === 'personal' ? staffBase.filter((row) => row.id === 'staff-sam') : staffBase
  const factor = staffRangeFactor[range]
  const leaveFactor = leaveRangeFactor[range]

  return visible.map((row) => ({
    ...row,
    leads_handled: Math.round(row.leads_handled * factor),
    qualified: Math.round(row.qualified * factor),
    deals_won: Math.round(row.deals_won * factor),
    won_value: formatCurrency(row.won_value * factor),
    leave_days: Math.round(row.leave_days * leaveFactor * 2) / 2,
  }))
}

const staffNote =
  'CRM activity from leads, deals, follow-ups and survey answers. Attendance, leave and late arrivals come from core2 / performance management (HR).'

const aiByTier: Record<DashboardTier, Pick<DashboardAiReport, 'headline' | 'narrative'>> = {
  personal: {
    headline: 'You are ahead on wins — Sunrise needs a call this week.',
    narrative:
      'You closed 33% of your deals in the last 30 days, two points above your team average, and your pipeline is the healthiest it has been this quarter. The exception is Sunrise Foods: a CSAT 2 / NPS 4 answer on 19 October sits beside an overdue rate-sheet follow-up. One call and a revised rate card would clear both. Your next best action is to log the call from the account timeline so the AI report can see it next period.',
  },
  team: {
    headline: 'The team is closing well; two accounts are slipping.',
    narrative:
      "Business Development is at a 36% win rate with ₱4.12M open, and Sam is carrying the largest share of won value. Two signals drag the week down: Sunrise Foods answered the post-deployment survey 2/5 and has an overdue rate-sheet follow-up, while the BPO 40-seat ramp has sat in negotiation for twelve days. Liza's Harbor renewal is nine days out; confirming it now protects roughly ₱1.2M. Attendance is stable at 94–99%, with five and a half leave days taken this period.",
  },
  org: {
    headline: 'Revenue is ahead, but eight accounts need a human this week.',
    narrative:
      'The org is at a 38% win rate with ₱8.64M open and ₱2.72M closed-won in the last 30 days — ahead of the previous period on every acquisition measure. The risk is concentrated: one unhappy survey answer (Sunrise Foods, CSAT 2), one failed service handoff (BPO Solutions), and a negotiation queue where the largest deals have not moved in over ten days. Twenty-seven active clients are healthy, but two contracts expire within 30 days. Staff attendance is 94–99%; the two late arrivals are isolated, not a pattern.',
  },
}

const aiInsights: DashboardAiReport['insights'] = [
  {
    id: 'ai-sunrise',
    title: 'Sunrise is the loudest signal',
    body: "A CSAT 2 / NPS 4 answer on 19 Oct, plus an overdue rate-sheet follow-up. The account is Sam's and still open for business.",
    domain: 'people',
    to: '/satisfaction/responses?attention=true',
    link_label: 'Read the answer',
  },
  {
    id: 'ai-negotiation',
    title: 'Negotiation is where deals wait',
    body: 'The largest open deals have sat in negotiation for more than ten days. The BPO 40-seat ramp alone is ₱1.25M.',
    domain: 'cost',
    to: '/pipeline',
    link_label: 'Open the board',
  },
  {
    id: 'ai-people',
    title: 'Attendance is stable; workload is not',
    body: 'Attendance holds at 94–99%, but follow-up punctuality ranges from 88% to 96% — the overdue queue, not absence, is the drag.',
    domain: 'activity',
    to: '/follow-ups',
    link_label: 'Review follow-ups',
  },
]

const aiActions: DashboardAiReport['actions'] = [
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
    to: '/assignment',
  },
]

const buildAi = (tier: DashboardTier): DashboardAiReport => ({
  simulated: true,
  generated_at: '20 Oct 2026, 09:00',
  model: 'Primepower Insights v0',
  ...aiByTier[tier],
  insights: aiInsights,
  actions: aiActions,
})

const alerts: DashboardAlert[] = [
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
    id: 'alert-follow-ups',
    variant: 'warning',
    title: 'Follow-ups are past due',
    body: 'Six tasks and scheduled sends are overdue across the book.',
    link_label: 'Open the worklist',
    to: '/follow-ups',
    minTier: 'personal',
  },
  {
    id: 'alert-contracts',
    variant: 'info',
    title: '2 contracts expire within 30 days',
    body: 'Harbor Logistics renews on 29 Oct; BPO follows in November.',
    link_label: 'Open clients',
    to: '/clients',
    minTier: 'team',
  },
  {
    id: 'alert-unassigned',
    variant: 'info',
    title: '1 unassigned lead is waiting',
    body: 'Metro Builders has had no first touch for three days.',
    link_label: 'Claim the lead',
    to: '/assignment',
    minTier: 'team',
  },
]

const activityRows: DashboardActivityRow[] = [
  {
    id: 'act-email',
    domain: 'counts',
    title: 'Email logged',
    detail: 'Rate-sheet follow-up sent to Sunrise Foods',
    when: '2 hours ago',
    to: '/communications',
  },
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
    to: '/qualification',
  },
  {
    id: 'act-survey',
    domain: 'activity',
    title: 'Survey answered',
    detail: 'Harbor Logistics scored 5/5 on the check-in',
    when: '2 days ago',
    to: '/satisfaction',
  },
  {
    id: 'act-contract',
    domain: 'cost',
    title: 'Contract signed',
    detail: 'Harbor Logistics rate agreement countersigned',
    when: '6 days ago',
    to: '/clients',
  },
  {
    id: 'act-task',
    domain: 'activity',
    title: 'Follow-up completed',
    detail: 'Sunrise commissary site visit',
    when: '6 days ago',
    to: '/follow-ups',
  },
]

const tierRank: Record<DashboardTier, number> = { personal: 0, team: 1, org: 2 }
const visibleFor = (tier: DashboardTier, minTier: DashboardTier) =>
  tierRank[tier] >= tierRank[minTier]

const buildWorklist = (tier: DashboardTier) =>
  worklistRows.filter((row) => visibleFor(tier, row.minTier))

const buildAlerts = (tier: DashboardTier) => alerts.filter((row) => visibleFor(tier, row.minTier))

/** Deterministic assembly — async on purpose so the page already handles a loading state. */
export async function buildDashboard(role: Role, range: DashboardRange): Promise<DashboardData> {
  const tier = tierForRole(role)

  return {
    simulated: true,
    range,
    updated_at: '20 Oct 2026, 09:00',
    scope: scopeFor(role, tier),
    primary: buildKpis(tier, range).slice(0, 4),
    secondary: buildKpis(tier, range).slice(4),
    line: buildLine(tier, range),
    donut: buildDonut(tier),
    worklist: buildWorklist(tier),
    staff: buildStaff(tier, range),
    staff_note: staffNote,
    ai: buildAi(tier),
    alerts: buildAlerts(tier),
    activity: activityRows,
  }
}
