import { isAxiosError } from 'axios'
import type { DashboardDomain, DashboardRange, DashboardScope } from './dashboard'
import { api } from './api'
import { formatDateTime } from './format'

/**
 * Module 07 — the management report (spec 15).
 *
 * `GET /reports/management` returns cached model prose when one exists, otherwise the standard
 * rules report; it never calls the provider. `POST /reports/management/generate` is the on-demand
 * path. Both return the same report shape, so the page maps provenance and fallback once.
 */

export type ManagementReportVariant = 'executive' | 'pipeline' | 'client_health'

export const REPORT_VARIANTS: {
  value: ManagementReportVariant
  label: string
  description: string
}[] = [
  {
    value: 'executive',
    label: 'Executive',
    description: 'The state of the book this period.',
  },
  {
    value: 'pipeline',
    label: 'Pipeline',
    description: 'Where the value sits, and what is stuck.',
  },
  {
    value: 'client_health',
    label: 'Client Health',
    description: 'Which clients need care right now.',
  },
]

export type ManagementReportFallbackReason =
  'not_configured' | 'rate_limited' | 'timeout' | 'provider_error' | 'invalid_response'

export interface ManagementReportFallback {
  reason: ManagementReportFallbackReason
  message: string
}

export interface ManagementReportInsight {
  id: string
  title: string
  body: string
  domain: DashboardDomain
  to: string
  link_label: string
}

export interface ManagementReportAction {
  id: string
  label: string
  description: string
  to: string
}

export interface ManagementReport {
  variant: ManagementReportVariant
  title: string
  headline: string
  narrative: string
  insights: ManagementReportInsight[]
  actions: ManagementReportAction[]
  generated_at: string
  source: 'model' | 'rules'
  model: string
  cached: boolean
  fallback: ManagementReportFallback | null
}

export interface ManagementReportMeta {
  range: DashboardRange
  scope: DashboardScope
  /** Reflected from the server switch; false means the standard report is the current one. */
  ai_available: boolean
  variants: ManagementReportVariant[]
}

export interface ManagementReportData {
  report: ManagementReport
  meta: ManagementReportMeta
}

interface WireInsight {
  id: string
  title: string
  body: string
  domain: DashboardDomain
  href: string
  link_label: string
}

interface WireAction {
  id: string
  label: string
  description: string
  href: string
}

interface WireReport {
  variant: ManagementReportVariant
  title: string
  headline: string
  narrative: string
  insights: WireInsight[]
  actions: WireAction[]
  generated_at: string
  source: 'model' | 'rules'
  model: string
  cached: boolean
  fallback: ManagementReportFallback | null
}

interface WireResponse {
  data: WireReport
  meta: ManagementReportMeta
}

/** A refresh asked for inside the server's cooldown; the current report is still valid. */
export class ReportCooldownError extends Error {
  readonly retryAfterSeconds: number

  constructor(retryAfterSeconds: number) {
    super('Try again in a few minutes.')
    this.name = 'ReportCooldownError'
    this.retryAfterSeconds = retryAfterSeconds
  }
}

const mapReport = (report: WireReport): ManagementReport => ({
  variant: report.variant,
  title: report.title,
  headline: report.headline,
  narrative: report.narrative,
  insights: report.insights.map((insight) => ({
    id: insight.id,
    title: insight.title,
    body: insight.body,
    domain: insight.domain,
    to: insight.href,
    link_label: insight.link_label,
  })),
  actions: report.actions.map((action) => ({
    id: action.id,
    label: action.label,
    description: action.description,
    to: action.href,
  })),
  generated_at: formatDateTime(report.generated_at),
  source: report.source,
  model: report.model,
  cached: report.cached,
  fallback: report.fallback,
})

/**
 * One read. The period and variant are the only client inputs; the scope comes from the JWT.
 */
export async function getManagementReport(
  range: DashboardRange,
  variant: ManagementReportVariant,
): Promise<ManagementReportData> {
  const response = await api.get<WireResponse>('/reports/management', {
    params: { range, variant },
  })

  return {
    report: mapReport(response.data.data),
    meta: response.data.meta,
  }
}

/**
 * On-demand generation. `refresh` bypasses the server cache but is subject to the per-user
 * cooldown; a 429 keeps the current report on screen and surfaces a retry horizon.
 */
export async function generateManagementReport(
  range: DashboardRange,
  variant: ManagementReportVariant,
  refresh = false,
): Promise<ManagementReportData> {
  try {
    const response = await api.post<WireResponse>('/reports/management/generate', {
      range,
      variant,
      refresh,
    })

    return {
      report: mapReport(response.data.data),
      meta: response.data.meta,
    }
  } catch (error) {
    if (isAxiosError(error) && error.response?.status === 429) {
      const seconds = Number(error.response.data?.retry_after_seconds ?? 0)

      throw new ReportCooldownError(Number.isFinite(seconds) ? seconds : 0)
    }

    throw error
  }
}
