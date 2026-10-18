import { api } from './api'
import { communicationSubjectPath } from './communicationMeta'
import type {
  Communication,
  CommunicationHistory,
  CommunicationInput,
  CommunicationsPage,
  CommunicationSubjectType,
} from '../types'

export interface CommunicationFilters {
  search?: string
  channel?: string
  direction?: string
  status?: string
  subject_type?: string
  subject_id?: string
  company?: string
  contact?: string
  owner?: string
  date_from?: string
  date_to?: string
  page?: number
}

const buildParams = (filters: CommunicationFilters): Record<string, string> => {
  const params: Record<string, string> = {}
  if (filters.search) params.search = filters.search
  if (filters.channel) params['filter[channel]'] = filters.channel
  if (filters.direction) params['filter[direction]'] = filters.direction
  if (filters.status) params['filter[status]'] = filters.status
  if (filters.subject_type) params['filter[subject_type]'] = filters.subject_type
  if (filters.subject_id) params['filter[subject_id]'] = filters.subject_id
  if (filters.company) params['filter[company]'] = filters.company
  if (filters.contact) params['filter[contact]'] = filters.contact
  if (filters.owner) params['filter[owner]'] = filters.owner
  if (filters.date_from) params['filter[date_from]'] = filters.date_from
  if (filters.date_to) params['filter[date_to]'] = filters.date_to
  if (filters.page && filters.page > 1) params.page = String(filters.page)
  return params
}

/** The communication book (module 03 §UI) — scoped to records the caller may see. */
export const listCommunications = async (
  filters: CommunicationFilters = {},
): Promise<CommunicationsPage> => {
  const { data } = await api.get<CommunicationsPage>('/communications', {
    params: buildParams(filters),
  })
  return data
}

export const getCommunication = async (id: string): Promise<Communication> => {
  const { data } = await api.get<{ data: Communication }>(`/communications/${id}`)
  return data.data
}

/** Log a message against a record. The API mirrors it onto that record's timeline. */
export const createCommunication = async (input: CommunicationInput): Promise<Communication> => {
  const { data } = await api.post<{ data: Communication }>('/communications', input)
  return data.data
}

/** Only a `draft`/`logged` record accepts an edit — a sent one is a 422 (spec 11 §Business rules 4). */
export const updateCommunication = async (
  id: string,
  input: Partial<CommunicationInput>,
): Promise<Communication> => {
  const { data } = await api.patch<{ data: Communication }>(`/communications/${id}`, input)
  return data.data
}

export const deleteCommunication = async (id: string): Promise<void> => {
  await api.delete(`/communications/${id}`)
}

export const restoreCommunication = async (id: string): Promise<Communication> => {
  const { data } = await api.post<{ data: Communication }>(`/communications/${id}/restore`)
  return data.data
}

/** One record's history — the Communications tab/section on a lead, account, contact, deal or contract. */
export const listCommunicationsFor = async (
  subjectType: CommunicationSubjectType,
  subjectId: string,
): Promise<CommunicationHistory> => {
  const { data } = await api.get<CommunicationHistory>(
    `/${communicationSubjectPath[subjectType]}/${subjectId}/communications`,
  )
  return data
}
