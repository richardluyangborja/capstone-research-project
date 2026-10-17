import type { Tone } from '../components/common/StatusBadge'
import type { LostReason, OpportunityStage } from '../types'

// Deal pipeline (ADR-0002). Pipeline work reads as activity (purple), the commercial
// outcome as people (green, won) or danger (lost) — see resources/design-tokens.md.
export const opportunityStageMeta: Record<OpportunityStage, { label: string; tone: Tone }> = {
  new_inquiry: { label: 'New inquiry', tone: 'neutral' },
  qualified: { label: 'Qualified', tone: 'counts' },
  discovery: { label: 'Discovery', tone: 'counts' },
  proposal_sent: { label: 'Proposal sent', tone: 'activity' },
  negotiation: { label: 'Negotiation', tone: 'activity' },
  won: { label: 'Won', tone: 'people' },
  lost: { label: 'Lost', tone: 'danger' },
  on_hold: { label: 'On hold', tone: 'warm' },
}

export const opportunityStageLabels: Record<OpportunityStage, string> = Object.fromEntries(
  Object.entries(opportunityStageMeta).map(([stage, meta]) => [stage, meta.label]),
) as Record<OpportunityStage, string>

export const lostReasonLabels: Record<LostReason, string> = {
  price: 'Price',
  competitor: 'Lost to competitor',
  no_budget: 'No budget',
  timing: 'Bad timing',
  no_decision: 'No decision',
  other: 'Other',
}

/** Stages a conversion may open a deal at (spec 08 §1 — always `qualified`). */
export const conversionOpeningStage: OpportunityStage = 'qualified'
