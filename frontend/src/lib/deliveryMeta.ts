import type { Tone } from '../components/common/StatusBadge'
import type { DeploymentStatus, TimesheetStatus } from '../types'

/** Shared presentation for the read-only Deployment / Timekeeping feeds (S9/S10). */
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
