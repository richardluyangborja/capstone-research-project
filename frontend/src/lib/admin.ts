import { api } from './api'
import type {
  AdminAccess,
  AdminInvitationResult,
  AdminSettingCategory,
  AdminTeam,
  AdminTeamOption,
  AdminUser,
  AdminUserOwnershipImpact,
  AuditLogEntry,
  AuditSeverity,
  Communication,
  MaintenanceEmailRetryResult,
  MaintenanceRefreshResult,
  OperationsHealth,
  OperationsHealthResponse,
  OperationsSummary,
  Paginated,
  Role,
  UserStatus,
} from '../types'

/**
 * The server-side capability probe for the administration shell (spec 19 S1). It is deliberately
 * small: the JWT already names the role, but this endpoint gives later slices one place to prove
 * the boundary and gives non-admins Laravel's existing 403 response shape.
 */
export const getAdminAccess = async (): Promise<AdminAccess> => {
  const { data } = await api.get<{ data: AdminAccess }>('/admin/access')
  return data.data
}

// --- Module 08 S2 — the user directory and account lifecycle -----------------

export interface AdminUserFilters {
  search?: string
  role?: Role | ''
  team_id?: string
  status?: UserStatus | ''
  page?: number
  per_page?: number
}

/**
 * The directory is paginated and role-scoped on the server: an admin's query never returns a
 * superadmin account, so the page does not filter the response itself (spec 19 §Endpoint and
 * policy inventory).
 */
export const listAdminUsers = async (
  filters: AdminUserFilters = {},
): Promise<Paginated<AdminUser>> => {
  const params: Record<string, string | number> = {}
  if (filters.search) params.search = filters.search
  if (filters.role) params.role = filters.role
  if (filters.team_id) params.team_id = filters.team_id
  if (filters.status) params.status = filters.status
  if (filters.page && filters.page > 1) params.page = filters.page
  if (filters.per_page) params.per_page = filters.per_page

  const { data } = await api.get<Paginated<AdminUser>>('/admin/users', { params })
  return data
}

export const getAdminUser = async (id: string): Promise<AdminUser> => {
  const { data } = await api.get<{ data: AdminUser }>(`/admin/users/${id}`)
  return data.data
}

/** The teams a directory account can belong to — a lookup, not team administration (S3). */
export const listAdminTeamOptions = async (): Promise<AdminTeamOption[]> => {
  const { data } = await api.get<{ data: AdminTeamOption[] }>('/admin/users/team-options')
  return data.data
}

export interface CreateAdminUserInput {
  name: string
  email: string
  role: Role
  team_id?: string | null
  /** Optional: an omitted password means the account starts through the invitation flow. */
  password?: string
}

export interface CreateAdminUserResult {
  user: AdminUser
  invitation: AdminInvitationResult
}

export const createAdminUser = async (
  input: CreateAdminUserInput,
): Promise<CreateAdminUserResult> => {
  const { data } = await api.post<{ data: AdminUser; invitation: AdminInvitationResult }>(
    '/admin/users',
    input,
  )
  return { user: data.data, invitation: data.invitation }
}

/** Safe profile edits only — role, team, status and password have their own endpoints. */
export const updateAdminUser = async (
  id: string,
  input: { name?: string; email?: string },
): Promise<AdminUser> => {
  const { data } = await api.patch<{ data: AdminUser }>(`/admin/users/${id}`, input)
  return data.data
}

export const changeAdminUserRole = async (id: string, role: Role): Promise<AdminUser> => {
  const { data } = await api.patch<{ data: AdminUser }>(`/admin/users/${id}/role`, { role })
  return data.data
}

/** A team change never reassigns CRM ownership (spec 19 §Resource boundaries). */
export const changeAdminUserTeam = async (
  id: string,
  teamId: string | null,
): Promise<AdminUser> => {
  const { data } = await api.patch<{ data: AdminUser }>(`/admin/users/${id}/team`, {
    team_id: teamId,
  })
  return data.data
}

