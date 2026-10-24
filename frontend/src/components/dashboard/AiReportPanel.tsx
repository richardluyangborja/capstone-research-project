import { ArrowRight, ArrowUpRight, Sparkles } from 'lucide-react'
import { Link } from 'react-router-dom'
import type { DashboardAiReport, DashboardDomain } from '../../lib/dashboard'

interface AiReportPanelProps {
  report: DashboardAiReport
}

const toneBorder: Record<DashboardDomain, string> = {
  counts: 'border-counts/40 hover:border-counts',
  people: 'border-people/40 hover:border-people',
  activity: 'border-activity/40 hover:border-activity',
  cost: 'border-cost/40 hover:border-cost',
}

const toneText: Record<DashboardDomain, string> = {
  counts: 'text-counts',
  people: 'text-people',
  activity: 'text-activity',
  cost: 'text-cost',
}

const toneDot: Record<DashboardDomain, string> = {
  counts: 'bg-counts',
  people: 'bg-people',
  activity: 'bg-activity',
  cost: 'bg-cost',
}

/**
 * The AI management report (spec 14 §7, ADR-0020). D2 serves the rules-based block from Laravel;
 * D3 may serve the Python service behind the same contract. The component already consumes the
 * final contract, so the source changes without a UI change.
 */
export default function AiReportPanel({ report }: AiReportPanelProps) {
  return (
    <section
      className="rounded-lg border border-border bg-card p-5"
      aria-labelledby="ai-report-title"
    >
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div className="flex items-start gap-3">
          <span className="rounded-lg bg-activity/15 p-2 text-activity" aria-hidden>
            <Sparkles className="h-4 w-4" />
          </span>
          <div>
            <h3 id="ai-report-title" className="text-sm font-semibold text-foreground">
              AI management report
            </h3>
            <p className="mt-0.5 text-xs text-muted-foreground">
              Generated {report.generated_at} · {report.model}
            </p>
          </div>
        </div>
      </div>

      <p className="mt-4 text-lg font-semibold text-foreground">{report.headline}</p>
      <p className="mt-2 max-w-4xl text-sm leading-relaxed text-muted-foreground">
        {report.narrative}
      </p>

      <div className="mt-4 grid grid-cols-1 gap-3 md:grid-cols-3">
        {report.insights.map((insight) => (
          <Link
            key={insight.id}
            to={insight.to}
            className={`group flex flex-col rounded-lg border bg-background p-3 transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring ${toneBorder[insight.domain]}`}
          >
            <div className="flex items-center justify-between gap-2">
              <span
                className={`inline-flex items-center gap-1.5 text-xs font-medium ${toneText[insight.domain]}`}
              >
                <span className={`h-2 w-2 rounded-full ${toneDot[insight.domain]}`} aria-hidden />
                {insight.title}
              </span>
              <ArrowRight
                className="h-3.5 w-3.5 text-muted-foreground transition-transform group-hover:translate-x-0.5"
                aria-hidden
              />
            </div>
            <p className="mt-2 flex-1 text-xs leading-relaxed text-muted-foreground">
              {insight.body}
            </p>
            <span className="mt-2 text-xs font-medium text-foreground">{insight.link_label}</span>
          </Link>
        ))}
      </div>

      <div className="mt-4 border-t border-border pt-3">
        <p className="text-xs font-medium text-muted-foreground">Suggested next actions</p>
        <div className="mt-2 grid grid-cols-1 gap-2 sm:grid-cols-2 lg:grid-cols-4">
          {report.actions.map((action) => (
            <Link
              key={action.id}
              to={action.to}
              className="group flex items-start justify-between gap-2 rounded-md border border-border bg-background px-3 py-2 hover:border-primary/50 hover:bg-secondary/40"
            >
              <span>
                <span className="block text-xs font-medium text-foreground">{action.label}</span>
                <span className="mt-0.5 block text-xs text-muted-foreground">
                  {action.description}
                </span>
              </span>
              <ArrowUpRight
                className="mt-0.5 h-3.5 w-3.5 shrink-0 text-muted-foreground"
                aria-hidden
              />
            </Link>
          ))}
        </div>
      </div>
    </section>
  )
}
