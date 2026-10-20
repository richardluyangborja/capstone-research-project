import { api } from './api'
import type { Survey, SurveyInput, SurveysPage } from '../types'
import type { SurveyState } from './surveyMeta'

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
