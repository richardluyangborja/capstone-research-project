import type { Survey, SurveyInvitationStateName, SurveyQuestionTypeName } from '../types'

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

/** A rating shows as `4.6 / 5`, an NPS as a whole score, and a missing one as an em dash. */
export const formatCsat = (csat: number | null | undefined): string =>
  csat === null || csat === undefined ? '—' : `${csat.toFixed(1)} / 5`

export const formatNps = (nps: number | null | undefined): string =>
  nps === null || nps === undefined ? '—' : String(nps)
