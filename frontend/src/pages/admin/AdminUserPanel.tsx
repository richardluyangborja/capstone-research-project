import { useCallback, useEffect, useState } from 'react'
import { Mail, RotateCcw, ShieldAlert, UserX, X } from 'lucide-react'
import StatusBadge from '../../components/common/StatusBadge'
import AlertItem from '../../components/common/AlertItem'
import StatCard from '../../components/common/StatCard'
import {
  changeAdminUserRole,
  changeAdminUserTeam,
  getAdminUserOwnershipImpact,
  resendAdminInvitation,
  restoreAdminUser,
  suspendAdminUser,
  updateAdminUser,
} from '../../lib/admin'
import { assignableRoles, roleMeta, userStatusMeta } from '../../lib/adminMeta'
import { parseValidationError } from '../../lib/leads'
import { formatDateTime } from '../../lib/format'
import type { AdminTeamOption, AdminUser, AdminUserOwnershipImpact, Role } from '../../types'

const inputClass =
  'mt-1 w-full rounded-md border border-input bg-card px-3 py-2 text-sm text-foreground outline-none focus:ring-2 focus:ring-ring disabled:opacity-60'

interface AdminUserPanelProps {
  user: AdminUser
  teams: AdminTeamOption[]
  actorRole: Role | null | undefined
  /** The panel owns the mutation calls; the directory reloads the row it changed. */
  onChanged: (user: AdminUser) => void
  onClose: () => void
}

/**
 * One directory account, with its lifecycle controls (module 08 S2, spec 19 §Endpoint and policy
 * inventory). Every control is gated by the row's own `can` block — the server's policy answer —
 * so the panel never offers an action that would come back 403. Suspension and team changes never
 * reassign CRM records, which the ownership preview states explicitly.
 */
