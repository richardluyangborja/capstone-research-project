import type { Role } from '../types'

/** Roles that may enter the application-administration plane (spec 19 S1, ADR-0030). */
export const ADMIN_ROLES: Role[] = ['admin', 'superadmin']

/** Roles that may enter the platform-operations plane. */
export const PLATFORM_ROLES: Role[] = ['superadmin']

/** Every signed-in role has the personal-settings surface. */
export const ALL_ROLES: Role[] = ['salesrep', 'manager', 'admin', 'superadmin']

export function hasAnyRole(role: Role | null | undefined, allowed: readonly Role[]): boolean {
  return Boolean(role && allowed.includes(role))
}

export function canAccessAdmin(role: Role | null | undefined): boolean {
  return hasAnyRole(role, ADMIN_ROLES)
}

export function canAccessPlatform(role: Role | null | undefined): boolean {
  return hasAnyRole(role, PLATFORM_ROLES)
}

export function canAccessPersonalSettings(role: Role | null | undefined): boolean {
  return hasAnyRole(role, ALL_ROLES)
}

/**
 * The visible administrative areas for a role. The backend `/admin/access` contract returns the
 * same shape; keeping this helper means the shell does not have to wait for a network round trip
 * to decide which navigation group it may render.
 */
export function adminAreas(role: Role | null | undefined): ('application' | 'platform')[] {
  if (role === 'superadmin') return ['application', 'platform']
  if (role === 'admin') return ['application']
  return []
}
