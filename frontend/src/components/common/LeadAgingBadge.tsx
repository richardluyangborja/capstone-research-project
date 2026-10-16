import StatusBadge from './StatusBadge'
import { agingMeta } from '../../lib/leadMeta'
import type { AgingLevel } from '../../types'

/**
 * Aging flag for a lead (spec 04 §3). Thresholds are computed by the API; this
 * renders nothing when the lead is healthy, terminal or archived.
 */
export default function LeadAgingBadge({
  aging,
  days,
  className,
}: {
  aging: AgingLevel | null
  days?: number
  className?: string
}) {
  if (!aging) return null

  const meta = agingMeta[aging]

  return (
    <StatusBadge tone={meta.tone} className={className}>
      {meta.label}
      {days !== undefined ? ` · ${days}d` : ''}
    </StatusBadge>
  )
}
