import type { Tone } from '../components/common/StatusBadge'
import type { ActivityStatus, FollowUpDueStateName, FollowUpKind } from '../types'

// Follow-up reminders (module 04, spec 12). Lateness is a problem (danger), today is the work
// in hand (warm), anything further out waits quietly (neutral) — the same token vocabulary the
// communication book uses, never a hardcoded colour (resources/design-tokens.md).
export const followUpDueMeta: Record<FollowUpDueStateName, { label: string; tone: Tone }> = {
  overdue: { label: 'Overdue', tone: 'danger' },
  today: { label: 'Due today', tone: 'warm' },
  upcoming: { label: 'Upcoming', tone: 'neutral' },
}

/** The two shapes a row can be. Only a task exists in slice S1; S2 adds the message. */
export const followUpKindMeta: Record<FollowUpKind, { label: string; tone: Tone }> = {
  task: { label: 'Task', tone: 'cost' },
}

export const taskStatusMeta: Record<ActivityStatus, { label: string; tone: Tone }> = {
  open: { label: 'Open', tone: 'counts' },
  done: { label: 'Done', tone: 'success' },
  cancelled: { label: 'Cancelled', tone: 'neutral' },
}

/** The state filter's options, in the order the header reads them. */
export const followUpStateOptions: { value: string; label: string }[] = [
  { value: 'open', label: 'Still owed' },
  { value: 'overdue', label: 'Overdue' },
  { value: 'today', label: 'Due today' },
  { value: 'upcoming', label: 'Upcoming' },
  { value: 'done', label: 'Done' },
  { value: 'cancelled', label: 'Cancelled' },
  { value: 'all', label: 'Everything' },
]

/**
 * The moment a fresh task is owed: tomorrow at 09:00 in the business calendar, as the
 * `datetime-local` input wants it. Slice S1's default (spec 12 §UI).
 */
export const defaultDueAt = (): string => {
  const tomorrow = new Date()
  tomorrow.setDate(tomorrow.getDate() + 1)
  const pad = (part: number) => String(part).padStart(2, '0')
  return `${tomorrow.getFullYear()}-${pad(tomorrow.getMonth() + 1)}-${pad(tomorrow.getDate())}T09:00`
}
