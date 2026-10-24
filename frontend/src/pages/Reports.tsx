import { useEffect, useState } from 'react'
import type { LucideIcon } from 'lucide-react'
import { BriefcaseBusiness, Gauge, RefreshCw, Sparkles, SquareKanban } from 'lucide-react'
import PageHeader from '../components/common/PageHeader'
import StatusBadge from '../components/common/StatusBadge'
import AlertItem from '../components/common/AlertItem'
import EmptyState from '../components/common/EmptyState'
import ReportBody from '../components/common/ReportBody'
import { DASHBOARD_RANGES, type DashboardRange } from '../lib/dashboard'
import {
  REPORT_VARIANTS,
  ReportCooldownError,
  generateManagementReport,
  getManagementReport,
  type ManagementReportData,
  type ManagementReportVariant,
} from '../lib/reports'

const variantIcons: Record<ManagementReportVariant, LucideIcon> = {
  executive: BriefcaseBusiness,
  pipeline: SquareKanban,
  client_health: Gauge,
}

/**
 * Module 07 — AI Management Reports (spec 15).
 *
 * The page is a scoped read: the JWT decides the book, the two controls decide the question and
 * the period. The standard report is always complete; the AI version is requested on demand and
 * either replaces the prose or returns a rules report with a fallback reason. Nothing here can
 * widen the caller's scope.
 */
