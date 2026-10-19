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

/** The two shapes a row can be: a task, or a scheduled message (slice S2). */
export const followUpKindMeta: Record<FollowUpKind, { label: string; tone: Tone }> = {
  task: { label: 'Task', tone: 'cost' },
  message: { label: 'Email', tone: 'activity' },
}

export const taskStatusMeta: Record<ActivityStatus, { label: string; tone: Tone }> = {
  open: { label: 'Open', tone: 'counts' },
  done: { label: 'Done', tone: 'success' },
  cancelled: { label: 'Cancelled', tone: 'neutral' },
}

/**
 * A row's state, whichever shape it is. A task moves through `open`/`done`/`cancelled`; a
 * scheduled message has not moved anywhere yet — it is simply owed until it goes out, so one
 * extra label joins the same lookup rather than a second table for one value.
 */
export const followUpRowStatusMeta: Record<string, { label: string; tone: Tone }> = {
  ...taskStatusMeta,
  scheduled: { label: 'Scheduled', tone: 'warm' },
}

/** How a row is narrowed by shape — the worklist's kind filter (spec 12 §S2). */
export const followUpKindOptions: { value: string; label: string }[] = [
  { value: '', label: 'Both shapes' },
  { value: 'task', label: 'Tasks' },
  { value: 'message', label: 'Scheduled emails' },
]

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
 * The moment a fresh follow-up is owed: tomorrow at 09:00 in the business calendar, as the
 * `datetime-local` input wants it. Slice S1's default, reused by the composer's schedule
 * control (module 04 §S2).
 */
export const defaultDueAt = (): string => {
  const tomorrow = new Date()
  tomorrow.setDate(tomorrow.getDate() + 1)
  const pad = (part: number) => String(part).padStart(2, '0')
  return `${tomorrow.getFullYear()}-${pad(tomorrow.getMonth() + 1)}-${pad(tomorrow.getDate())}T09:00`
}
