import { ArrowRight, Sparkles } from 'lucide-react'
import { Link } from 'react-router-dom'
import type { DashboardAiReport } from '../../lib/dashboard'
import ReportBody from '../common/ReportBody'

interface AiReportPanelProps {
  report: DashboardAiReport
}

/**
 * The dashboard's management summary (spec 14 §7, ADR-0020). It stays rules-based; the variant
 * page, provider seam and generated reports live in Module 07 (spec 15, ADR-0021). The card links
 * to `/reports` and never spends a provider call.
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

      <div className="mt-4">
        <ReportBody
          headline={report.headline}
          narrative={report.narrative}
          insights={report.insights}
          actions={report.actions}
        />
      </div>

      <div className="mt-4 border-t border-border pt-3">
        <Link
          to="/reports"
          className="inline-flex items-center gap-1 text-xs font-medium text-primary hover:underline"
        >
          Open management reports
          <ArrowRight className="h-3.5 w-3.5" aria-hidden />
        </Link>
      </div>
    </section>
  )
}
