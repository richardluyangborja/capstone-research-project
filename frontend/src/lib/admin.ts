import { api } from './api'
import type { AdminAccess } from '../types'

/**
 * The server-side capability probe for the administration shell (spec 19 S1). It is deliberately
 * small: the JWT already names the role, but this endpoint gives later slices one place to prove
 * the boundary and gives non-admins Laravel's existing 403 response shape.
 */
export const getAdminAccess = async (): Promise<AdminAccess> => {
  const { data } = await api.get<{ data: AdminAccess }>('/admin/access')
  return data.data
}
