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
  quota: { monthly: number; quarterly: number; annual: number }
  quota_attainment: number | null
  adjusted_quota: number | null
  can_update_quota: boolean
  /** Module 09 T4 — open flags and the page's abilities (detail read only). */
  discrepancies?: {
    open: DiscrepancyCategory[]
    open_count: number
    can_raise: boolean
    can_resolve: boolean
  } | null
}

export type DiscrepancyCategory = 'attendance' | 'leave_balance' | 'commission_calculation' | 'missing_deal'

export type DiscrepancyStatus = 'open' | 'resolved'

export interface EmployeeDiscrepancy {
  id: string
  category: DiscrepancyCategory
  category_label: string
  section: 'attendance' | 'compensation'
  explanation: string
  status: DiscrepancyStatus
  status_label: string
  subject: { type: 'opportunity' | 'contract'; id: string; label: string } | null
  raised_by: { id: string; name: string } | null
  raised_at: string | null
  resolved: { at: string; by: { id: string; name: string } | null; note: string | null } | null
}

export interface EmployeeDiscrepanciesResponse {
  data: EmployeeDiscrepancy[]
  meta: { total: number; open: number; can_raise: boolean; can_resolve: boolean }
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
  approved_leave_days: number
  standard_business_days: number
  net_working_days: number
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

export interface EmployeeCompensation {
  user_id: string
  base_salary: number | null
  fixed_allowances: number | null
  is_masked: boolean
  commission: {
    won_value: number
    quota_value: number
    attainment_percent: number | null
    baseline_rate_percent: number
    accelerator_rate_percent: number
    baseline_commission: number
    accelerator_commission: number
    total_earned_commission: number
    tier_applied: 'baseline' | 'accelerator'
  }
  recent_payslips: Array<{
    period: string
    gross_pay: number | null
    net_pay: number | null
    status: string
  }>
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
    range: EmployeeRange
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

export const updateEmployeeQuota = async (id: string, quota: { monthly_quota?: number; quarterly_quota?: number; annual_quota?: number }) => {
  const { data } = await api.patch<{ data: { monthly: number; quarterly: number; annual: number } }>(`/employees/${id}/quota`, quota)
  return data.data
}

/** The employee's flags, newest first (module 09 T4, spec 21 §T4). */
export const listEmployeeDiscrepancies = async (id: string): Promise<EmployeeDiscrepanciesResponse> => {
  const { data } = await api.get<EmployeeDiscrepanciesResponse>(`/employees/${id}/discrepancies`)
  return data
}

export interface RaiseDiscrepancyPayload {
  category: DiscrepancyCategory
  explanation: string
  subject_type?: 'opportunity' | 'contract'
  subject_id?: string
}

/** Raise a flag on one's own record — the API refuses this for anybody else (ADR-0033). */
export const raiseEmployeeDiscrepancy = async (id: string, payload: RaiseDiscrepancyPayload) => {
  const { data } = await api.post<{ data: EmployeeDiscrepancy }>(`/employees/${id}/discrepancies`, payload)
  return data.data
}

/** Settle a flag — manager/admin only; the note is optional. */
export const resolveEmployeeDiscrepancy = async (id: string, discrepancyId: string, note?: string) => {
  const { data } = await api.post<{ data: EmployeeDiscrepancy }>(
    `/employees/${id}/discrepancies/${discrepancyId}/resolve`,
    note === undefined ? {} : { note },
  )
  return data.data
}

export const getEmployee = async (id: string, range: EmployeeRange = '30d'): Promise<EmployeeDetailResponse> => {
  const { data } = await api.get<EmployeeDetailResponse>(`/employees/${id}`, { params: { range } })
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
    meta: {
      source: string
      read_only: boolean
      as_of: string
      core2_available: boolean
      range: EmployeeRange
      open_discrepancies: DiscrepancyCategory[]
    }
  }>(`/employees/${id}/attendance`, { params: { range } })
  return data
}

export const getEmployeeCompensation = async (id: string, range: EmployeeRange = '30d') => {
  const { data } = await api.get<{
    data: EmployeeCompensation | null
    meta: {
      source: string
      read_only: boolean
      as_of: string
      core2_available: boolean
      range: EmployeeRange
      commission: EmployeeCompensation['commission']
    }
  }>(`/employees/${id}/compensation`, { params: { range } })
  return data
}

export const getEmployeeAppraisals = async (id: string) => {
  const { data } = await api.get<{
    data: EmployeeAppraisal[] | null
    meta: { source: string; read_only: boolean; as_of: string; core2_available: boolean }
  }>(`/employees/${id}/appraisals`)
  return data
}

export const getEmployeeLeaderboard = async (range: EmployeeRange = '30d'): Promise<EmployeeDirectoryResponse> => {
  const { data } = await api.get<EmployeeDirectoryResponse>('/employees/leaderboard', { params: { range } })
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
