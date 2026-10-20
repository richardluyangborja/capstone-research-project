import { api } from './api'
import type { PublicSurvey, PublicSurveyAnswerInput, SurveyResponseSummary } from '../types'

/**
 * The client's page (module 05 slice S2). No session and no token header beyond whatever the
 * shared client happens to hold: the link's token is the credential, so these are the only calls
 * the public page makes (spec 13 §Business rules 6).
 */
export const getPublicSurvey = async (token: string): Promise<PublicSurvey> => {
  const { data } = await api.get<{ data: PublicSurvey }>(`/public/surveys/${token}`)
  return data.data
}

export const submitPublicSurvey = async (
  token: string,
  answers: PublicSurveyAnswerInput[],
): Promise<SurveyResponseSummary> => {
  const { data } = await api.post<{ data: SurveyResponseSummary; message: string }>(
    `/public/surveys/${token}/responses`,
    { answers },
  )
  return data.data
}
