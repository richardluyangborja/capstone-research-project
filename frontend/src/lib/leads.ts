import { api } from './api'
import type {
  Activity,
  AssignmentTarget,
  Lead,
  LeadSummary,
  LeadTimeline,
  Paginated,
} from '../types'

export interface LeadFilters {
  search?: string
  status?: string
  rating?: string
  source?: string
  owner?: string
  stale?: string
  archived?: string
}

export interface NewLeadInput {
  company_name: string
  contact_name: string
  contact_email?: string
  contact_phone?: string
  contact_position?: string
  source: string
  source_detail?: string
  industry?: string
  location?: string
  service_interest?: string
  estimated_headcount?: number
  needed_roles?: string
  target_start_date?: string
  urgency?: string
  notes?: string
}

export interface CreatedLeadResponse {
  data: Lead
  duplicates: { id: string; reference: string; company_name: string }[]
}

export interface QualificationInput {
  estimated_headcount: number | null
  needed_roles: string | null
  target_start_date: string | null
  budget_rate?: number | null
  authority_confirmed: boolean
  work_site: string | null
  urgency: string | null
  qualification_notes: string | null
}

export interface ValidationErrors {
  message: string
  errors: Record<string, string[]>
}

const buildParams = (filters: LeadFilters): Record<string, string> => {
  const params: Record<string, string> = {}
  if (filters.search) params.search = filters.search
  if (filters.status) params['filter[status]'] = filters.status
  if (filters.rating) params['filter[rating]'] = filters.rating
  if (filters.source) params['filter[source]'] = filters.source
  if (filters.owner) params['filter[owner]'] = filters.owner
  if (filters.stale) params['filter[stale]'] = filters.stale
  if (filters.archived) params['filter[archived]'] = filters.archived
  return params
}

export const listLeads = async (filters: LeadFilters = {}): Promise<Paginated<Lead>> => {
  const { data } = await api.get<Paginated<Lead>>('/leads', { params: buildParams(filters) })
  return data
}

export const getLead = async (id: string): Promise<Lead> => {
  const { data } = await api.get<{ data: Lead }>(`/leads/${id}`)
  return data.data
}

export const createLead = async (input: NewLeadInput): Promise<CreatedLeadResponse> => {
  const { data } = await api.post<CreatedLeadResponse>('/leads', input)
  return data
}

export const updateQualification = async (id: string, input: QualificationInput): Promise<Lead> => {
  const { data } = await api.patch<{ data: Lead }>(`/leads/${id}/qualification`, input)
  return data.data
}

export const rateLead = async (
  id: string,
  input: { rating?: string; score?: number },
): Promise<Lead> => {
  const { data } = await api.post<{ data: Lead }>(`/leads/${id}/rate`, input)
  return data.data
}

export const qualifyLead = async (id: string): Promise<Lead> => {
  const { data } = await api.post<{ data: Lead }>(`/leads/${id}/qualify`)
  return data.data
}

export const disqualifyLead = async (
  id: string,
  input: { reason: string; notes?: string },
): Promise<Lead> => {
  const { data } = await api.post<{ data: Lead }>(`/leads/${id}/disqualify`, input)
  return data.data
}

export const nurtureLead = async (id: string): Promise<Lead> => {
  const { data } = await api.post<{ data: Lead }>(`/leads/${id}/nurture`)
  return data.data
}

// --- Submodule 03: assignment & ownership ---------------------------------

export const claimLead = async (id: string): Promise<Lead> => {
  const { data } = await api.post<{ data: Lead }>(`/leads/${id}/claim`)
  return data.data
}

export const assignLead = async (id: string, ownerId: number): Promise<Lead> => {
  const { data } = await api.post<{ data: Lead }>(`/leads/${id}/assign`, { owner_id: ownerId })
  return data.data
}

export const bulkAssignLeads = async (
  leadIds: string[],
  ownerId: number,
): Promise<{ assigned: number }> => {
  const { data } = await api.post<{ data: { assigned: number } }>('/leads/bulk-assign', {
    lead_ids: leadIds,
    owner_id: ownerId,
  })
  return data.data
}

export const bulkTransferLeads = async (
  fromUserId: number,
  toUserId: number,
): Promise<{ transferred: number }> => {
  const { data } = await api.post<{ data: { transferred: number } }>('/leads/bulk-transfer', {
    from_user_id: fromUserId,
    to_user_id: toUserId,
  })
  return data.data
}

export const getAssignableUsers = async (): Promise<AssignmentTarget[]> => {
  const { data } = await api.get<{ data: AssignmentTarget[] }>('/users/assignable')
  return data.data
}

export const getLeadSummary = async (): Promise<LeadSummary> => {
  const { data } = await api.get<{ data: LeadSummary }>('/leads/summary')
  return data.data
}

export const getLeadTimeline = async (id: string): Promise<LeadTimeline> => {
  const { data } = await api.get<LeadTimeline>(`/leads/${id}/timeline`)
  return data
}

// --- Submodule 04: lifecycle & status --------------------------------------

export const updateLeadStatus = async (
  id: string,
  input: { status: string; reason?: string; note?: string },
): Promise<Lead> => {
  const { data } = await api.patch<{ data: Lead }>(`/leads/${id}/status`, input)
  return data.data
}

export const archiveLead = async (id: string): Promise<Lead> => {
  const { data } = await api.post<{ data: Lead }>(`/leads/${id}/archive`)
  return data.data
}

export interface LogActivityInput {
  type: string
  subject: string
  body?: string
  occurred_at?: string
}

export const logLeadActivity = async (id: string, input: LogActivityInput): Promise<Lead> => {
  const { data } = await api.post<{ data: Lead; activity: Activity }>(
    `/leads/${id}/activities`,
    input,
  )
  return data.data
}

/**
 * Extract a Laravel 422 payload (`{ message, errors }`) from an axios error, or null.
 */
export const parseValidationError = (error: unknown): ValidationErrors | null => {
  const response = (error as { response?: { status?: number; data?: unknown } }).response
  if (response?.status !== 422) return null

  const data = response.data as { message?: string; errors?: Record<string, string[]> }
  return {
    message: data.message ?? 'Please correct the highlighted fields.',
    errors: data.errors ?? {},
  }
}
