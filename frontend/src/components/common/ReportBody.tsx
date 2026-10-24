import { ArrowRight, ArrowUpRight } from 'lucide-react'
import { Link } from 'react-router-dom'
import type { DashboardDomain } from '../../lib/dashboard'
import type { ManagementReportAction, ManagementReportInsight } from '../../lib/reports'

interface ReportBodyProps {
  headline: string
  narrative: string
  insights: ManagementReportInsight[]
  actions: ManagementReportAction[]
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
 * The report's written body: headline, narrative, the three insight cards and the four action
 * links. Shared by the dashboard summary (module 06) and `/reports` (module 07) so both read the
 * same shape and every link points at the page that owns the work.
 */
export default function ReportBody({ headline, narrative, insights, actions }: ReportBodyProps) {
  return (
    <>
      <p className="text-lg font-semibold text-foreground">{headline}</p>
      <p className="mt-2 max-w-4xl text-sm leading-relaxed text-muted-foreground">{narrative}</p>

      <div className="mt-4 grid grid-cols-1 gap-3 md:grid-cols-3">
        {insights.map((insight) => (
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
          {actions.map((action) => (
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
    </>
  )
}
