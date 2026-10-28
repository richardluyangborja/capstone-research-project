import type { Tone } from '../components/common/StatusBadge'
import type { BillingUnit, ContractStatus, HandoffStatus } from '../types'

// Contract lifecycle (spec 09 §1). A signed contract is the live engagement, so it reads
// as people (green); an expired/terminated one is history (neutral/danger).
export const contractStatusMeta: Record<ContractStatus, { label: string; tone: Tone }> = {
  draft: { label: 'Draft', tone: 'neutral' },
  sent: { label: 'Sent for signature', tone: 'counts' },
  signed: { label: 'Signed', tone: 'people' },
  expired: { label: 'Expired', tone: 'neutral' },
  terminated: { label: 'Terminated', tone: 'danger' },
}

export const contractStatusLabels: Record<ContractStatus, string> = Object.fromEntries(
  Object.entries(contractStatusMeta).map(([status, meta]) => [status, meta.label]),
) as Record<ContractStatus, string>

/** Moves the API accepts on `PATCH` — signing has its own action (spec 09 §1). */
export const contractStatusOptions: ContractStatus[] = ['draft', 'sent', 'expired', 'terminated']

/** How a rate-card line is billed (module 01 refresh S7, ADR-0024). */
export const billingUnitLabels: Record<BillingUnit, string> = {
  hourly: 'Hourly',
  monthly: 'Monthly',
  placement: 'Placement',
}

export const billingUnitOptions: BillingUnit[] = ['hourly', 'monthly', 'placement']

export const handoffStatusLabels: Record<HandoffStatus, string> = {
  sent: 'Handed off to Service',
  failed: 'Handoff failed',
}

/**
 * Human-readable expiry countdown for the Contracts tab: `12 days left`, `expired 3 days
 * ago`, or an em dash when the contract has no end date.
 */
export const formatExpiry = (days: number | null): string => {
  if (days === null) {
    return '—'
  }

  if (days < 0) {
    return `expired ${Math.abs(days)} ${Math.abs(days) === 1 ? 'day' : 'days'} ago`
  }

  if (days === 0) {
    return 'expires today'
  }

  return `${days} ${days === 1 ? 'day' : 'days'} left`
}
