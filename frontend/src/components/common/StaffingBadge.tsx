import StatusBadge from './StatusBadge'
import { staffingSummary } from '../../lib/opportunityMeta'

interface StaffingBadgeProps {
  estimatedHeadcount: number | null | undefined
  serviceType: string | null | undefined
  className?: string
}

/**
 * The staffing demand carried by a deal (spec 18 S2) — "25 workers · Temporary Staffing".
 * Renders nothing when the deal has neither a headcount nor a placement type, so cards
 * stay quiet for early enquiries.
 */
export default function StaffingBadge({
  estimatedHeadcount,
  serviceType,
  className,
}: StaffingBadgeProps) {
  const summary = staffingSummary(estimatedHeadcount, serviceType)

  if (!summary) return null

  return (
    <StatusBadge tone="counts" className={className}>
      {summary}
    </StatusBadge>
  )
}
