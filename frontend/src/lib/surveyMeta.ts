import type {
  Survey,
  SurveyBandName,
  SurveyInvitationStateName,
  SurveyQuestionTypeName,
} from '../types'

/** How each question type reads in the builder and in the client's preview (spec 13 §S1). */
export interface SurveyQuestionTypeMeta {
  label: string
  hint: string
  /** The two types that need an options list; the other three must not carry one. */
  hasOptions: boolean
}

export const surveyQuestionTypeMeta: Record<SurveyQuestionTypeName, SurveyQuestionTypeMeta> = {
  rating: {
    label: 'Rating (1–5)',
    hint: 'A satisfaction score — the account’s CSAT is the average of these answers.',
    hasOptions: false,
  },
  nps: {
    label: 'Net promoter (0–10)',
    hint: 'The recommendation question. A survey can ask it once.',
    hasOptions: false,
  },
  single_choice: {
    label: 'Single choice',
    hint: 'One option from a list.',
    hasOptions: true,
  },
  multi_choice: {
    label: 'Multiple choice',
    hint: 'Any number of options.',
    hasOptions: true,
  },
  text: {
    label: 'Free text',
    hint: 'A comment, in the client’s own words.',
    hasOptions: false,
  },
}

export const surveyQuestionTypes = Object.keys(
  surveyQuestionTypeMeta,
) as SurveyQuestionTypeName[]

/** The three states a library row can be in — a draft, sendable, or retired (soft-deleted). */
export type SurveyState = 'active' | 'inactive' | 'retired'

export const surveyStateMeta: Record<
  SurveyState,
  { label: string; tone: 'success' | 'neutral' | 'danger' }
> = {
  active: { label: 'Active', tone: 'success' },
  inactive: { label: 'Draft', tone: 'neutral' },
  retired: { label: 'Retired', tone: 'danger' },
}

export const surveyStateOf = (survey: Survey): SurveyState =>
  survey.deleted_at !== null ? 'retired' : survey.is_active ? 'active' : 'inactive'

/** How an invitation state reads and colors (spec 13 §UI, slice S2). */
export const surveyInvitationStateMeta: Record<
  SurveyInvitationStateName,
  { label: string; tone: 'counts' | 'success' | 'neutral' | 'danger' }
> = {
  sent: { label: 'Awaiting a reply', tone: 'counts' },
  expired: { label: 'Expired', tone: 'neutral' },
  failed: { label: 'Failed to send', tone: 'danger' },
  submitted: { label: 'Submitted', tone: 'success' },
  cancelled: { label: 'Cancelled', tone: 'neutral' },
}

/** The states the book can filter by, in the order a person looks for them. */
export const surveyInvitationStates: SurveyInvitationStateName[] = [
  'sent',
  'submitted',
  'expired',
  'failed',
  'cancelled',
]

/** A rating shows as `4.6 / 5`, an NPS answer as a whole score, a missing one as an em dash. */
export const formatCsat = (csat: number | null | undefined): string =>
  csat === null || csat === undefined ? '—' : `${csat.toFixed(1)} / 5`

export const formatNps = (nps: number | null | undefined): string =>
  nps === null || nps === undefined ? '—' : String(nps)

/** The org NPS runs −100…100, so its sign is part of the reading: `+40`, `−20`, `0`. */
export const formatNpsScore = (nps: number | null | undefined): string => {
  if (nps === null || nps === undefined) return '—'

  return nps > 0 ? `+${nps}` : String(nps)
}

/** A response rate reads as a share with one decimal, or an em dash before anything was asked. */
export const formatRate = (rate: number | null | undefined): string =>
  rate === null || rate === undefined ? '—' : `${rate.toFixed(1)}%`

/** How the three promoter bands read and color (spec 13 §Data model). */
export const surveyBandMeta: Record<
  SurveyBandName,
  { label: string; tone: 'success' | 'neutral' | 'danger' }
> = {
  promoter: { label: 'Promoter', tone: 'success' },
  passive: { label: 'Passive', tone: 'neutral' },
  detractor: { label: 'Detractor', tone: 'danger' },
}

/** The three bands in the order a split reads: the good news first. */
export const surveyBandNames: SurveyBandName[] = ['promoter', 'passive', 'detractor']