export const suspendAdminUser = async (id: string, statusReason: string): Promise<AdminUser> => {
  const { data } = await api.post<{ data: AdminUser }>(`/admin/users/${id}/suspend`, {
    status_reason: statusReason,
  })
  return data.data
}

export const restoreAdminUser = async (id: string): Promise<AdminUser> => {
  const { data } = await api.post<{ data: AdminUser }>(`/admin/users/${id}/restore`)
  return data.data
}

export const resendAdminInvitation = async (id: string): Promise<AdminInvitationResult> => {
  const { data } = await api.post<{ data: AdminInvitationResult }>(
    `/admin/users/${id}/resend-invitation`,
  )
  return data.data
}

export const getAdminUserOwnershipImpact = async (
  id: string,
): Promise<AdminUserOwnershipImpact> => {
  const { data } = await api.get<{ data: AdminUserOwnershipImpact }>(
    `/admin/users/${id}/ownership-impact`,
  )
  return data.data
}

// --- Module 08 S3 — teams, roles and ownership administration ---------------

export interface AdminTeamFilters {
  search?: string
  page?: number
}

export const listAdminTeams = async (
  filters: AdminTeamFilters = {},
): Promise<Paginated<AdminTeam>> => {
  const params: Record<string, string | number> = {}
  if (filters.search) params.search = filters.search
  if (filters.page && filters.page > 1) params.page = filters.page

  const { data } = await api.get<Paginated<AdminTeam>>('/admin/teams', { params })
  return data
}

export const getAdminTeam = async (id: string): Promise<AdminTeam> => {
  const { data } = await api.get<{ data: AdminTeam }>(`/admin/teams/${id}`)
  return data.data
}

export const createAdminTeam = async (input: {
  name: string
  manager_id?: string | null
}): Promise<AdminTeam> => {
  const { data } = await api.post<{ data: AdminTeam }>('/admin/teams', input)
  return data.data
}

export const updateAdminTeam = async (id: string, name: string): Promise<AdminTeam> => {
  const { data } = await api.patch<{ data: AdminTeam }>(`/admin/teams/${id}`, { name })
  return data.data
}

export const assignAdminTeamManager = async (id: string, managerId: string): Promise<AdminTeam> => {
  const { data } = await api.patch<{ data: AdminTeam }>(`/admin/teams/${id}/manager`, {
    manager_id: managerId,
  })
  return data.data
}

export const addAdminTeamMember = async (id: string, userId: string): Promise<AdminTeam> => {
  const { data } = await api.post<{ data: AdminTeam }>(`/admin/teams/${id}/members`, {
    user_id: userId,
  })
  return data.data
}

export const removeAdminTeamMember = async (id: string, userId: string): Promise<AdminTeam> => {
  const { data } = await api.delete<{ data: AdminTeam }>(`/admin/teams/${id}/members/${userId}`)
  return data.data
}

// --- Module 08 S4: organization and CRM settings -----------------------------

/** The grouped read: categories with their typed, sourced settings (spec 19 §S4). */
export const getAdminSettings = async (): Promise<AdminSettingCategory[]> => {
  const { data } = await api.get<{ data: AdminSettingCategory[] }>('/admin/settings')
  return data.data
}

/**
 * A keyed batch write. Unknown, read-only or out-of-scope keys come back as 422/403 per
 * the spec; the response is the full grouped read as it now stands.
 */
export const updateAdminSettings = async (
  settings: Record<string, string | number | boolean | null>,
): Promise<AdminSettingCategory[]> => {
  const { data } = await api.patch<{ data: AdminSettingCategory[] }>('/admin/settings', {
    settings,
  })
  return data.data
}

/** Rebuild the effective-configuration snapshot — a bounded refresh, not a cache flush. */
export const refreshAdminSettings = async (): Promise<void> => {
  await api.post('/admin/settings/refresh')
}

// --- Module 08 S5: audit and security activity ------------------------------

