import type { Opportunity, OpportunityStage } from '../../types'
import { opportunityStageLabels } from '../../lib/opportunityMeta'

interface StageMoveControlProps {
  deal: Opportunity
  /** The page decides whether a move needs a lost reason before calling the API. */
  onRequestMove: (stage: OpportunityStage) => void
  disabled?: boolean
  className?: string
}

/**
 * The accessible way to move a deal (module 02 §UI): a `<select>` lists every legal
 * target stage (`allowed_transitions`) so the board works without drag-and-drop. The value
 * is always reset — the move itself is what matters, not a lingering selection.
 */
export default function StageMoveControl({
  deal,
  onRequestMove,
  disabled,
  className,
}: StageMoveControlProps) {
  const options = deal.allowed_transitions.filter((stage) => stage !== deal.stage)

  return (
    <select
      value=""
      disabled={disabled || options.length === 0}
      aria-label={`Move ${deal.reference} to another stage`}
      onChange={(event) => {
        if (event.target.value) onRequestMove(event.target.value as OpportunityStage)
      }}
      className={`rounded-md border border-input bg-card px-2 py-1 text-xs text-foreground outline-none focus:ring-2 focus:ring-ring disabled:opacity-60 ${className ?? ''}`}
    >
      <option value="">Move to…</option>
      {options.map((stage) => (
        <option key={stage} value={stage}>
          {opportunityStageLabels[stage]}
        </option>
      ))}
    </select>
  )
}
