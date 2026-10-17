import { api } from './api'
import type { Activity, Company, CompanySite, CompanyTimeline, Paginated } from '../types'

export interface CompanyFilters {
  search?: string
  status?: string
  tier?: string
  industry?: string
  parent_company_id?: string
  page?: number
}

export interface CompanyInput {
  name: string
  trade_name?: string | null
  industry?: string | null
  size?: string | null
  website?: string | null
  description?: string | null
  status?: string
  tier?: string | null
  client_since?: string | null
  parent_company_id?: string | null
  owner_id?: number | null
  tags?: string[]
}

export interface CompanySiteInput {
  label: string
  street_address?: string | null
  barangay?: string | null
  city_municipality?: string | null
  province?: string | null
  region?: string | null
  postal_code?: string | null
  is_primary?: boolean
}

export interface DuplicateCompany {
  id: string
  reference: string
  name: string
  status: string
}

export interface CreatedCompanyResponse {
  data: Company
  duplicates: DuplicateCompany[]
}

const buildParams = (filters: CompanyFilters): Record<string, string> => {
  const params: Record<string, string> = {}
  if (filters.search) params.search = filters.search
  if (filters.status) params['filter[status]'] = filters.status
  if (filters.tier) params['filter[tier]'] = filters.tier
  if (filters.industry) params['filter[industry]'] = filters.industry
  if (filters.parent_company_id) params['filter[parent_company_id]'] = filters.parent_company_id
  if (filters.page && filters.page > 1) params.page = String(filters.page)
  return params
}

export const listCompanies = async (filters: CompanyFilters = {}): Promise<Paginated<Company>> => {
  const { data } = await api.get<Paginated<Company>>('/companies', { params: buildParams(filters) })
  return data
}

export const getCompany = async (id: string): Promise<Company> => {
  const { data } = await api.get<{ data: Company }>(`/companies/${id}`)
  return data.data
}

export const createCompany = async (input: CompanyInput): Promise<CreatedCompanyResponse> => {
  const { data } = await api.post<CreatedCompanyResponse>('/companies', input)
  return data
}

export const updateCompany = async (id: string, input: Partial<CompanyInput>): Promise<Company> => {
  const { data } = await api.patch<{ data: Company }>(`/companies/${id}`, input)
  return data.data
}

export const deleteCompany = async (id: string): Promise<void> => {
  await api.delete(`/companies/${id}`)
}

export const restoreCompany = async (id: string): Promise<Company> => {
  const { data } = await api.post<{ data: Company }>(`/companies/${id}/restore`)
  return data.data
}

export const listCompanySites = async (id: string): Promise<CompanySite[]> => {
  const { data } = await api.get<{ data: CompanySite[] }>(`/companies/${id}/sites`)
  return data.data
}

export const createCompanySite = async (
  id: string,
  input: CompanySiteInput,
): Promise<CompanySite> => {
  const { data } = await api.post<{ data: CompanySite }>(`/companies/${id}/sites`, input)
  return data.data
}

export const updateCompanySite = async (
  id: string,
  siteId: string,
  input: Partial<CompanySiteInput>,
): Promise<CompanySite> => {
  const { data } = await api.patch<{ data: CompanySite }>(`/companies/${id}/sites/${siteId}`, input)
  return data.data
}

export const deleteCompanySite = async (id: string, siteId: string): Promise<void> => {
  await api.delete(`/companies/${id}/sites/${siteId}`)
}

export const getCompanyTimeline = async (id: string): Promise<CompanyTimeline> => {
  const { data } = await api.get<CompanyTimeline>(`/companies/${id}/activities`)
  return data
}

export const logCompanyActivity = async (
  id: string,
  input: { type: string; subject: string; body?: string; occurred_at?: string },
): Promise<{ company: Company; activity: Activity }> => {
  const { data } = await api.post<{ data: Company; activity: Activity }>(
    `/companies/${id}/activities`,
    input,
  )
  return { company: data.data, activity: data.activity }
}
