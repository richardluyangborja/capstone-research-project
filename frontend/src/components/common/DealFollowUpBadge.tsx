import StatusBadge from './StatusBadge'
import { followUpDueMeta } from '../../lib/followUpMeta'
import { followUpWarningStages } from '../../lib/opportunityMeta'
import type { Opportunity } from '../../types'

interface DealFollowUpBadgeProps {
  deal: Pick<Opportunity, 'is_open' | 'stage' | 'next_follow_up'>
  className?: string
}

/**
 * The deal's follow-up state on the board and in the table (spec 18 S4): the earliest open
 * task's derived due state, or a quiet warning on an open proposal/negotiation with nothing
 * scheduled. Renders nothing when there is neither a task nor a reason to prompt one.
 */
export default function DealFollowUpBadge({ deal, className }: DealFollowUpBadgeProps) {
  if (deal.next_follow_up) {
    const meta = followUpDueMeta[deal.next_follow_up.state]

    return (
      <StatusBadge tone={meta.tone} className={className}>
        {meta.label}
      </StatusBadge>
    )
  }

  if (deal.is_open && followUpWarningStages.includes(deal.stage)) {
    return (
      <StatusBadge tone="warm" className={className}>
        No follow-up scheduled
      </StatusBadge>
    )
  }

  return null
}
