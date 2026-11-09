import { useCallback, useEffect, useState } from 'react'
import { Plus, Search } from 'lucide-react'
import PageHeader from '../../components/common/PageHeader'
import EmptyState from '../../components/common/EmptyState'
import AlertItem from '../../components/common/AlertItem'
import DataTable, { type DataTableColumn } from '../../components/common/DataTable'
import { createAdminTeam, getAdminTeam, listAdminTeams, listAdminUsers } from '../../lib/admin'
import { roleMeta } from '../../lib/adminMeta'
import { parseValidationError } from '../../lib/leads'
import { formatDate } from '../../lib/format'
import { useAuth } from '../../lib/auth-context'
import AdminTeamPanel from './AdminTeamPanel'
import type { AdminTeam, AdminUser } from '../../types'

const inputClass =
  'w-full rounded-md border border-input bg-card px-3 py-2 text-sm text-foreground outline-none focus:ring-2 focus:ring-ring disabled:opacity-60'

/**
 * The administrative team directory (module 08 S3, spec 19).
 *
 * A team is created and named here, a manager is assigned from active manager accounts, and
 * membership is edited in the detail panel. Every mutation is audited; none of them reassigns
 * CRM records, so ownership remains with the domain delegation flows.
 */
export default function AdminTeams() {
  const { user: actor } = useAuth()

  const [teams, setTeams] = useState<AdminTeam[]>([])
  const [managerOptions, setManagerOptions] = useState<AdminUser[]>([])
  const [selectedId, setSelectedId] = useState<string | null>(null)
  const [selectedTeam, setSelectedTeam] = useState<AdminTeam | null>(null)
  const [detailLoading, setDetailLoading] = useState(false)

  const [search, setSearch] = useState('')
  const [page, setPage] = useState(1)
  const [lastPage, setLastPage] = useState(1)
  const [total, setTotal] = useState(0)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')

  const [createOpen, setCreateOpen] = useState(false)
  const [newName, setNewName] = useState('')
  const [newManager, setNewManager] = useState('')
  const [creating, setCreating] = useState(false)
  const [createError, setCreateError] = useState('')
  const [createNotice, setCreateNotice] = useState('')
  const [createErrors, setCreateErrors] = useState<Record<string, string[]>>({})

  const load = useCallback(async (targetPage: number, term: string) => {
    setLoading(true)
    setError('')
    try {
      const response = await listAdminTeams({ search: term, page: targetPage })
      setTeams(response.data)
      setPage(response.meta.current_page)
      setLastPage(response.meta.last_page)
      setTotal(response.meta.total)
    } catch {
      setError('Could not load the team directory.')
    } finally {
      setLoading(false)
    }
  }, [])

  useEffect(() => {
    void load(1, '')
    void listAdminUsers({ role: 'manager', status: 'active', per_page: 100 })
      .then((response) => setManagerOptions(response.data))
      .catch(() => setManagerOptions([]))
  }, [load])

  const openTeam = (team: AdminTeam) => {
    setSelectedId(team.id)
    setSelectedTeam(null)
    setDetailLoading(true)
    void getAdminTeam(team.id)
      .then((next) => setSelectedTeam(next))
      .catch(() => setError('Could not load that team.'))
      .finally(() => setDetailLoading(false))
  }

  const handleChanged = (next: AdminTeam) => {
    setSelectedTeam(next)
    setTeams((rows) => rows.map((row) => (row.id === next.id ? next : row)))
    void load(page, search)
  }

  const columns: DataTableColumn<AdminTeam>[] = [
    {
      key: 'name',
      header: 'Team',
      render: (row) => (
        <button
          type="button"
          onClick={() => openTeam(row)}
          className="text-left font-medium text-foreground hover:text-primary hover:underline"
        >
          {row.name}
        </button>
      ),
    },
    {
      key: 'manager',
      header: 'Manager',
      render: (row) =>
        row.manager ? (
          <div>
            <p className="text-sm text-foreground">{row.manager.name}</p>
            <p className="text-xs text-muted-foreground">{row.manager.email}</p>
          </div>
        ) : (
          <span className="text-sm text-muted-foreground">No manager</span>
        ),
    },
    {
      key: 'members_count',
      header: 'Members',
      className: 'text-muted-foreground',
      render: (row) => row.members_count ?? 0,
    },
    {
      key: 'created_at',
      header: 'Created',
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
          onClick={() => openTeam(row)}
          className="rounded-md border border-border px-2.5 py-1 text-xs font-medium text-foreground hover:bg-muted"
        >
          Manage
        </button>
      ),
    },
  ]

  return (
    <div>
      <PageHeader
        title="Teams"
        description="Team managers, membership and the sales organization."
        actions={
          <button
            type="button"
            onClick={() => {
              setCreateOpen((open) => !open)
              setCreateError('')
              setCreateNotice('')
              setCreateErrors({})
            }}
            className="inline-flex items-center gap-1.5 rounded-md bg-primary px-3 py-2 text-sm font-medium text-primary-foreground"
          >
            <Plus className="h-4 w-4" aria-hidden />
            New team
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
            void createAdminTeam({
              name: newName.trim(),
              manager_id: newManager === '' ? null : newManager,
            })
              .then((team) => {
                setCreateNotice(`${team.name} was created.`)
                setNewName('')
                setNewManager('')
                void load(1, search)
              })
              .catch((caught) => {
                const validation = parseValidationError(caught)
                if (validation) {
                  setCreateErrors(validation.errors)
                  setCreateError(validation.message)
                } else {
                  setCreateError('Could not create the team.')
                }
              })
              .finally(() => setCreating(false))
          }}
        >
          <h3 className="text-sm font-semibold text-foreground">Create a team</h3>
          <p className="mt-0.5 text-xs text-muted-foreground">
            The manager is optional now; a team can be named before its lead is chosen.
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

          <div className="mt-3 grid grid-cols-1 gap-3 sm:grid-cols-2">
            <label className="block text-sm font-medium text-foreground">
              Team name
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
              Manager
              <select
                value={newManager}
                onChange={(event) => setNewManager(event.target.value)}
                className={`mt-1 ${inputClass}`}
              >
                <option value="">No manager yet</option>
                {managerOptions.map((manager) => (
                  <option key={manager.id} value={manager.id}>
                    {manager.name} · {roleMeta[manager.role].label}
                  </option>
                ))}
              </select>
              {createErrors.manager_id && (
                <span className="mt-1 block text-xs text-destructive">
                  {createErrors.manager_id[0]}
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
              {creating ? 'Creating…' : 'Create team'}
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
          void load(1, search)
        }}
      >
        <div className="relative w-full max-w-sm">
          <Search
            className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground"
            aria-hidden
          />
          <input
            type="search"
            value={search}
            onChange={(event) => setSearch(event.target.value)}
            placeholder="Search teams by name"
            className="w-full rounded-md border border-input bg-card py-2 pl-9 pr-3 text-sm text-foreground outline-none focus:ring-2 focus:ring-ring"
          />
        </div>
        <button
          type="submit"
          className="rounded-md border border-border bg-card px-3 py-2 text-sm font-medium text-foreground hover:bg-muted"
        >
          Search
        </button>
      </form>

      {error && (
        <div className="mb-4">
          <AlertItem variant="danger">{error}</AlertItem>
        </div>
      )}

      <div className="grid grid-cols-1 gap-4 xl:grid-cols-3">
        <div className={selectedId ? 'xl:col-span-2' : 'xl:col-span-3'}>
          <DataTable
            columns={columns}
            rows={teams}
            rowKey={(row) => row.id}
            loading={loading}
            caption="Sales teams"
            pagination={{
              currentPage: page,
              lastPage,
              total,
              onPageChange: (next) => void load(next, search),
            }}
            empty={
              <EmptyState
                title="No teams match"
                description="Adjust the search, or create the first team for the sales organization."
              />
            }
          />
        </div>

        {selectedId && (
          <div className="xl:col-span-1">
            {detailLoading || selectedTeam === null ? (
              <div className="rounded-lg border border-border bg-card p-4 text-sm text-muted-foreground">
                Loading team…
              </div>
            ) : (
              <AdminTeamPanel
                key={selectedTeam.id}
                team={selectedTeam}
                managerOptions={managerOptions}
                actorRole={actor?.role}
                onChanged={handleChanged}
                onClose={() => {
                  setSelectedId(null)
                  setSelectedTeam(null)
                }}
              />
            )}
          </div>
        )}
      </div>
    </div>
  )
}