export default function Reports() {
  const [range, setRange] = useState<DashboardRange>('30d')
  const [variant, setVariant] = useState<ManagementReportVariant>('executive')
  const [data, setData] = useState<ManagementReportData | null>(null)
  const [loading, setLoading] = useState(true)
  const [generating, setGenerating] = useState(false)
  const [error, setError] = useState('')
  const [generateError, setGenerateError] = useState('')
  const [reloadKey, setReloadKey] = useState(0)

  useEffect(() => {
    let cancelled = false
    setLoading(true)
    setError('')
    setGenerateError('')
    setData(null)

    void getManagementReport(range, variant)
      .then((next) => {
        if (!cancelled) setData(next)
      })
      .catch(() => {
        if (!cancelled) {
          setError('Could not build the management report. Check the API and try again.')
        }
      })
      .finally(() => {
        if (!cancelled) setLoading(false)
      })

    return () => {
      cancelled = true
    }
  }, [range, variant, reloadKey])

  const report = data?.report ?? null

  const handleGenerate = async (refresh: boolean) => {
    setGenerating(true)
    setGenerateError('')

    try {
      setData(await generateManagementReport(range, variant, refresh))
    } catch (caught) {
      setGenerateError(
        caught instanceof ReportCooldownError
          ? 'Try again in a few minutes.'
          : 'Could not generate the AI report. The current report is still shown.',
      )
    } finally {
      setGenerating(false)
    }
  }

  return (
    <div className="flex flex-col gap-6" aria-busy={loading || generating}>
      <PageHeader
        title="AI Management Reports"
        description={
          data
            ? `${data.meta.scope.label} · ${data.meta.scope.description}`
            : 'A scoped management report over the book, the pipeline and client health.'
        }
        actions={
          <div className="flex flex-wrap items-center justify-end gap-2">
            {data?.meta.ai_available && report && (report.source !== 'model' || report.cached) && (
              <button
                type="button"
                onClick={() => void handleGenerate(report.cached)}
                disabled={generating || loading}
                className="inline-flex items-center gap-1.5 rounded-md bg-primary px-3 py-1.5 text-xs font-semibold text-primary-foreground transition-colors hover:bg-primary/90 disabled:opacity-60"
              >
                {report.cached ? (
                  <RefreshCw
                    className={`h-3.5 w-3.5 ${generating ? 'animate-spin' : ''}`}
                    aria-hidden
                  />
                ) : (
                  <Sparkles className="h-3.5 w-3.5" aria-hidden />
                )}
                {generating ? 'Generating…' : report.cached ? 'Refresh AI report' : 'Generate with AI'}
              </button>
            )}
            <div
              className="inline-flex rounded-md border border-border bg-card p-0.5"
              role="group"
              aria-label="Reporting period"
            >
              {DASHBOARD_RANGES.map((option) => {
                const active = range === option.value
                return (
                  <button
                    key={option.value}
                    type="button"
                    aria-pressed={active}
                    disabled={loading || generating}
                    onClick={() => setRange(option.value)}
                    className={`rounded px-2.5 py-1 text-xs font-medium transition-colors disabled:opacity-60 ${
                      active
                        ? 'bg-primary text-primary-foreground'
                        : 'text-muted-foreground hover:text-foreground'
                    }`}
                  >
                    {option.label}
                  </button>
                )
              })}
            </div>
          </div>
        }
      />

      <div
        className="grid grid-cols-1 gap-2 sm:grid-cols-3"
        role="tablist"
        aria-label="Report variant"
      >
        {REPORT_VARIANTS.map((option) => {
          const Icon = variantIcons[option.value]
          const active = variant === option.value

          return (
            <button
              key={option.value}
              id={`report-tab-${option.value}`}
              type="button"
              role="tab"
              aria-selected={active}
              aria-controls="management-report"
              disabled={loading || generating}
              onClick={() => setVariant(option.value)}
              className={`flex items-start gap-3 rounded-lg border p-4 text-left transition-colors disabled:opacity-60 ${
                active
                  ? 'border-primary bg-secondary/60'
                  : 'border-border bg-card hover:border-primary/50 hover:bg-secondary/40'
              }`}
            >
              <span
                className={`rounded-md p-2 ${
                  active ? 'bg-primary text-primary-foreground' : 'bg-muted text-muted-foreground'
                }`}
                aria-hidden
              >
                <Icon className="h-4 w-4" />
              </span>
              <span>
                <span className="block text-sm font-semibold text-foreground">{option.label}</span>
                <span className="mt-0.5 block text-xs text-muted-foreground">
                  {option.description}
                </span>
              </span>
            </button>
          )
        })}
      </div>

      {error && (
        <AlertItem variant="danger" title="Report unavailable">
          <div className="flex flex-wrap items-center justify-between gap-3">
            <p>{error}</p>
            <button
              type="button"
              onClick={() => setReloadKey((key) => key + 1)}
              className="inline-flex items-center gap-1 rounded-md border border-border bg-card px-3 py-1.5 text-xs font-medium text-foreground hover:bg-secondary"
            >
              <RefreshCw className="h-3.5 w-3.5" aria-hidden />
              Try again
            </button>
          </div>
        </AlertItem>
      )}

      {generateError && (
        <AlertItem variant="warning" title="AI generation paused">
          {generateError}
        </AlertItem>
      )}

      {loading && !data ? (
        <p
          role="status"
          className="rounded-lg border border-border bg-card p-8 text-center text-sm text-muted-foreground"
        >
          Building the management report…
        </p>
      ) : null}

      {!loading && !error && !data ? (
        <EmptyState
          title="No report yet"
          description="The standard report could not be assembled for this period."
        />
      ) : null}

      {data && report && (
        <>
          {report.fallback && (
            <AlertItem variant="info" title="AI report unavailable">
              {report.fallback.message}
            </AlertItem>
          )}

          <section
            id="management-report"
            role="tabpanel"
            aria-labelledby={`report-tab-${variant}`}
            className="rounded-lg border border-border bg-card"
          >
            <header className="border-b border-border p-5">
              <div className="flex flex-wrap items-start justify-between gap-3">
                <div className="flex items-start gap-3">
                  <span className="rounded-lg bg-activity/15 p-2 text-activity" aria-hidden>
                    <Sparkles className="h-4 w-4" />
                  </span>
                  <div>
                    <div className="flex flex-wrap items-center gap-2">
                      <h3 className="text-sm font-semibold text-foreground">{report.title}</h3>
                      <StatusBadge tone={report.source === 'model' ? 'people' : 'activity'}>
                        {report.source === 'model' ? 'AI-generated' : 'Standard report'}
                      </StatusBadge>
                      {report.cached && <StatusBadge tone="neutral">Cached</StatusBadge>}
                    </div>
                    <p className="mt-1 text-xs text-muted-foreground">
                      Generated {report.generated_at} · {report.model}
                    </p>
                    {!data.meta.ai_available && (
                      <p className="mt-1 text-xs text-muted-foreground">
                        AI generation is not configured in this build; the standard report is
                        current.
                      </p>
                    )}
                  </div>
                </div>
              </div>
            </header>

            <div className="p-5">
              <ReportBody
                headline={report.headline}
                narrative={report.narrative}
                insights={report.insights}
                actions={report.actions}
              />
            </div>
          </section>
        </>
      )}
    </div>
  )
}
