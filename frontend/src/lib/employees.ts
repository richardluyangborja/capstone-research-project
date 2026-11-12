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

export interface EmployeeAttendance {
  user_id: string
  attendance_rate: number
  days_present: number
  late_arrivals_count: number
  late_arrivals_minutes: number
  undertime_hours: number
  absences_count: number
  leave_balances: {
    vacation_leave_remaining: number
    sick_leave_remaining: number
    emergency_leave_remaining: number
  }
}

export interface EmployeeAppraisal {
  appraisal_id: string
  period_label: string
  overall_rating: number
  competencies: Record<string, number>
  evaluator_notes: string
  conducted_at: string
}

export interface EmployeeDetailResponse {
  data: EmployeeRow
  meta: {
    source: string
    read_only: boolean
    as_of: string
    core2_available: boolean
  }
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

export const getEmployee = async (id: string): Promise<EmployeeDetailResponse> => {
  const { data } = await api.get<EmployeeDetailResponse>(`/employees/${id}`)
  return data
}

export const getEmployeePerformance = async (id: string, range: EmployeeRange) => {
  const { data } = await api.get<{ data: EmployeePerformance; meta: { range: EmployeeRange } }>(
    `/employees/${id}/performance`,
    { params: { range } },
  )
  return data
}

export const getEmployeeAttendance = async (id: string, range: EmployeeRange) => {
  const { data } = await api.get<{
    data: EmployeeAttendance | null
    meta: { source: string; read_only: boolean; as_of: string; core2_available: boolean; range: EmployeeRange }
  }>(`/employees/${id}/attendance`, { params: { range } })
  return data
}

export const getEmployeeAppraisals = async (id: string) => {
  const { data } = await api.get<{
    data: EmployeeAppraisal[] | null
    meta: { source: string; read_only: boolean; as_of: string; core2_available: boolean }
  }>(`/employees/${id}/appraisals`)
  return data
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