export interface AuditLogFilters {
  actor?: string
  action?: string
  resource?: string
  severity?: AuditSeverity | ''
  from?: string
  to?: string
  request_id?: string
  page?: number
  per_page?: number
}

/** Only the filters that carry a value reach the query string. */
const auditParams = (filters: AuditLogFilters): Record<string, string | number> => {
  const params: Record<string, string | number> = {}
  if (filters.actor) params.actor = filters.actor
  if (filters.action) params.action = filters.action
  if (filters.resource) params.resource = filters.resource
  if (filters.severity) params.severity = filters.severity
  if (filters.from) params.from = filters.from
  if (filters.to) params.to = filters.to
  if (filters.request_id) params.request_id = filters.request_id
  if (filters.page && filters.page > 1) params.page = filters.page
  if (filters.per_page) params.per_page = filters.per_page
  return params
}

/**
 * The audit book is paginated and role-scoped on the server: an application admin's query
 * never returns a security-sensitive event, so the page does not filter the response itself.
 */
export const listAuditLogs = async (
  filters: AuditLogFilters = {},
): Promise<Paginated<AuditLogEntry>> => {
  const { data } = await api.get<Paginated<AuditLogEntry>>('/admin/audit-logs', {
    params: auditParams(filters),
  })
  return data
}

export const getAuditLog = async (id: string): Promise<AuditLogEntry> => {
  const { data } = await api.get<{ data: AuditLogEntry }>(`/admin/audit-logs/${id}`)
  return data.data
}

/** The CSV is fetched as a blob so the bearer token stays inside the axios client. */
export const exportAuditLogs = async (filters: AuditLogFilters = {}): Promise<Blob> => {
  const { data } = await api.get<Blob>('/admin/audit-logs/export', {
    params: auditParams(filters),
    responseType: 'blob',
  })
  return data
}

// --- Module 08 S6 — integration and operational health ----------------------

/**
 * The role-shaped operations summary. An application admin's payload carries `platform: null`;
 * the server decides that, so the page renders what it was given rather than hiding a section.
 */
export const getOperationsSummary = async (): Promise<OperationsSummary> => {
  const { data } = await api.get<{ data: OperationsSummary }>('/admin/operations/summary')
  return data.data
}

/** The bounded, side-effect-free dependency probes. */
export const getOperationsHealth = async (): Promise<OperationsHealthResponse> => {
  const { data } = await api.get<{
    data: OperationsHealth
    meta: OperationsHealthResponse['meta']
  }>('/admin/operations/health')
  return { health: data.data, meta: data.meta }
}

// --- Module 08 S7 — safe, allow-listed maintenance actions -------------------

/**
 * Rebuild the effective-configuration snapshot. The server requires `confirm: true` and runs
 * `EffectiveSettings::refresh()` — never a generic cache flush. Idempotent.
 */
export const refreshMaintenanceConfiguration = async (): Promise<MaintenanceRefreshResult> => {
  const { data } = await api.post<{ data: MaintenanceRefreshResult }>(
    '/admin/maintenance/refresh-configuration',
    { confirm: true },
  )
  return data.data
}

/**
 * Retry exactly one failed outbound email. A mocked gateway rejection is a delivery outcome,
 * not an HTTP error: the result is `failed` and the page says so.
 */
export const retryFailedEmail = async (
  communicationId: string,
): Promise<MaintenanceEmailRetryResult> => {
  const { data } = await api.post<{ data: MaintenanceEmailRetryResult }>(
    '/admin/maintenance/retry-email',
    { communication_id: communicationId, confirm: true },
  )
  return data.data
}

/**
 * The failed outbound emails the maintenance panel offers. It reuses the existing history
 * book (`GET /communications`) with its failed/email filters and a bounded page size — no
 * new administration read endpoint exists.
 */
export const listRetryableFailedEmails = async (): Promise<Communication[]> => {
  const { data } = await api.get<Paginated<Communication>>('/communications', {
    params: {
      'filter[status]': 'failed',
      'filter[channel]': 'email',
      per_page: 50,
    },
  })
  return data.data
}
