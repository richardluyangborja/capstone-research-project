import type { Tone } from '../components/common/StatusBadge'
import type { Role, UserStatus } from '../types'

/**
 * Directory labels (module 08 S2). Roles read as the job they do, never as the internal enum
 * value, and the tone follows the design tokens: people-domain roles are green, the
 * administration plane is the counts domain.
 */
export const roleMeta: Record<Role, { label: string; tone: Tone }> = {
  salesrep: { label: 'Sales rep', tone: 'people' },
  manager: { label: 'Manager', tone: 'people' },
  admin: { label: 'Admin', tone: 'counts' },
  superadmin: { label: 'Superadmin', tone: 'counts' },
}

export const userStatusMeta: Record<UserStatus, { label: string; tone: Tone }> = {
  active: { label: 'Active', tone: 'success' },
  suspended: { label: 'Suspended', tone: 'danger' },
}

/**
 * The roles a caller may name in the directory. An admin manages manager/salesrep accounts;
 * only a superadmin may assign `admin`; `superadmin` is never assignable through the UI
 * (spec 19 §S2 acceptance). The server rejects the same payloads, so this is presentation only.
 */
export const assignableRoles = (actorRole: Role | null | undefined): Role[] =>
  actorRole === 'superadmin' ? ['admin', 'manager', 'salesrep'] : ['manager', 'salesrep']
