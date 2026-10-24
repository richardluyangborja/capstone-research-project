import type { Role } from '../types'
import { api } from './api'
import { formatCurrency, formatDateTime, formatRelativeTime } from './format'

/**
 * Dashboard home + management report (module 06, spec 14, ADR-0020).
 *
 * The page reads three read-only endpoints and assembles the same view model the presentation
 * build used, so every component below stays presentational:
 *
 *   GET /api/v1/dashboard?range=30d|quarter|ytd          → scope, KPIs, line, donut, alerts, activity, ai
 *   GET /api/v1/dashboard/worklist?range=…               → worklist rows
 *   GET /api/v1/dashboard/staff-performance?range=…      → staff (CRM) + HR via HrGateway
 *
 * The server scopes every list by the JWT (role is not trusted from the client), sends raw
 * values + units, and leaves formatting here. D3 changes only the `ai` block's source.
 */

export type DashboardRange = '30d' | 'quarter' | 'ytd'

export const DASHBOARD_RANGES: { value: DashboardRange; label: string }[] = [
  { value: '30d', label: 'Last 30 days' },
  { value: 'quarter', label: 'This quarter' },
  { value: 'ytd', label: 'Year to date' },
]

/** The fixed data-domain colour mapping (counts=blue, people=green, activity=purple, cost=orange). */
export type DashboardDomain = 'counts' | 'people' | 'activity' | 'cost'

export type DashboardTier = 'personal' | 'team' | 'org'

export interface DashboardTrend {
  direction: 'up' | 'down' | 'flat'
  label: string
  tone: 'positive' | 'negative' | 'neutral'
}

export interface DashboardKpi {
  id: string
  label: string
  /** Formatted here from the API's raw value + unit (`lib/format.ts` owns the PH rules). */
  value: string
  hint: string
  domain: DashboardDomain
  /** Where the card leads — every dashboard number is clickable. */
  to: string
  trend?: DashboardTrend
}

export interface DashboardScope {
  role: Role
  tier: DashboardTier
  label: string
  description: string
}

export interface DashboardLineSeries {
  name: string
  domain: DashboardDomain
  values: number[]
}

export interface DashboardLine {
  labels: string[]
  series: DashboardLineSeries[]
  caption: string
}

export interface DashboardDonutSegment {
  label: string
  value: number
  display: string
  domain: DashboardDomain
  to?: string
}

export interface DashboardDonut {
  caption: string
  total: string
  segments: DashboardDonutSegment[]
}

export type WorklistKind = 'lead' | 'company' | 'opportunity' | 'follow_up' | 'survey' | 'contract'

export type WorklistSeverity = 'danger' | 'warning' | 'info'

export interface DashboardWorklistRow {
  id: string
  kind: WorklistKind
  subject: string
  detail: string
  owner: string
  due: string
  severity: WorklistSeverity
  action: string
  to: string
  /** Which tier may see the row (personal = everyone, team = manager+, org = admin only). */
  minTier: DashboardTier
}

export interface StaffPerformanceRow {
  id: string
  name: string
  job_title: string
  team: string
  initials: string
  leads_handled: number
  qualified: number
  deals_won: number
  won_value: string
  conversion_rate: number
  follow_up_on_time: number
  /** Null when the rep has no answers in the period — excluded from the team average. */
  csat: number | null
  /** core2 / performance management (HR) figures — null when the gateway cannot answer. */
  attendance_rate: number | null
  leave_days: number | null
  late_arrivals: number | null
}

export interface DashboardAiInsight {
  id: string
  title: string
  body: string
  domain: DashboardDomain
  to: string
  link_label: string
}

export interface DashboardAiAction {
  id: string
  label: string
  description: string
  to: string
}

export interface DashboardAiReport {
  generated_at: string
  model: string
  /** `rules` in D2; D3 records `model` when the Python service answered. */
  source: 'model' | 'rules'
  headline: string
  narrative: string
  insights: DashboardAiInsight[]
  actions: DashboardAiAction[]
}

export interface DashboardAlert {
  id: string
  variant: 'info' | 'success' | 'warning' | 'danger'
  title: string
  body: string
  link_label: string
  to: string
  minTier: DashboardTier
}

export interface DashboardActivityRow {
  id: string
  domain: DashboardDomain
  title: string
  detail: string
  when: string
  to: string
}

export interface DashboardData {
  range: DashboardRange
  updated_at: string
  scope: DashboardScope
  primary: DashboardKpi[]
  secondary: DashboardKpi[]
  line: DashboardLine
  donut: DashboardDonut
  worklist: DashboardWorklistRow[]
  staff: StaffPerformanceRow[]
  staff_note: string
  ai: DashboardAiReport
  alerts: DashboardAlert[]
  activity: DashboardActivityRow[]
}

/* ---------------------------------------------------------------------------------------------
 * The wire contract (spec 14 §Data contract). Kept private: the view model above is what the
 * components consume, and this file is the one place that translates between the two.
 * ------------------------------------------------------------------------------------------- */

