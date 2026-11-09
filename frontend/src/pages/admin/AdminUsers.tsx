import { useCallback, useEffect, useState } from 'react'
import { Search, UserPlus } from 'lucide-react'
import PageHeader from '../../components/common/PageHeader'
import EmptyState from '../../components/common/EmptyState'
import AlertItem from '../../components/common/AlertItem'
import StatusBadge from '../../components/common/StatusBadge'
import DataTable, { type DataTableColumn } from '../../components/common/DataTable'
import { useAuth } from '../../lib/auth-context'
import {
  createAdminUser,
  listAdminTeamOptions,
  listAdminUsers,
  type AdminUserFilters,
} from '../../lib/admin'
import { assignableRoles, roleMeta, userStatusMeta } from '../../lib/adminMeta'
import { parseValidationError } from '../../lib/leads'
import { formatDate } from '../../lib/format'
import AdminUserPanel from './AdminUserPanel'
import type { AdminTeamOption, AdminUser, Role, UserStatus } from '../../types'

const inputClass =
  'w-full rounded-md border border-input bg-card px-3 py-2 text-sm text-foreground outline-none focus:ring-2 focus:ring-ring disabled:opacity-60'

const emptyFilters: AdminUserFilters = { search: '', role: '', team_id: '', status: '' }

/**
 * The admin user directory (module 08 slice S2, spec 19).
 *
 * Search plus role/team/status filters, a detail panel that owns the account lifecycle, and a
 * create/invite form. Every control is gated by the row's own `can` block — the server's policy
 * answer — and the directory never renders a superadmin account to an admin, because the API
 * never returns one.
 */
