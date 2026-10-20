import { api } from './api'
import type {
  FollowUpsPage,
  GenerateRemindersResult,
  Task,
  TaskInput,
  TaskUpdateInput,
} from '../types'

export interface FollowUpFilters {
  search?: string
  /** `me`, `team`, `all` or a user id — the tabs and the manager's picker (spec 12 §S1). */
  owner?: string
  /** `open` (the default), `overdue`, `today`, `upcoming`, `done`, `cancelled` or `all`. */
  state?: string
  /** `task` or `message` — the worklist's two shapes (spec 12 §S2). */
  kind?: string
  record_type?: string
  record_id?: string
  due_from?: string
  due_to?: string
  page?: number
}

const buildParams = (filters: FollowUpFilters): Record<string, string> => {
  const params: Record<string, string> = {}
  if (filters.search) params.search = filters.search
  if (filters.owner) params['filter[owner]'] = filters.owner
  if (filters.state) params['filter[state]'] = filters.state
  if (filters.kind) params['filter[kind]'] = filters.kind
  if (filters.record_type) params['filter[record_type]'] = filters.record_type
  if (filters.record_id) params['filter[record_id]'] = filters.record_id
  if (filters.due_from) params['filter[due_from]'] = filters.due_from
  if (filters.due_to) params['filter[due_to]'] = filters.due_to
  if (filters.page && filters.page > 1) params.page = String(filters.page)
  return params
}

/**
 * The worklist — what is owed, by when (spec 12 §S1). A read model over the records that hold a
 * promise, so it is scoped to the records the caller may see (rule 8).
 */
export const listFollowUps = async (filters: FollowUpFilters = {}): Promise<FollowUpsPage> => {
  const { data } = await api.get<FollowUpsPage>('/follow-ups', { params: buildParams(filters) })
  return data
}

export const getTask = async (id: string): Promise<Task> => {
  const { data } = await api.get<{ data: Task }>(`/tasks/${id}`)
  return data.data
}

/** Put something owed on a record. The record's own rights decide whether the caller may. */
export const createTask = async (input: TaskInput): Promise<Task> => {
  const { data } = await api.post<{ data: Task }>('/tasks', input)
  return data.data
}

export const updateTask = async (id: string, input: TaskUpdateInput): Promise<Task> => {
  const { data } = await api.patch<{ data: Task }>(`/tasks/${id}`, input)
  return data.data
}

/**
 * Complete, cancel or reopen (spec 12 §S1). The API runs the state machine, so an illegal move
 * comes back as a 422 — the caller could act on the task, just not that way.
 */
export const updateTaskStatus = async (id: string, status: string): Promise<Task> => {
  const { data } = await api.patch<{ data: Task }>(`/tasks/${id}/status`, { status })
  return data.data
}

/**
 * Flag every open task that has fallen due (spec 12 §S3, §10): the in-app twin of
 * `follow-ups:notify-due`. Admin-only, because one run notifies owners across every team, and
 * nothing runs on a timer in this phase — the demo clicks this.
 */
export const dispatchDueFollowUps = async (): Promise<{ notified: number }> => {
  const { data } = await api.post<{ data: { notified: number } }>('/follow-ups/dispatch-due')
  return data.data
}

/**
 * Raise the reminders the records imply (spec 12 §S4, §11): the in-app twin of
 * `follow-ups:generate`. Manager+ — one run writes across every team — and it reports each
 * source's created and skipped rows, so "no owner" stays visible instead of silent.
 */
export const generateFollowUps = async (): Promise<GenerateRemindersResult> => {
  const { data } = await api.post<{ data: GenerateRemindersResult }>('/follow-ups/generate')
  return data.data
}