export default function AdminUserPanel({
  user,
  teams,
  actorRole,
  onChanged,
  onClose,
}: AdminUserPanelProps) {
  const [name, setName] = useState(user.name)
  const [email, setEmail] = useState(user.email)
  const [role, setRole] = useState<Role>(user.role)
  const [teamId, setTeamId] = useState(user.team?.id ?? '')
  const [impact, setImpact] = useState<AdminUserOwnershipImpact | null>(null)

  const [busy, setBusy] = useState(false)
  const [notice, setNotice] = useState('')
  const [error, setError] = useState('')
  const [errors, setErrors] = useState<Record<string, string[]>>({})
  const [suspendOpen, setSuspendOpen] = useState(false)
  const [suspendReason, setSuspendReason] = useState('')

  // The directory keys this panel by account id, so switching accounts remounts it and these
  // initial values are the reset. A mutation returns a fresh row for the same account, which
  // must not wipe the notice it just set.
  useEffect(() => {
    let cancelled = false

    if (!user.can.view_ownership_impact) {
      return
    }

    void getAdminUserOwnershipImpact(user.id)
      .then((next) => {
        if (!cancelled) setImpact(next)
      })
      .catch(() => {
        if (!cancelled) setImpact(null)
      })

    return () => {
      cancelled = true
    }
  }, [user.id, user.can.view_ownership_impact])

  /** One mutation wrapper: clears the last message, then reports the outcome the same way. */
  const run = useCallback(
    async (action: () => Promise<AdminUser>, success: string) => {
      setBusy(true)
      setError('')
      setNotice('')
      setErrors({})
      try {
        const next = await action()
        setNotice(success)
        onChanged(next)
      } catch (caught) {
        const validation = parseValidationError(caught)
        if (validation) {
          setErrors(validation.errors)
          setError(validation.message)
        } else {
          setError('That change was refused. Reload the account and try again.')
        }
      } finally {
        setBusy(false)
      }
    },
    [onChanged],
  )

  const roleOptions = assignableRoles(actorRole)
  const canChangeRole = user.can.change_role && roleOptions.includes(user.role)
  const isSuspended = user.status === 'suspended'

  return (
    <section
      aria-label={`Account ${user.name}`}
      className="rounded-lg border border-border bg-card"
    >
      <header className="flex items-start justify-between gap-3 border-b border-border px-4 py-3">
        <div>
          <div className="flex flex-wrap items-center gap-2">
            <h3 className="text-sm font-semibold text-foreground">{user.name}</h3>
            <StatusBadge tone={roleMeta[user.role].tone}>{roleMeta[user.role].label}</StatusBadge>
            <StatusBadge tone={userStatusMeta[user.status].tone}>
              {userStatusMeta[user.status].label}
            </StatusBadge>
          </div>
          <p className="mt-0.5 text-xs text-muted-foreground">{user.email}</p>
        </div>
        <button
          type="button"
          onClick={onClose}
          aria-label="Close account panel"
          className="rounded-md p-1 text-muted-foreground hover:bg-muted hover:text-foreground"
        >
          <X className="h-4 w-4" aria-hidden />
        </button>
      </header>

      <div className="space-y-4 p-4">
        {isSuspended && (
          <AlertItem variant="warning" title="Suspended account">
            {user.status_reason ?? 'No reason recorded.'}
            {user.suspended_at && ` · Suspended ${formatDateTime(user.suspended_at)}`}
            {user.suspended_by && ` by ${user.suspended_by.name}`}. The account cannot sign in or
            use the API until it is restored.
          </AlertItem>
        )}

        {notice && <AlertItem variant="success">{notice}</AlertItem>}
        {error && <AlertItem variant="danger">{error}</AlertItem>}

        {impact && (
          <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
            <StatCard label="Leads owned" value={impact.counts.leads} domain="people" />
            <StatCard label="Accounts owned" value={impact.counts.companies} domain="counts" />
            <StatCard label="Deals owned" value={impact.counts.opportunities} domain="activity" />
            <StatCard label="Open follow-ups" value={impact.counts.open_follow_ups} domain="cost" />
          </div>
        )}
        {impact && <p className="text-xs text-muted-foreground">{impact.note}</p>}

        {user.can.update && (
          <form
            className="grid grid-cols-1 gap-3 sm:grid-cols-2"
            onSubmit={(event) => {
              event.preventDefault()
              void run(
                () => updateAdminUser(user.id, { name: name.trim(), email: email.trim() }),
                'Profile saved.',
              )
            }}
          >
            <label className="block text-sm font-medium text-foreground">
              Name
              <input
                type="text"
                value={name}
                onChange={(event) => setName(event.target.value)}
                className={inputClass}
                required
              />
              {errors.name && (
                <span className="mt-1 block text-xs text-destructive">{errors.name[0]}</span>
              )}
            </label>
            <label className="block text-sm font-medium text-foreground">
              Email
              <input
                type="email"
                value={email}
                onChange={(event) => setEmail(event.target.value)}
                className={inputClass}
                required
              />
              {errors.email && (
                <span className="mt-1 block text-xs text-destructive">{errors.email[0]}</span>
              )}
            </label>
            <div className="sm:col-span-2">
              <button
                type="submit"
                disabled={busy}
                className="rounded-md bg-primary px-3 py-2 text-sm font-medium text-primary-foreground disabled:opacity-60"
              >
                {busy ? 'Saving…' : 'Save profile'}
              </button>
            </div>
          </form>
        )}

        <div className="grid grid-cols-1 gap-3 border-t border-border pt-4 sm:grid-cols-2">
          <label className="block text-sm font-medium text-foreground">
            Role
            <select
              value={role}
              disabled={busy || !canChangeRole}
              onChange={(event) => {
                const next = event.target.value as Role
                setRole(next)
                void run(
                  () => changeAdminUserRole(user.id, next),
                  `Role changed to ${roleMeta[next].label}.`,
                )
              }}
              className={inputClass}
            >
              {/* The current role is always shown, even when the actor may not assign it. */}
              {!roleOptions.includes(user.role) && (
                <option value={user.role}>{roleMeta[user.role].label}</option>
              )}
              {roleOptions.map((value) => (
                <option key={value} value={value}>
                  {roleMeta[value].label}
                </option>
              ))}
            </select>
            <span className="mt-1 block text-xs text-muted-foreground">
              {canChangeRole
                ? 'Role changes are audited and apply on the next request.'
                : 'You may not change this account’s role.'}
            </span>
            {errors.role && (
              <span className="mt-1 block text-xs text-destructive">{errors.role[0]}</span>
            )}
          </label>

          <label className="block text-sm font-medium text-foreground">
            Team
            <select
              value={teamId}
              disabled={busy || !user.can.change_team}
              onChange={(event) => {
                const next = event.target.value
                setTeamId(next)
                void run(
                  () => changeAdminUserTeam(user.id, next === '' ? null : next),
                  'Team changed. CRM ownership was left untouched.',
                )
              }}
              className={inputClass}
            >
              <option value="">No team</option>
              {teams.map((team) => (
                <option key={team.id} value={team.id}>
                  {team.name}
                </option>
              ))}
            </select>
            <span className="mt-1 block text-xs text-muted-foreground">
              Moving a user never reassigns their records.
            </span>
            {errors.team_id && (
              <span className="mt-1 block text-xs text-destructive">{errors.team_id[0]}</span>
            )}
          </label>
        </div>

        <div className="flex flex-wrap items-center gap-2 border-t border-border pt-4">
          {user.can.resend_invitation && !isSuspended && (
            <button
              type="button"
              disabled={busy}
              onClick={() => {
                setBusy(true)
                setError('')
                setNotice('')
                void resendAdminInvitation(user.id)
                  .then((result) =>
                    setNotice(
                      result.sent
                        ? `Invitation handed to the simulated mailbox (${result.source}).`
                        : `The gateway rejected the invitation: ${result.message ?? 'unknown reason'}.`,
                    ),
                  )
                  .catch(() => setError('Could not resend the invitation.'))
                  .finally(() => setBusy(false))
              }}
              className="inline-flex items-center gap-1.5 rounded-md border border-border bg-card px-3 py-2 text-sm font-medium text-foreground hover:bg-muted disabled:opacity-60"
            >
              <Mail className="h-4 w-4" aria-hidden />
              Resend invitation
            </button>
          )}

          {user.can.suspend && !isSuspended && (
            <button
              type="button"
              disabled={busy}
              onClick={() => setSuspendOpen(true)}
              className="inline-flex items-center gap-1.5 rounded-md border border-destructive/40 bg-destructive/10 px-3 py-2 text-sm font-medium text-destructive hover:bg-destructive/20 disabled:opacity-60"
            >
              <UserX className="h-4 w-4" aria-hidden />
              Suspend account
            </button>
          )}

          {user.can.restore && isSuspended && (
            <button
              type="button"
              disabled={busy}
              onClick={() => void run(() => restoreAdminUser(user.id), 'Account restored.')}
              className="inline-flex items-center gap-1.5 rounded-md bg-primary px-3 py-2 text-sm font-medium text-primary-foreground disabled:opacity-60"
            >
              <RotateCcw className="h-4 w-4" aria-hidden />
              Restore account
            </button>
          )}

          {!user.can.suspend && !user.can.restore && !isSuspended && (
            <p className="inline-flex items-center gap-1.5 text-xs text-muted-foreground">
              <ShieldAlert className="h-3.5 w-3.5" aria-hidden />
              You cannot suspend your own account.
            </p>
          )}
        </div>
      </div>

      {suspendOpen && (
        <div
          role="dialog"
          aria-modal="true"
          aria-label="Suspend account"
          className="fixed inset-0 z-50 flex items-center justify-center bg-foreground/40 p-4"
        >
          <div className="w-full max-w-sm rounded-lg border border-border bg-card p-5">
            <h2 className="text-sm font-semibold text-foreground">Suspend {user.name}</h2>
            <p className="mt-1 text-sm text-muted-foreground">
              The account cannot sign in or use the API. Their records stay exactly where they are —
              nothing is reassigned.
            </p>
            <label className="mt-4 block text-sm font-medium text-foreground">
              Reason
              <textarea
                rows={3}
                value={suspendReason}
                onChange={(event) => setSuspendReason(event.target.value)}
                placeholder="Why is this account being suspended?"
                className={inputClass}
              />
              {errors.status_reason && (
                <span className="mt-1 block text-xs text-destructive">
                  {errors.status_reason[0]}
                </span>
              )}
            </label>
            <div className="mt-5 flex justify-end gap-2">
              <button
                type="button"
                onClick={() => setSuspendOpen(false)}
                className="rounded-md border border-border bg-card px-3 py-2 text-sm font-medium text-foreground hover:bg-muted"
              >
                Cancel
              </button>
              <button
                type="button"
                disabled={busy || suspendReason.trim().length < 3}
                onClick={() => {
                  void run(
                    () => suspendAdminUser(user.id, suspendReason.trim()),
                    'Account suspended.',
                  )
                  setSuspendOpen(false)
                }}
                className="rounded-md bg-destructive px-3 py-2 text-sm font-medium text-destructive-foreground disabled:opacity-60"
              >
                {busy ? 'Suspending…' : 'Suspend'}
              </button>
            </div>
          </div>
        </div>
      )}
    </section>
  )
}
