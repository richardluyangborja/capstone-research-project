import { api } from './api'
import type {
  Survey,
  SurveyInput,
  SurveyInvitation,
  SurveyInvitationInput,
  SurveyInvitationsPage,
  SurveysPage,
} from '../types'
import type { SurveyState } from './surveyMeta'
import type { SurveyInvitationStateName } from '../types'

/**
 * How the library narrows (slice S1). `state` is one choice rather than two knobs: a survey is a
 * draft, sendable, or retired.
 */
export interface SurveyFilters {
  search?: string
  state?: SurveyState | ''
  page?: number
}

const buildParams = (filters: SurveyFilters): Record<string, string> => {
  const params: Record<string, string> = {}
  if (filters.search) params.search = filters.search
  if (filters.state === 'active') params['filter[active]'] = 'true'
  if (filters.state === 'inactive') params['filter[active]'] = 'false'
  if (filters.state === 'retired') params['filter[retired]'] = 'true'
  if (filters.page && filters.page > 1) params.page = String(filters.page)
  return params
}

/** The survey library (module 05 §UI, slice S1) — active first, then by name. */
export const listSurveys = async (filters: SurveyFilters = {}): Promise<SurveysPage> => {
  const { data } = await api.get<SurveysPage>('/surveys', { params: buildParams(filters) })
  return data
}

export const getSurvey = async (id: string): Promise<Survey> => {
  const { data } = await api.get<{ data: Survey }>(`/surveys/${id}`)
  return data.data
}

/** Author one — manager+ only (the API answers 403 for a salesrep). */
export const createSurvey = async (input: SurveyInput): Promise<Survey> => {
  const { data } = await api.post<{ data: Survey }>('/surveys', input)
  return data.data
}

export const updateSurvey = async (id: string, input: Partial<SurveyInput>): Promise<Survey> => {
  const { data } = await api.patch<{ data: Survey }>(`/surveys/${id}`, input)
  return data.data
}

/** Retire one (soft) — its questions and whatever it gathered stay readable. */
export const retireSurvey = async (id: string): Promise<void> => {
  await api.delete(`/surveys/${id}`)
}

export const restoreSurvey = async (id: string): Promise<Survey> => {
  const { data } = await api.post<{ data: Survey }>(`/surveys/${id}/restore`)
  return data.data
}

/** Copy the questions into a new draft — the way to version a survey once it has been sent. */
export const duplicateSurvey = async (id: string): Promise<Survey> => {
  const { data } = await api.post<{ data: Survey }>(`/surveys/${id}/duplicate`)
  return data.data
}

/**
 * How the invitation book narrows (slice S2). `state` reads the derived state, so `expired` finds
 * a row the database still stores as `sent`.
 */
export interface SurveyInvitationFilters {
  search?: string
  state?: SurveyInvitationStateName | ''
  survey?: string
  company?: string
  page?: number
}

const buildInvitationParams = (filters: SurveyInvitationFilters): Record<string, string> => {
  const params: Record<string, string> = {}
  if (filters.search) params.search = filters.search
  if (filters.state) params['filter[state]'] = filters.state
  if (filters.survey) params['filter[survey]'] = filters.survey
  if (filters.company) params['filter[company]'] = filters.company
  if (filters.page && filters.page > 1) params.page = String(filters.page)
  return params
}

/** The invitation book (module 05 §UI, slice S2), scoped to the caller's accounts. */
export const listSurveyInvitations = async (
  filters: SurveyInvitationFilters = {},
): Promise<SurveyInvitationsPage> => {
  const { data } = await api.get<SurveyInvitationsPage>('/survey-invitations', {
    params: buildInvitationParams(filters),
  })
  return data
}

/** The account's sends — the Satisfaction tab. */
export const listCompanySurveyInvitations = async (
  companyId: string,
  page = 1,
): Promise<SurveyInvitationsPage> => {
  const { data } = await api.get<SurveyInvitationsPage>(
    `/companies/${companyId}/survey-invitations`,
    { params: page > 1 ? { page: String(page) } : undefined },
  )
  return data
}

export const getSurveyInvitation = async (id: string): Promise<SurveyInvitation> => {
  const { data } = await api.get<{ data: SurveyInvitation }>(`/survey-invitations/${id}`)
  return data.data
}

/**
 * Send a survey to one account (201 whether the mocked gateway accepted or rejected it — the
 * invitation's state says which).
 */
export const sendSurveyInvitation = async (
  surveyId: string,
  input: SurveyInvitationInput,
): Promise<SurveyInvitation> => {
  const { data } = await api.post<{ data: SurveyInvitation }>(
    `/surveys/${surveyId}/invitations`,
    input,
  )
  return data.data
}

/** Withdraw an unanswered invitation: the token stops working at once. */
export const cancelSurveyInvitation = async (id: string): Promise<SurveyInvitation> => {
  const { data } = await api.post<{ data: SurveyInvitation }>(
    `/survey-invitations/${id}/cancel`,
  )
  return data.data
}

/** Hand the same invitation to the gateway again — the retry, and a fresh window for an expiry. */
export const resendSurveyInvitation = async (id: string): Promise<SurveyInvitation> => {
  const { data } = await api.post<{ data: SurveyInvitation }>(
    `/survey-invitations/${id}/resend`,
  )
  return data.data
}
