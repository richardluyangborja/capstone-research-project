import { useCallback, useEffect, useMemo, useState } from 'react'
import { Search, Trash2, UserPlus, UserRoundCog, X } from 'lucide-react'
import AlertItem from '../../components/common/AlertItem'
import StatusBadge from '../../components/common/StatusBadge'
import {
  addAdminTeamMember,
  assignAdminTeamManager,
  listAdminUsers,
  removeAdminTeamMember,
  updateAdminTeam,
} from '../../lib/admin'
import { roleMeta, userStatusMeta } from '../../lib/adminMeta'
import { parseValidationError } from '../../lib/leads'
import type { AdminTeam, AdminUser, Role } from '../../types'

const inputClass =
  'mt-1 w-full rounded-md border border-input bg-card px-3 py-2 text-sm text-foreground outline-none focus:ring-2 focus:ring-ring disabled:opacity-60'

interface AdminTeamPanelProps {
  team: AdminTeam
  managerOptions: AdminUser[]
  actorRole: Role | null | undefined
  onChanged: (team: AdminTeam) => void
  onClose: () => void
}

/**
 * One team, with its manager assignment and membership controls (module 08 S3, spec 19).
 *
 * A manager must be an active manager account, and the current manager cannot be removed as a
 * member — both rules are repeated in the UI copy and enforced by the API. Adding or removing a
 * member never touches CRM ownership; the panel says that beside the controls.
 */
