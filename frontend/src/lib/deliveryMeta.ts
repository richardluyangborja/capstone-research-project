import type { Tone } from '../components/common/StatusBadge'
import type { DeliveryPriority, DeploymentStatus, JobOrderStatus, TimesheetStatus } from '../types'

/** Shared presentation for the read-only Recruitment / Deployment / Timekeeping feeds. */
export const jobOrderMeta: Record<JobOrderStatus, { label: string; tone: Tone }> = {
  open: { label: 'Open', tone: 'counts' },
  partially_filled: { label: 'Partially filled', tone: 'warm' },
  filled: { label: 'Filled', tone: 'success' },
  closed: { label: 'Closed', tone: 'neutral' },
  cancelled: { label: 'Cancelled', tone: 'danger' },
}

export const priorityMeta: Record<DeliveryPriority, { label: string; tone: Tone }> = {
  high: { label: 'High', tone: 'hot' },
  medium: { label: 'Medium', tone: 'warm' },
  low: { label: 'Low', tone: 'cold' },
}

export const deploymentMeta: Record<DeploymentStatus, { label: string; tone: Tone }> = {
  pending: { label: 'Pending', tone: 'warm' },
  active: { label: 'Active', tone: 'success' },
  ended: { label: 'Ended', tone: 'neutral' },
}

export const timesheetMeta: Record<TimesheetStatus, { label: string; tone: Tone }> = {
  current: { label: 'Current', tone: 'success' },
  pending: { label: 'Pending', tone: 'warm' },
  missing: { label: 'Missing', tone: 'danger' },
}

export const formatHours = (hours: number): string =>
  `${new Intl.NumberFormat('en-PH', { maximumFractionDigits: 2 }).format(hours)} hrs`
