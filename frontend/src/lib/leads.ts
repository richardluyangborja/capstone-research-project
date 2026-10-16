import { api } from './api'
import type { Lead, Paginated } from '../types'

export interface LeadFilters {
  search?: string
  status?: string
  rating?: string
  source?: string
  owner?: string
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

export const rateLead = async (id: string, input: { rating?: string; score?: number }): Promise<Lead> => {
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
