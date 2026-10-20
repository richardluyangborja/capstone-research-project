import type { Role } from '../types'
import { buildDashboard } from '../mocks/dashboard'

/**
 * Dashboard home + management report (module 06, spec 14, ADR-0020).
 *
 * **The page reads simulated data.** Everything below is the shape the real endpoints will
 * return, so wiring the dashboard to the backend is a one-file change: replace the body of
 * `getDashboard` with the calls named in §5 of the spec and keep every component as it is.
 *
 * Planned endpoints (not built yet):
 *   GET /api/v1/dashboard?range=30d|quarter|ytd          → scope, primary, secondary, line, donut
 *   GET /api/v1/dashboard/worklist?range=…               → worklist, alerts, activity
 *   GET /api/v1/dashboard/staff-performance?range=…      → staff (CRM) + HR figures via HrGateway
 *   POST /ai/reports/dashboard  (Python service)         → ai (falls back to the rules template)
 *
 * The AI report is labelled *Simulated* in the UI until the `ai/` service is agreed and built
 * (resources/system-ai-feature.md). No component may present it as a real model output.
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
  /** Pre-formatted because the fixture is pre-formatted; the real API sends a raw value + unit. */
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
  /** core2 / performance management (HR) figures — mocked behind the future HrGateway. */
  attendance_rate: number
  leave_days: number
  late_arrivals: number
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
  /** True until the `ai/` service exists; the UI must badge it. */
  simulated: true
  generated_at: string
  model: string
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
  /** The UI renders a "Simulated data" badge whenever this is true. */
  simulated: true
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

/** The single swap point: mock today, HTTP tomorrow. Keep the signature. */
export async function getDashboard(role: Role, range: DashboardRange): Promise<DashboardData> {
  return buildDashboard(role, range)
}
