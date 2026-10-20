import type { LucideIcon } from 'lucide-react'
import { Minus, TrendingDown, TrendingUp } from 'lucide-react'
import { Link } from 'react-router-dom'
import type { DashboardTrend } from '../../lib/dashboard'

type Domain = 'counts' | 'people' | 'activity' | 'cost'

interface StatCardProps {
  label: string
  value: string | number
  domain?: Domain
  /** Optional metric icon, tinted with the domain colour. */
  icon?: LucideIcon
  /** One line under the value — what the number means. */
  hint?: string
  /** Period-over-period trend. */
  trend?: DashboardTrend
  /** When present the whole card is a link (dashboard rule: every KPI is clickable). */
  to?: string
}

// Fixed data-domain color mapping (design system rule).
const domainText: Record<Domain, string> = {
  counts: 'text-counts',
  people: 'text-people',
  activity: 'text-activity',
  cost: 'text-cost',
}

const trendTone: Record<DashboardTrend['tone'], string> = {
  positive: 'text-people',
  negative: 'text-destructive',
  neutral: 'text-muted-foreground',
}

const trendIcon: Record<DashboardTrend['direction'], LucideIcon> = {
  up: TrendingUp,
  down: TrendingDown,
  flat: Minus,
}

export default function StatCard({
  label,
  value,
  domain = 'counts',
  icon: Icon,
  hint,
  trend,
  to,
}: StatCardProps) {
  const TrendIcon = trend ? trendIcon[trend.direction] : null

  const body = (
    <>
      <div className="flex items-start justify-between gap-2">
        <p className="text-xs font-medium text-muted-foreground">{label}</p>
        {Icon && <Icon className={`h-4 w-4 shrink-0 ${domainText[domain]}`} aria-hidden />}
      </div>
      <p className={`mt-1 text-2xl font-semibold ${domainText[domain]}`}>{value}</p>
      {trend && TrendIcon && (
        <p className={`mt-1 flex items-center gap-1 text-xs ${trendTone[trend.tone]}`}>
          <TrendIcon className="h-3.5 w-3.5" aria-hidden />
          {trend.label}
        </p>
      )}
      {hint && <p className="mt-1 text-xs text-muted-foreground">{hint}</p>}
    </>
  )

  if (to) {
    return (
      <Link
        to={to}
        className="block rounded-lg border border-border bg-card p-4 transition-colors hover:border-primary/50 hover:bg-secondary/40 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
      >
        {body}
      </Link>
    )
  }

  return <div className="rounded-lg border border-border bg-card p-4">{body}</div>
}
