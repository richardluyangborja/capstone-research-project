import type { Tone } from '../components/common/StatusBadge'
import type {
  OperationCheckSource,
  OperationCheckStatusName,
  OperationsPlatformSummary,
  OperationsSummary,
} from '../types'
import { getOperationsHealth, getOperationsSummary } from './admin'

/**
 * The operational health view model (module 08 slice S6, spec 19 §S6).
 *
 * The server owns the boundary: an admin's summary has `platform: null`. This module only
 * decides presentation — the status tone, the source label, the platform counters — so the page
 * stays a rendering of what it was given and never filters an authorization decision itself.
 */

export const operationStatusMeta: Record<
  OperationCheckStatusName,
  { label: string; tone: Tone; description: string }
> = {
  healthy: {
    label: 'Healthy',
    tone: 'success',
    description: 'The dependency answered its check.',
  },
  degraded: {
    label: 'Degraded',
    tone: 'warm',
    description: 'The dependency works with a caveat that needs reading.',
  },
  unavailable: {
    label: 'Unavailable',
    tone: 'danger',
    description: 'The dependency could not be reached.',
  },
  not_configured: {
    label: 'Not configured',
    tone: 'neutral',
    description: 'The subsystem is not set up in this environment.',
  },
}

export const operationSourceLabel: Record<OperationCheckSource, string> = {
  application: 'Application',
  mocked: 'Mocked',
  external: 'External service',
}

export const operationStatusOrder: OperationCheckStatusName[] = [
  'healthy',
  'degraded',
  'unavailable',
  'not_configured',
]

/** A check needs an operator's eye when it degraded or failed outright. */
export const needsAttention = (status: OperationCheckStatusName): boolean =>
  status === 'degraded' || status === 'unavailable'

export interface HealthRollup {
  total: number
  counts: Record<OperationCheckStatusName, number>
  attention: number
  /** The headline the page shows above the cards. */
  headline: string
  /** The overall tone for the summary strip. */
  tone: Tone
}

/**
 * Roll the checks up into the dashboard headline. The worst status wins: one unavailable
 * dependency outranks any number of healthy ones, and a mocked feed keeps the strip honest.
 */
export const rollUpHealth = (
  checks: Array<{ status: OperationCheckStatusName }> = [],
): HealthRollup => {
  const counts = { healthy: 0, degraded: 0, unavailable: 0, not_configured: 0 } as Record<
    OperationCheckStatusName,
    number
  >

  for (const check of checks) {
    counts[check.status] = (counts[check.status] ?? 0) + 1
  }

  const total = checks.length
  const attention = counts.degraded + counts.unavailable

  if (total === 0) {
    return { total, counts, attention, headline: 'No checks reported', tone: 'neutral' }
  }

  if (counts.unavailable > 0) {
    return {
      total,
      counts,
      attention,
      headline: `${counts.unavailable} ${plural(counts.unavailable, 'dependency', 'dependencies')} unavailable`,
      tone: 'danger',
    }
  }

  if (counts.degraded > 0) {
    return {
      total,
      counts,
      attention,
      headline: `${counts.degraded} ${plural(counts.degraded, 'check needs', 'checks need')} attention`,
      tone: 'warm',
    }
  }

  return {
    total,
    counts,
    attention,
    headline: 'All application dependencies answered',
    tone: 'success',
  }
}

/**
 * The label for a platform schema state — an absent or unreadable schema is stated, never
 * rendered as zero.
 */
export const schemaStateLabel = (
  state: OperationsPlatformSummary['schema']['state'],
): { label: string; tone: Tone } => {
  switch (state) {
    case 'current':
      return { label: 'Current', tone: 'success' }
    case 'pending':
      return { label: 'Pending migrations', tone: 'warm' }
    case 'unavailable':
      return { label: 'Unavailable', tone: 'danger' }
    default:
      return { label: 'Not configured', tone: 'neutral' }
  }
}

/** The fixed order the platform entity counters are shown in. */
export const entityTotalOrder: Array<{ key: string; label: string }> = [
  { key: 'leads', label: 'Leads' },
  { key: 'accounts', label: 'Accounts' },
  { key: 'contacts', label: 'Contacts' },
  { key: 'opportunities', label: 'Opportunities' },
  { key: 'contracts', label: 'Contracts' },
  { key: 'communications', label: 'Communications' },
  { key: 'users', label: 'Users' },
]

/**
 * The two read-only calls, in parallel. They are independent — a failing health probe should
 * never blank the summary, so each caller decides what to show for its own failure.
 */
export const loadOperations = async (): Promise<{
  summary: OperationsSummary | null
  summaryError: string
  health: Awaited<ReturnType<typeof getOperationsHealth>> | null
  healthError: string
}> => {
  const [summaryResult, healthResult] = await Promise.allSettled([
    getOperationsSummary(),
    getOperationsHealth(),
  ])

  return {
    summary: summaryResult.status === 'fulfilled' ? summaryResult.value : null,
    summaryError:
      summaryResult.status === 'rejected' ? 'Could not load the operations summary.' : '',
    health: healthResult.status === 'fulfilled' ? healthResult.value : null,
    healthError: healthResult.status === 'rejected' ? 'Could not load the health checks.' : '',
  }
}

const plural = (count: number, one: string, many: string): string => (count === 1 ? one : many)