type DashboardUnit = 'count' | 'currency' | 'percent'
type TrendUnit = 'count' | 'percent' | 'points'

interface WireTrend {
  direction: DashboardTrend['direction']
  value: number
  unit: TrendUnit
  tone: DashboardTrend['tone']
}

interface WireKpi {
  id: string
  label: string
  value: number
  unit: DashboardUnit
  domain: DashboardDomain
  trend: WireTrend | null
  hint: string
  href: string
}

interface WireScope {
  role: Role
  tier: DashboardTier
  label: string
  description: string
}

interface WireLine {
  caption: string
  labels: string[]
  series: DashboardLineSeries[]
}

interface WireDonut {
  caption: string
  total: number
  segments: { label: string; value: number; domain: DashboardDomain; href: string }[]
}

interface WireAlert {
  id: string
  variant: DashboardAlert['variant']
  title: string
  body: string
  href: string
  link_label: string
  minTier: DashboardTier
}

interface WireActivity {
  id: string
  domain: DashboardDomain
  title: string
  detail: string
  occurred_at: string
  href: string
}

interface WireAiInsight {
  id: string
  title: string
  body: string
  domain: DashboardDomain
  href: string
  link_label: string
}

interface WireAiAction {
  id: string
  label: string
  description: string
  href: string
}

interface WireAi {
  headline: string
  narrative: string
  insights: WireAiInsight[]
  actions: WireAiAction[]
  model: string
  generated_at: string
  source: 'model' | 'rules'
}

interface WireSummary {
  range: DashboardRange
  updated_at: string
  scope: WireScope
  primary: WireKpi[]
  secondary: WireKpi[]
  line: WireLine
  donut: WireDonut
  alerts: WireAlert[]
  activity: WireActivity[]
  ai: WireAi
}

interface WireWorklistRow {
  id: string
  kind: WorklistKind
  subject: string
  detail: string
  owner: { id: number; name: string } | null
  due: { label: string; severity: WorklistSeverity }
  href: string
  action_label: string
  minTier: DashboardTier
}

interface WireStaffRow {
  user: { id: number; name: string; job_title: string; team: string }
  leads_handled: number
  qualified: number
  deals_won: number
  won_value: number
  conversion_rate: number
  follow_up_on_time: number
  csat: number | null
  hr: {
    attendance_rate: number
    leave_days: number
    late_arrivals: number
    source: string
  } | null
  minTier: DashboardTier
}

interface WireStaffPerformance {
  data: WireStaffRow[]
  meta: { period: DashboardRange; hr_available: boolean }
}

interface Envelope<T> {
  data: T
}

const STAFF_NOTE =
  'CRM activity from leads, deals, follow-ups and survey answers. Attendance, leave and late arrivals come from core2 / performance management (HR).'

const STAFF_NOTE_NO_HR =
  'CRM activity from leads, deals, follow-ups and survey answers. core2 HR figures are unavailable right now, so attendance, leave and late arrivals show as —.'

const numberFormatter = new Intl.NumberFormat('en-PH', { maximumFractionDigits: 1 })

const formatNumber = (value: number) => numberFormatter.format(value)

const formatValue = (value: number, unit: DashboardUnit): string => {
  if (unit === 'currency') return formatCurrency(value)
  if (unit === 'percent') return `${formatNumber(value)}%`

  return formatNumber(value)
}

const previousWindow = (range: DashboardRange): string => {
  if (range === 'quarter') return 'previous quarter'
  if (range === 'ytd') return 'previous year'

  return 'previous 30 days'
}

/**
 * The API sends the direction, magnitude, unit and tone; the sentence around it is presentation.
 * A few KPIs read better with their own wording (clients gained, overdue work, attention delta).
 */
const formatTrend = (trend: WireTrend, kpiId: string, range: DashboardRange): DashboardTrend => {
  const previous = previousWindow(range)
  const amount = formatNumber(trend.value)

  if (trend.direction === 'flat') {
    return {
      direction: 'flat',
      label: kpiId === 'follow-ups' ? 'None overdue' : `No change vs ${previous}`,
      tone: trend.tone,
    }
  }

  const sign = trend.direction === 'up' ? '+' : '−'

  if (trend.unit === 'percent') {
    return {
      direction: trend.direction,
      label: `${sign}${amount}% vs ${previous}`,
      tone: trend.tone,
    }
  }

  if (trend.unit === 'points') {
    return {
      direction: trend.direction,
      label: `${sign}${amount} pts vs ${previous}`,
      tone: trend.tone,
    }
  }

  if (kpiId === 'clients') {
    return {
      direction: trend.direction,
      label: `${sign}${amount} ${amount === '1' ? 'account' : 'accounts'}`,
      tone: trend.tone,
    }
  }

  if (kpiId === 'follow-ups') {
    return { direction: trend.direction, label: `${amount} overdue`, tone: trend.tone }
  }

  if (kpiId === 'attention') {
    return {
      direction: trend.direction,
      label: `${amount} ${trend.direction === 'up' ? 'more' : 'fewer'} than ${previous}`,
      tone: trend.tone,
    }
  }

  return {
    direction: trend.direction,
    label: `${sign}${amount} vs ${previous}`,
    tone: trend.tone,
  }
}

