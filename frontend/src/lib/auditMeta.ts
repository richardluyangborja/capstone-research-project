import type { Tone } from '../components/common/StatusBadge'
import type { AuditSeverity } from '../types'

export const auditSeverityMeta: Record<AuditSeverity, { label: string; tone: Tone }> = {
  info: { label: 'Info', tone: 'neutral' },
  notice: { label: 'Notice', tone: 'activity' },
  warning: { label: 'Warning', tone: 'cost' },
  critical: { label: 'Critical', tone: 'danger' },
}

/**
 * The event vocabulary (spec 19 §Audit event vocabulary). The label is what the book shows;
 * the raw action stays visible in the detail so an operator can quote it in a ticket.
 */
export const auditActionMeta: Record<string, { label: string; group: string }> = {
  'auth.login_succeeded': { label: 'Signed in', group: 'Authentication' },
  'auth.login_failed': { label: 'Sign-in failed', group: 'Authentication' },
  'auth.logout': { label: 'Signed out', group: 'Authentication' },
  'auth.password_changed': { label: 'Password changed', group: 'Authentication' },
  'auth.session_revoked': { label: 'Session revoked', group: 'Authentication' },
  'user.created': { label: 'User created', group: 'Users' },
  'user.updated': { label: 'User updated', group: 'Users' },
  'user.role_changed': { label: 'Role changed', group: 'Users' },
  'user.team_changed': { label: 'Team changed', group: 'Users' },
  'user.suspended': { label: 'User suspended', group: 'Users' },
  'user.restored': { label: 'User restored', group: 'Users' },
  'user.invitation_resent': { label: 'Invitation resent', group: 'Users' },
  'team.created': { label: 'Team created', group: 'Teams' },
  'team.updated': { label: 'Team updated', group: 'Teams' },
  'team.member_added': { label: 'Team member added', group: 'Teams' },
  'team.member_removed': { label: 'Team member removed', group: 'Teams' },
  'setting.updated': { label: 'Setting updated', group: 'Settings' },
  'setting.refreshed': { label: 'Configuration refreshed', group: 'Settings' },
  'audit.exported': { label: 'Audit exported', group: 'Audit' },
  'security.access_denied': { label: 'Access denied', group: 'Security' },
  'security.final_superadmin_guard': { label: 'Final superadmin guard', group: 'Security' },
}

export const auditActionLabel = (action: string): string => auditActionMeta[action]?.label ?? action

/** The filter's grouped options, in vocabulary order. */
export const auditActionGroups = (): { group: string; actions: string[] }[] => {
  const groups = new Map<string, string[]>()

  for (const [action, meta] of Object.entries(auditActionMeta)) {
    const actions = groups.get(meta.group) ?? []
    actions.push(action)
    groups.set(meta.group, actions)
  }

  return [...groups.entries()].map(([group, actions]) => ({ group, actions }))
}

export const auditResourceOptions = ['User', 'Team', 'Setting', 'AuditLog'] as const