export default function AdminUsers() {
  const { user: actor } = useAuth()

  const [users, setUsers] = useState<AdminUser[]>([])
  const [teams, setTeams] = useState<AdminTeamOption[]>([])
  const [selected, setSelected] = useState<AdminUser | null>(null)

  const [search, setSearch] = useState('')
  const [role, setRole] = useState<Role | ''>('')
  const [teamId, setTeamId] = useState('')
  const [status, setStatus] = useState<UserStatus | ''>('')

  const [page, setPage] = useState(1)
  const [lastPage, setLastPage] = useState(1)
  const [total, setTotal] = useState(0)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')

  const [createOpen, setCreateOpen] = useState(false)
  const [newName, setNewName] = useState('')
  const [newEmail, setNewEmail] = useState('')
  const [newRole, setNewRole] = useState<Role>('salesrep')
  const [newTeam, setNewTeam] = useState('')
  const [creating, setCreating] = useState(false)
  const [createError, setCreateError] = useState('')
  const [createNotice, setCreateNotice] = useState('')
  const [createErrors, setCreateErrors] = useState<Record<string, string[]>>({})

  const load = useCallback(async (targetPage: number, filters: AdminUserFilters) => {
    setLoading(true)
    setError('')
    try {
      const response = await listAdminUsers({ ...filters, page: targetPage })
      setUsers(response.data)
      setPage(response.meta.current_page)
      setLastPage(response.meta.last_page)
      setTotal(response.meta.total)
    } catch {
      setError('Could not load the user directory.')
    } finally {
      setLoading(false)
    }
  }, [])

  useEffect(() => {
    void load(1, emptyFilters)
    void listAdminTeamOptions()
      .then(setTeams)
      .catch(() => setTeams([]))
  }, [load])

  const filters: AdminUserFilters = { search, role, team_id: teamId, status }

  /** A mutation returns the fresh row; keep the table and the open panel in step. */
  const handleChanged = (next: AdminUser) => {
    setUsers((rows) => rows.map((row) => (row.id === next.id ? next : row)))
    setSelected((current) => (current?.id === next.id ? next : current))
  }

  const columns: DataTableColumn<AdminUser>[] = [
    {
      key: 'name',
      header: 'Account',
      render: (row) => (
        <div>
          <button
            type="button"
            onClick={() => setSelected(row)}
            className="text-left font-medium text-foreground hover:text-primary hover:underline"
          >
            {row.name}
          </button>
          <p className="text-xs text-muted-foreground">{row.email}</p>
        </div>
      ),
    },
    {
      key: 'role',
      header: 'Role',
      render: (row) => (
        <StatusBadge tone={roleMeta[row.role].tone}>{roleMeta[row.role].label}</StatusBadge>
      ),
    },
    {
      key: 'team',
      header: 'Team',
      className: 'text-muted-foreground',
      render: (row) => row.team?.name ?? '—',
    },
    {
      key: 'status',
      header: 'Status',
      render: (row) => (
        <StatusBadge tone={userStatusMeta[row.status].tone}>
          {userStatusMeta[row.status].label}
        </StatusBadge>
      ),
    },
    {
      key: 'created_at',
      header: 'Added',
      className: 'text-muted-foreground',
      render: (row) => formatDate(row.created_at),
    },
    {
      key: 'actions',
      header: '',
      headerClassName: 'sr-only',
      render: (row) => (
        <button
          type="button"
          onClick={() => setSelected(row)}
          className="rounded-md border border-border px-2.5 py-1 text-xs font-medium text-foreground hover:bg-muted"
        >
          Manage
        </button>
      ),
    },
  ]

  const roleOptions = assignableRoles(actor?.role)

  return (
    <div>
      <PageHeader
        title="Users"
        description="Create, invite, suspend and restore CRM accounts."
        actions={
          <button
            type="button"
            onClick={() => {
              setCreateOpen((open) => !open)
              setCreateNotice('')
              setCreateError('')
              setCreateErrors({})
            }}
            className="inline-flex items-center gap-1.5 rounded-md bg-primary px-3 py-2 text-sm font-medium text-primary-foreground"
          >
            <UserPlus className="h-4 w-4" aria-hidden />
            New account
          </button>
        }
      />

      {createOpen && (
        <form
          className="mb-4 rounded-lg border border-border bg-card p-4"
          onSubmit={(event) => {
            event.preventDefault()
            setCreating(true)
            setCreateError('')
            setCreateNotice('')
            setCreateErrors({})
            void createAdminUser({
              name: newName.trim(),
              email: newEmail.trim(),
              role: newRole,
              team_id: newTeam === '' ? null : newTeam,
            })
              .then((result) => {
                setCreateNotice(
                  result.invitation.sent
                    ? `${result.user.name} was created and the invitation went to the simulated mailbox (${result.invitation.source}).`
                    : `${result.user.name} was created, but the gateway rejected the invitation: ${result.invitation.message ?? 'unknown reason'}.`,
                )
                setNewName('')
                setNewEmail('')
                setNewRole('salesrep')
                setNewTeam('')
                void load(1, filters)
              })
              .catch((caught) => {
                const validation = parseValidationError(caught)
                if (validation) {
                  setCreateErrors(validation.errors)
                  setCreateError(validation.message)
                } else {
                  setCreateError('Could not create the account.')
                }
              })
              .finally(() => setCreating(false))
          }}
        >
          <h3 className="text-sm font-semibold text-foreground">Create an account</h3>
          <p className="mt-0.5 text-xs text-muted-foreground">
            No password is set here. The account starts through the mocked invitation email.
          </p>

          {createNotice && (
            <div className="mt-3">
              <AlertItem variant="success">{createNotice}</AlertItem>
            </div>
          )}
          {createError && (
            <div className="mt-3">
              <AlertItem variant="danger">{createError}</AlertItem>
            </div>
          )}

          <div className="mt-3 grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <label className="block text-sm font-medium text-foreground">
              Name
              <input
                type="text"
                value={newName}
                onChange={(event) => setNewName(event.target.value)}
                className={`mt-1 ${inputClass}`}
                required
              />
              {createErrors.name && (
                <span className="mt-1 block text-xs text-destructive">{createErrors.name[0]}</span>
              )}
            </label>
            <label className="block text-sm font-medium text-foreground">
              Email
              <input
                type="email"
                value={newEmail}
                onChange={(event) => setNewEmail(event.target.value)}
                className={`mt-1 ${inputClass}`}
                required
              />
              {createErrors.email && (
                <span className="mt-1 block text-xs text-destructive">{createErrors.email[0]}</span>
              )}
            </label>
            <label className="block text-sm font-medium text-foreground">
              Role
              <select
                value={newRole}
                onChange={(event) => setNewRole(event.target.value as Role)}
                className={`mt-1 ${inputClass}`}
              >
                {roleOptions.map((value) => (
                  <option key={value} value={value}>
                    {roleMeta[value].label}
                  </option>
                ))}
              </select>
              {createErrors.role && (
                <span className="mt-1 block text-xs text-destructive">{createErrors.role[0]}</span>
              )}
            </label>
            <label className="block text-sm font-medium text-foreground">
              Team
              <select
                value={newTeam}
                onChange={(event) => setNewTeam(event.target.value)}
                className={`mt-1 ${inputClass}`}
              >
                <option value="">No team</option>
                {teams.map((team) => (
                  <option key={team.id} value={team.id}>
                    {team.name}
                  </option>
                ))}
              </select>
              {createErrors.team_id && (
                <span className="mt-1 block text-xs text-destructive">
                  {createErrors.team_id[0]}
                </span>
              )}
            </label>
          </div>

          <div className="mt-3 flex items-center gap-2">
            <button
              type="submit"
              disabled={creating}
              className="rounded-md bg-primary px-3 py-2 text-sm font-medium text-primary-foreground disabled:opacity-60"
            >
              {creating ? 'Creating…' : 'Create and invite'}
            </button>
            <button
              type="button"
              onClick={() => setCreateOpen(false)}
              className="rounded-md border border-border bg-card px-3 py-2 text-sm font-medium text-foreground hover:bg-muted"
            >
              Close
            </button>
          </div>
        </form>
      )}

      <form
        className="mb-4 flex flex-wrap items-center gap-2"
        onSubmit={(event) => {
          event.preventDefault()
          void load(1, filters)
        }}
      >
        <div className="relative w-full max-w-sm">
          <Search
            className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground"
            aria-hidden="true"
          />
          <input
            type="search"
            value={search}
            onChange={(event) => setSearch(event.target.value)}
            placeholder="Search name or email"
            className="w-full rounded-md border border-input bg-card py-2 pl-9 pr-3 text-sm text-foreground outline-none focus:ring-2 focus:ring-ring"
          />
        </div>

        <select
          value={role}
          onChange={(event) => setRole(event.target.value as Role | '')}
          aria-label="Filter by role"
          className="rounded-md border border-input bg-card px-3 py-2 text-sm text-foreground outline-none focus:ring-2 focus:ring-ring"
        >
          <option value="">All roles</option>
          {(Object.keys(roleMeta) as Role[]).map((value) => (
            <option key={value} value={value}>
              {roleMeta[value].label}
            </option>
          ))}
        </select>

        <select
          value={teamId}
          onChange={(event) => setTeamId(event.target.value)}
          aria-label="Filter by team"
          className="max-w-xs rounded-md border border-input bg-card px-3 py-2 text-sm text-foreground outline-none focus:ring-2 focus:ring-ring"
        >
          <option value="">All teams</option>
          {teams.map((team) => (
            <option key={team.id} value={team.id}>
              {team.name}
            </option>
          ))}
        </select>

        <select
          value={status}
          onChange={(event) => setStatus(event.target.value as UserStatus | '')}
          aria-label="Filter by status"
          className="rounded-md border border-input bg-card px-3 py-2 text-sm text-foreground outline-none focus:ring-2 focus:ring-ring"
        >
          <option value="">All statuses</option>
          {(Object.keys(userStatusMeta) as UserStatus[]).map((value) => (
            <option key={value} value={value}>
              {userStatusMeta[value].label}
            </option>
          ))}
        </select>

        <button
          type="submit"
          className="rounded-md border border-border bg-card px-3 py-2 text-sm font-medium text-foreground hover:bg-muted"
        >
          Filter
        </button>
      </form>

      {error && (
        <div className="mb-4">
          <AlertItem variant="danger">{error}</AlertItem>
        </div>
      )}

      <div className="grid grid-cols-1 gap-4 xl:grid-cols-3">
        <div className={selected ? 'xl:col-span-2' : 'xl:col-span-3'}>
          <DataTable
            columns={columns}
            rows={users}
            rowKey={(row) => row.id}
            loading={loading}
            caption="CRM accounts"
            pagination={{
              currentPage: page,
              lastPage,
              total,
              onPageChange: (next) => void load(next, filters),
            }}
            empty={
              <EmptyState
                title="No accounts match"
                description="Adjust the filters, or create the first account for this team."
              />
            }
          />
        </div>

        {selected && (
          <div className="xl:col-span-1">
            <AdminUserPanel
              key={selected.id}
              user={selected}
              teams={teams}
              actorRole={actor?.role}
              onChanged={handleChanged}
              onClose={() => setSelected(null)}
            />
          </div>
        )}
      </div>
    </div>
  )
}