const mapKpi = (kpi: WireKpi, range: DashboardRange): DashboardKpi => ({
  id: kpi.id,
  label: kpi.label,
  value: formatValue(kpi.value, kpi.unit),
  hint: kpi.hint,
  domain: kpi.domain,
  to: kpi.href,
  ...(kpi.trend ? { trend: formatTrend(kpi.trend, kpi.id, range) } : {}),
})

const mapDonut = (donut: WireDonut): DashboardDonut => ({
  caption: donut.caption,
  total: formatCurrency(donut.total),
  segments: donut.segments.map((segment) => ({
    label: segment.label,
    value: segment.value,
    display: formatCurrency(segment.value),
    domain: segment.domain,
    to: segment.href,
  })),
})

const mapWorklist = (row: WireWorklistRow): DashboardWorklistRow => ({
  id: row.id,
  kind: row.kind,
  subject: row.subject,
  detail: row.detail,
  owner: row.owner?.name ?? 'Unassigned',
  due: row.due.label,
  severity: row.due.severity,
  action: row.action_label,
  to: row.href,
  minTier: row.minTier,
})

const initialsFor = (name: string): string => {
  const parts = name.trim().split(/\s+/).filter(Boolean)

  if (parts.length === 0) return ''
  if (parts.length === 1) return parts[0].slice(0, 1).toUpperCase()

  return `${parts[0].slice(0, 1)}${parts[parts.length - 1].slice(0, 1)}`.toUpperCase()
}

const mapStaff = (row: WireStaffRow): StaffPerformanceRow => ({
  id: String(row.user.id),
  name: row.user.name,
  job_title: row.user.job_title,
  team: row.user.team,
  initials: initialsFor(row.user.name),
  leads_handled: row.leads_handled,
  qualified: row.qualified,
  deals_won: row.deals_won,
  won_value: formatCurrency(row.won_value),
  conversion_rate: row.conversion_rate,
  follow_up_on_time: row.follow_up_on_time,
  csat: row.csat,
  attendance_rate: row.hr?.attendance_rate ?? null,
  leave_days: row.hr?.leave_days ?? null,
  late_arrivals: row.hr?.late_arrivals ?? null,
})

const mapAi = (ai: WireAi): DashboardAiReport => ({
  generated_at: formatDateTime(ai.generated_at),
  model: ai.model,
  source: ai.source,
  headline: ai.headline,
  narrative: ai.narrative,
  insights: ai.insights.map((insight) => ({
    id: insight.id,
    title: insight.title,
    body: insight.body,
    domain: insight.domain,
    to: insight.href,
    link_label: insight.link_label,
  })),
  actions: ai.actions.map((action) => ({
    id: action.id,
    label: action.label,
    description: action.description,
    to: action.href,
  })),
})

/**
 * The single swap point (kept signature: `role` documents intent, the JWT is the real scope).
 * Every endpoint is read-only and already narrowed server-side.
 */
export async function getDashboard(role: Role, range: DashboardRange): Promise<DashboardData> {
  // The session role is not sent: `/dashboard` derives the scope from the bearer token.
  void role

  const params = { range }
  const [summaryResponse, worklistResponse, staffResponse] = await Promise.all([
    api.get<Envelope<WireSummary>>('/dashboard', { params }),
    api.get<Envelope<WireWorklistRow[]>>('/dashboard/worklist', { params }),
    api.get<WireStaffPerformance>('/dashboard/staff-performance', { params }),
  ])

  const summary = summaryResponse.data.data
  const worklist = worklistResponse.data.data
  const staff = staffResponse.data

  return {
    range: summary.range,
    updated_at: formatDateTime(summary.updated_at),
    scope: summary.scope,
    primary: summary.primary.map((kpi) => mapKpi(kpi, summary.range)),
    secondary: summary.secondary.map((kpi) => mapKpi(kpi, summary.range)),
    line: summary.line,
    donut: mapDonut(summary.donut),
    worklist: worklist.map(mapWorklist),
    staff: staff.data.map(mapStaff),
    staff_note: staff.meta.hr_available ? STAFF_NOTE : STAFF_NOTE_NO_HR,
    ai: mapAi(summary.ai),
    alerts: summary.alerts.map((alert) => ({
      id: alert.id,
      variant: alert.variant,
      title: alert.title,
      body: alert.body,
      link_label: alert.link_label,
      to: alert.href,
      minTier: alert.minTier,
    })),
    activity: summary.activity.map((activity) => ({
      id: activity.id,
      domain: activity.domain,
      title: activity.title,
      detail: activity.detail,
      when: formatRelativeTime(activity.occurred_at),
      to: activity.href,
    })),
  }
}
