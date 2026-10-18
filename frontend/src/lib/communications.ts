import { api } from './api'
import { communicationSubjectPath } from './communicationMeta'
import type {
  Communication,
  CommunicationHistory,
  CommunicationInput,
  CommunicationReplyInput,
  CommunicationsPage,
  CommunicationSubjectType,
  CommunicationSyncResult,
  CommunicationThreadDetail,
  CommunicationThreadsPage,
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

/**
 * What a conversation list can be narrowed by (slice S4). Fewer knobs than the book on
 * purpose: a conversation holds both directions and every status, so filtering those would
 * only ever hide half of it.
 */
export interface CommunicationThreadFilters {
  search?: string
  channel?: string
  subject_type?: string
  subject_id?: string
  owner?: string
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

/**
 * Hand a draft to the email gateway (spec 11 §S2). The response is the record as it ended up —
 * `sent` with the gateway's id, or `failed` with its reason, which is a delivery outcome rather
 * than an HTTP error.
 */
export const sendCommunication = async (id: string): Promise<Communication> => {
  const { data } = await api.post<{ data: Communication }>(`/communications/${id}/send`)
  return data.data
}

/** Try a message the gateway rejected again (spec 11 §S2). */
export const retryCommunication = async (id: string): Promise<Communication> => {
  const { data } = await api.post<{ data: Communication }>(`/communications/${id}/retry`)
  return data.data
}

/**
 * Pull the mocked mailbox's replies into the history (spec 11 §S2, §Business rules 7). Inbound
 * is pulled, never pushed — this is what the `communication:sync-inbound` command runs, exposed
 * for the demo. Admin-only, because one pull writes history across every team.
 */
export const syncInboundCommunications = async (): Promise<CommunicationSyncResult> => {
  const { data } = await api.post<{
    data: { recorded: number }
    meta: { source: string }
  }>('/communications/sync-inbound')
  return { recorded: data.data.recorded, source: data.meta.source }
}

export const restoreCommunication = async (id: string): Promise<Communication> => {
  const { data } = await api.post<{ data: Communication }>(`/communications/${id}/restore`)
  return data.data
}

/**
 * Answer a message (spec 11 §S4). The reply joins the parent's conversation and cannot choose
 * the record — that belongs to the conversation, not to the message. Only the parent's own
 * state decides whether it may be answered at all (a 422 for a draft, a scheduled follow-up or
 * a send the gateway rejected).
 */
export const replyToCommunication = async (
  id: string,
  input: CommunicationReplyInput,
): Promise<Communication> => {
  const { data } = await api.post<{ data: Communication }>(`/communications/${id}/reply`, input)
  return data.data
}

/** The conversations the caller may see, newest first (spec 11 §S4). */
export const listCommunicationThreads = async (
  filters: CommunicationThreadFilters = {},
): Promise<CommunicationThreadsPage> => {
  const params: Record<string, string> = {}
  if (filters.search) params.search = filters.search
  if (filters.channel) params['filter[channel]'] = filters.channel
  if (filters.subject_type) params['filter[subject_type]'] = filters.subject_type
  if (filters.subject_id) params['filter[subject_id]'] = filters.subject_id
  if (filters.owner) params['filter[owner]'] = filters.owner
  if (filters.page && filters.page > 1) params.page = String(filters.page)

  const { data } = await api.get<CommunicationThreadsPage>('/communications/threads', { params })
  return data
}

/** One conversation in full, oldest message first (spec 11 §S4). */
export const getCommunicationThread = async (
  threadId: string,
): Promise<CommunicationThreadDetail> => {
  const { data } = await api.get<{ data: CommunicationThreadDetail }>(
    `/communications/threads/${threadId}`,
  )
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
