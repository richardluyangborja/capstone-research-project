import { api } from './api'
import type {
  CompanySatisfaction,
  Paginated,
  SatisfactionSummary,
  SurveyResponseFilters,
  SurveyResponseSummary,
} from '../types'

const buildParams = (filters: SurveyResponseFilters): Record<string, string> => {
  const params: Record<string, string> = {}
  if (filters.survey) params['filter[survey]'] = filters.survey
  if (filters.company) params['filter[company]'] = filters.company
  if (filters.band) params['filter[band]'] = filters.band
  if (filters.attention) params['filter[attention]'] = filters.attention
  if (filters.date_from) params['filter[date_from]'] = filters.date_from
  if (filters.date_to) params['filter[date_to]'] = filters.date_to
  if (filters.search) params.search = filters.search
  if (filters.page && filters.page > 1) params.page = String(filters.page)
  return params
}

/** Every answer in view, newest first (module 05 slice S3) — the response book. */
export const listSurveyResponses = async (
  filters: SurveyResponseFilters = {},
): Promise<Omit<Paginated<SurveyResponseSummary>, 'links'>> => {
  const { data } = await api.get<Omit<Paginated<SurveyResponseSummary>, 'links'>>(
    '/survey-responses',
    { params: buildParams(filters) },
  )
  return data
}

/** One answer set, with its answers in the order the survey asked them. */
export const getSurveyResponse = async (id: string): Promise<SurveyResponseSummary> => {
  const { data } = await api.get<{ data: SurveyResponseSummary }>(`/survey-responses/${id}`)
  return data.data
}

/** The org rollup the satisfaction page is built on — scoped by the caller's accounts. */
export const getSatisfactionSummary = async (): Promise<SatisfactionSummary> => {
  const { data } = await api.get<{ data: SatisfactionSummary }>('/satisfaction/summary')
  return data.data
}

/** One account's card: the same numbers, its latest answer and a short trend. */
export const getCompanySatisfaction = async (companyId: string): Promise<CompanySatisfaction> => {
  const { data } = await api.get<{ data: CompanySatisfaction }>(
    `/companies/${companyId}/satisfaction`,
  )
  return data.data
}
