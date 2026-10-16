import type { Tone } from '../components/common/StatusBadge'
import type {
  ActivityType,
  DisqualificationReason,
  LeadRating,
  LeadSource,
  LeadStatus,
  Urgency,
} from '../types'

export const leadStatusMeta: Record<LeadStatus, { label: string; tone: Tone }> = {
  new: { label: 'New', tone: 'counts' },
  contacted: { label: 'Contacted', tone: 'activity' },
  qualified: { label: 'Qualified', tone: 'people' },
  converted: { label: 'Converted', tone: 'success' },
  disqualified: { label: 'Disqualified', tone: 'danger' },
  nurture: { label: 'Nurture', tone: 'neutral' },
  archived: { label: 'Archived', tone: 'neutral' },
}

export const leadRatingMeta: Record<LeadRating, { label: string; tone: Tone }> = {
  hot: { label: 'Hot', tone: 'hot' },
  warm: { label: 'Warm', tone: 'warm' },
  cold: { label: 'Cold', tone: 'cold' },
}

export const leadSourceLabels: Record<LeadSource, string> = {
  walk_in: 'Walk-in',
  email: 'Email',
  phone: 'Phone',
  referral: 'Referral',
  website: 'Website',
  outbound: 'Outbound',
  event: 'Event',
  partner: 'Partner',
  other: 'Other',
}

export const urgencyLabels: Record<Urgency, string> = {
  low: 'Low',
  medium: 'Medium',
  high: 'High',
}

export const activityTypeMeta: Record<ActivityType, { label: string; tone: Tone }> = {
  call: { label: 'Call', tone: 'people' },
  email: { label: 'Email', tone: 'counts' },
  meeting: { label: 'Meeting', tone: 'activity' },
  walk_in: { label: 'Walk-in', tone: 'people' },
  task: { label: 'Task', tone: 'cost' },
  note: { label: 'Note', tone: 'neutral' },
  system: { label: 'System', tone: 'neutral' },
}

export const disqualificationReasonLabels: Record<DisqualificationReason, string> = {
  no_budget: 'No budget',
  no_need: 'No need',
  competitor: 'Went to competitor',
  unresponsive: 'Unresponsive',
  bad_fit: 'Bad fit',
  duplicate: 'Duplicate',
  other: 'Other',
}
