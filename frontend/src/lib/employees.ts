import { api } from './api'
import type { Role } from '../types'

export type EmployeeRange = '30d' | 'quarter' | 'ytd'

export interface EmployeePerformance {
  leads_handled: number
  deals_won: number
  won_value: number
  conversion_rate: number
  open_pipeline_count: number
  open_pipeline_value: number
  follow_up_on_time: number
}

export interface EmployeeRow {
  id: string
  name: string
  role: Extract<Role, 'salesrep' | 'manager'>
  team: { id: string; name: string } | null
  profile: {
    employee_reference: string
    official_title: string
    department: string
    employment_status: string
    tenure_months: number
    shift_schedule: string
    direct_manager: string | null
  } | null
  performance: EmployeePerformance
  attendance: { attendance_rate: number; late_arrivals_count: number } | null
}

export interface EmployeeFilters {
  search?: string
  role?: string
  range?: EmployeeRange
  page?: number
  per_page?: number
}

export interface EmployeeDirectoryResponse {
  data: EmployeeRow[]
  links: { first: string | null; last: string | null; prev: string | null; next: string | null }
  meta: {
    current_page: number
    last_page: number
    per_page: number
    total: number
    source: string
    read_only: boolean
    as_of: string
    core2_available: boolean
    range: EmployeeRange
  }
}

export const listEmployees = async (filters: EmployeeFilters = {}): Promise<EmployeeDirectoryResponse> => {
  const params: Record<string, string | number> = {}
  if (filters.search) params.search = filters.search
  if (filters.role) params.role = filters.role
  if (filters.range) params.range = filters.range
  if (filters.page && filters.page > 1) params.page = filters.page
  if (filters.per_page) params.per_page = filters.per_page
  const { data } = await api.get<EmployeeDirectoryResponse>('/employees', { params })
  return data
}