export default function AdminTeamPanel({
  team,
  managerOptions,
  actorRole,
  onChanged,
  onClose,
}: AdminTeamPanelProps) {
  const [name, setName] = useState(team.name)
  const [managerId, setManagerId] = useState(team.manager?.id ?? '')
  const [candidates, setCandidates] = useState<AdminUser[]>([])
  const [candidateSearch, setCandidateSearch] = useState('')

  const [busy, setBusy] = useState(false)
  const [notice, setNotice] = useState('')
  const [error, setError] = useState('')
  const [errors, setErrors] = useState<Record<string, string[]>>({})

  const allowedRoles = useMemo<Role[]>(
    () => (actorRole === 'superadmin' ? ['admin', 'manager', 'salesrep'] : ['manager', 'salesrep']),
    [actorRole],
  )

  useEffect(() => {
    let cancelled = false

    if (!team.can.add_member) {
      return
    }

    void listAdminUsers({ status: 'active', per_page: 100 })
      .then((response) => {
        if (!cancelled) setCandidates(response.data)
      })
      .catch(() => {
        if (!cancelled) setCandidates([])
      })

    return () => {
      cancelled = true
    }
  }, [team.id, team.can.add_member])

  const run = useCallback(
    async (action: () => Promise<AdminTeam>, success: string): Promise<boolean> => {
      setBusy(true)
      setError('')
      setNotice('')
      setErrors({})
      try {
        const next = await action()
        setNotice(success)
        onChanged(next)
        return true
      } catch (caught) {
        const validation = parseValidationError(caught)
        if (validation) {
          setErrors(validation.errors)
          setError(validation.message)
        } else {
          setError('That change was refused. Reload the team and try again.')
        }
        return false
      } finally {
        setBusy(false)
      }
    },
    [onChanged],
  )

  const members = team.members ?? []
  const memberIds = new Set(members.map((member) => member.id))
  const matchingCandidates = candidates
    .filter(
      (candidate) =>
        allowedRoles.includes(candidate.role) &&
        candidate.role !== 'superadmin' &&
        !memberIds.has(candidate.id),
    )
    .filter((candidate) => {
      const term = candidateSearch.trim().toLowerCase()
      if (term === '') return true
      return (
        candidate.name.toLowerCase().includes(term) || candidate.email.toLowerCase().includes(term)
      )
    })
    .slice(0, 6)

  return (
    <section aria-label={`Team ${team.name}`} className="rounded-lg border border-border bg-card">
      <header className="flex items-start justify-between gap-3 border-b border-border px-4 py-3">
        <div>
          <div className="flex flex-wrap items-center gap-2">
            <h3 className="text-sm font-semibold text-foreground">{team.name}</h3>
            <StatusBadge tone="counts">{team.members_count ?? members.length} members</StatusBadge>
            {team.manager === null && <StatusBadge tone="warm">No manager</StatusBadge>}
          </div>
          <p className="mt-0.5 text-xs text-muted-foreground">
            Manager and membership changes are audited. CRM ownership is never reassigned here.
          </p>
        </div>
        <button
          type="button"
          onClick={onClose}
          aria-label="Close team panel"
          className="rounded-md p-1 text-muted-foreground hover:bg-muted hover:text-foreground"
        >
          <X className="h-4 w-4" aria-hidden />
        </button>
      </header>

      <div className="space-y-4 p-4">
        {notice && <AlertItem variant="success">{notice}</AlertItem>}
        {error && <AlertItem variant="danger">{error}</AlertItem>}

        {team.can.update && (
          <form
            className="flex flex-col gap-3 sm:flex-row sm:items-end"
            onSubmit={(event) => {
              event.preventDefault()
              void run(() => updateAdminTeam(team.id, name.trim()), 'Team name saved.')
            }}
          >
            <label className="block flex-1 text-sm font-medium text-foreground">
              Team name
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
            <button
              type="submit"
              disabled={busy || name.trim() === team.name}
              className="rounded-md bg-primary px-3 py-2 text-sm font-medium text-primary-foreground disabled:opacity-60"
            >
              {busy ? 'Saving…' : 'Save name'}
            </button>
          </form>
        )}

        <div className="rounded-lg border border-border p-3">
          <div className="flex items-center gap-2">
            <UserRoundCog className="h-4 w-4 text-counts" aria-hidden />
            <h4 className="text-sm font-semibold text-foreground">Team manager</h4>
          </div>
          <p className="mt-1 text-xs text-muted-foreground">
            Only an active account with the manager role can lead a team. Assigning a manager does
            not add them to the membership list automatically.
          </p>

          {team.can.assign_manager ? (
            <div className="mt-3 flex flex-col gap-3 sm:flex-row sm:items-end">
              <label className="block flex-1 text-sm font-medium text-foreground">
                Manager
                <select
                  value={managerId}
                  onChange={(event) => setManagerId(event.target.value)}
                  className={inputClass}
                  disabled={busy}
                >
                  <option value="">Select a manager</option>
                  {managerOptions.map((manager) => (
                    <option key={manager.id} value={manager.id}>
                      {manager.name}
                      {manager.email ? ` · ${manager.email}` : ''}
                    </option>
                  ))}
                </select>
                {errors.manager_id && (
                  <span className="mt-1 block text-xs text-destructive">
                    {errors.manager_id[0]}
                  </span>
                )}
              </label>
              <button
                type="button"
                disabled={busy || managerId === '' || managerId === team.manager?.id}
                onClick={() => {
                  void run(
                    () => assignAdminTeamManager(team.id, managerId),
                    'Team manager assigned.',
                  )
                }}
                className="rounded-md bg-primary px-3 py-2 text-sm font-medium text-primary-foreground disabled:opacity-60"
              >
                {busy ? 'Assigning…' : 'Assign manager'}
              </button>
            </div>
          ) : (
            <p className="mt-3 text-sm text-muted-foreground">
              {team.manager ? `${team.manager.name} leads this team.` : 'No manager assigned.'}
            </p>
          )}
        </div>

        <section aria-labelledby="team-members-heading">
          <div className="flex items-center justify-between gap-2">
            <h4 id="team-members-heading" className="text-sm font-semibold text-foreground">
              Members
            </h4>
            <span className="text-xs text-muted-foreground">{members.length} in the team</span>
          </div>

          {members.length === 0 ? (
            <p className="mt-2 rounded-md border border-dashed border-border p-3 text-sm text-muted-foreground">
              No members yet. Add an active manager or sales rep below.
            </p>
          ) : (
            <ul className="mt-2 divide-y divide-border rounded-md border border-border">
              {members.map((member) => (
                <li key={member.id} className="flex flex-wrap items-center gap-2 px-3 py-2">
                  <div className="min-w-0 flex-1">
                    <div className="flex flex-wrap items-center gap-1.5">
                      <span className="truncate text-sm font-medium text-foreground">
                        {member.name}
                      </span>
                      <StatusBadge tone={roleMeta[member.role].tone}>
                        {roleMeta[member.role].label}
                      </StatusBadge>
                      {member.is_manager && <StatusBadge tone="counts">Manager</StatusBadge>}
                      {member.status === 'suspended' && (
                        <StatusBadge tone={userStatusMeta[member.status].tone}>
                          {userStatusMeta[member.status].label}
                        </StatusBadge>
                      )}
                    </div>
                    <p className="truncate text-xs text-muted-foreground">{member.email}</p>
                  </div>
                  {team.can.remove_member && !member.is_manager && (
                    <button
                      type="button"
                      disabled={busy}
                      onClick={() => {
                        void run(
                          () => removeAdminTeamMember(team.id, member.id),
                          `${member.name} was removed from the team. Their records were not reassigned.`,
                        )
                      }}
                      className="inline-flex items-center gap-1 rounded-md border border-destructive/40 bg-destructive/10 px-2.5 py-1 text-xs font-medium text-destructive hover:bg-destructive/20 disabled:opacity-60"
                    >
                      <Trash2 className="h-3.5 w-3.5" aria-hidden />
                      Remove
                    </button>
                  )}
                  {member.is_manager && (
                    <span className="text-xs text-muted-foreground">
                      Assign another manager first
                    </span>
                  )}
                </li>
              ))}
            </ul>
          )}

          {team.can.add_member && (
            <div className="mt-3 rounded-md border border-border p-3">
              <div className="flex items-center gap-2">
                <UserPlus className="h-4 w-4 text-people" aria-hidden />
                <h5 className="text-sm font-semibold text-foreground">Add a member</h5>
              </div>
              <p className="mt-1 text-xs text-muted-foreground">
                Moving a user into this team never reassigns their leads, accounts or deals.
              </p>

              <div className="relative mt-3">
                <Search
                  className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground"
                  aria-hidden
                />
                <input
                  type="search"
                  value={candidateSearch}
                  onChange={(event) => setCandidateSearch(event.target.value)}
                  placeholder="Search an active account by name or email"
                  className="w-full rounded-md border border-input bg-card py-2 pl-9 pr-3 text-sm text-foreground outline-none focus:ring-2 focus:ring-ring"
                />
              </div>

              {matchingCandidates.length === 0 ? (
                <p className="mt-2 text-xs text-muted-foreground">
                  No eligible account matches. Suspended accounts and out-of-scope roles are never
                  offered.
                </p>
              ) : (
                <ul className="mt-2 divide-y divide-border">
                  {matchingCandidates.map((candidate) => (
                    <li
                      key={candidate.id}
                      className="flex flex-wrap items-center gap-2 py-2 first:pt-0 last:pb-0"
                    >
                      <div className="min-w-0 flex-1">
                        <p className="truncate text-sm font-medium text-foreground">
                          {candidate.name}
                        </p>
                        <p className="truncate text-xs text-muted-foreground">
                          {candidate.email} · {roleMeta[candidate.role].label}
                        </p>
                      </div>
                      <button
                        type="button"
                        disabled={busy}
                        onClick={() => {
                          void run(
                            () => addAdminTeamMember(team.id, candidate.id),
                            `${candidate.name} joined the team.`,
                          )
                        }}
                        className="rounded-md border border-border bg-card px-2.5 py-1 text-xs font-medium text-foreground hover:bg-muted disabled:opacity-60"
                      >
                        Add to team
                      </button>
                    </li>
                  ))}
                </ul>
              )}
            </div>
          )}
        </section>
      </div>
    </section>
  )
}
