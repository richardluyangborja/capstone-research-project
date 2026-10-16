import { api } from './api'
import type { Lead, Paginated } from '../types'

export interface LeadFilters {
  search?: string
  status?: string
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

export const listLeads = async (filters: LeadFilters = {}): Promise<Paginated<Lead>> => {
  const params: Record<string, string> = {}
  if (filters.search) params.search = filters.search
  if (filters.status) params['filter[status]'] = filters.status
  if (filters.source) params['filter[source]'] = filters.source
  if (filters.owner) params['filter[owner]'] = filters.owner

  const { data } = await api.get<Paginated<Lead>>('/leads', { params })
  return data
}

export const createLead = async (input: NewLeadInput): Promise<CreatedLeadResponse> => {
  const { data } = await api.post<CreatedLeadResponse>('/leads', input)
  return data
}
