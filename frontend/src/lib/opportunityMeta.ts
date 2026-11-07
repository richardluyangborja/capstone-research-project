import type { Tone } from '../components/common/StatusBadge'
import type { LostReason, OpportunityStage, ServiceType } from '../types'

// Deal pipeline (ADR-0002). Pipeline work reads as activity (purple), the commercial
// outcome as people (green, won) or danger (lost) — see implementation/design-tokens.md.
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

/** Staffing placement types (spec 18 S2). */
export const serviceTypeLabels: Record<ServiceType, string> = {
  temporary_staffing: 'Temporary Staffing',
  contract_to_hire: 'Contract to Hire',
  direct_hire: 'Direct Hire',
  project_based: 'Project Based',
}

export const serviceTypeOptions = Object.entries(serviceTypeLabels).map(([value, label]) => ({
  value: value as ServiceType,
  label,
}))

/**
 * A deal's service type is usually one of the four placement types, but a converted lead
 * may carry its free-text service interest. Never hide an unrecognised value — humanise it.
 */
export const serviceTypeLabel = (value: string | null | undefined): string | null => {
  if (!value) return null

  const known = serviceTypeLabels[value as ServiceType]
  if (known) return known

  return value
    .replace(/_/g, ' ')
    .replace(/\b\w/g, (letter) => letter.toUpperCase())
}

/** A short "25 workers" line for the staffing badge on cards and tables. */
export const staffingSummary = (
  headcount: number | null | undefined,
  serviceType: string | null | undefined,
): string | null => {
  const parts: string[] = []
  if (headcount) parts.push(`${headcount} ${headcount === 1 ? 'worker' : 'workers'}`)
  const placement = serviceTypeLabel(serviceType)
  if (placement) parts.push(placement)

  return parts.length > 0 ? parts.join(' · ') : null
}

/** Stages a conversion may open a deal at (spec 08 §1 — always `qualified`). */
export const conversionOpeningStage: OpportunityStage = 'qualified'

// --- Module 02: opportunity pipeline ---------------------------------------

/** The board columns, in pipeline order (module 02 §1). `won`/`lost` are terminal. */
export const openStageOrder: OpportunityStage[] = [
  'new_inquiry',
  'qualified',
  'discovery',
  'proposal_sent',
  'negotiation',
  'on_hold',
]

export const isTerminalStage = (stage: OpportunityStage): boolean =>
  stage === 'won' || stage === 'lost'
