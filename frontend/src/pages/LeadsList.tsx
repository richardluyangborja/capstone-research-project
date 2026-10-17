import { useCallback, useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { Plus, Search } from 'lucide-react'
import PageHeader from '../components/common/PageHeader'
import StatusBadge from '../components/common/StatusBadge'
import EmptyState from '../components/common/EmptyState'
import AlertItem from '../components/common/AlertItem'
import LeadOwnerControl from '../components/common/LeadOwnerControl'
import LeadAgingBadge from '../components/common/LeadAgingBadge'
import DataTable, { type DataTableColumn } from '../components/common/DataTable'
import {
  assignLead,
  bulkAssignLeads,
  claimLead,
  getAssignableUsers,
  getLeadSummary,
  listLeads,
} from '../lib/leads'
import { leadRatingMeta, leadSourceLabels, leadStatusMeta } from '../lib/leadMeta'
import { formatDate } from '../lib/format'
import { useAuth } from '../lib/auth-context'
import type { AssignmentTarget, Lead, LeadStatus } from '../types'

type ViewKey = 'all' | 'mine' | 'unassigned' | 'team' | 'archived'

const statusOptions: { value: LeadStatus | ''; label: string }[] = [
  { value: '', label: 'All statuses' },
  { value: 'new', label: 'New' },
  { value: 'contacted', label: 'Contacted' },
  { value: 'qualified', label: 'Qualified' },
  { value: 'nurture', label: 'Nurture' },
  { value: 'disqualified', label: 'Disqualified' },
  { value: 'archived', label: 'Archived' },
]

// Owner filter per view (submodule 03). `mine` = the caller's own leads.
const ownerFilter: Record<ViewKey, string | undefined> = {
  all: undefined,
  mine: 'me',
  unassigned: 'unassigned',
  team: 'team',
  archived: undefined,
}

export default function LeadsList() {
  const { user } = useAuth()
  const canReassign = user?.role !== 'salesrep'

  const [leads, setLeads] = useState<Lead[]>([])
  const [targets, setTargets] = useState<AssignmentTarget[]>([])
  const [counts, setCounts] = useState<Record<ViewKey, number>>({
    all: 0,
    mine: 0,
    unassigned: 0,
    team: 0,
    archived: 0,
  })
  const [view, setView] = useState<ViewKey>('all')
  const [search, setSearch] = useState('')
  const [status, setStatus] = useState<string>('')
  const [staleOnly, setStaleOnly] = useState(false)
  const [selected, setSelected] = useState<string[]>([])
  const [bulkOwner, setBulkOwner] = useState<string>('')
  const [busy, setBusy] = useState(false)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')

  const load = useCallback(
    async (term: string, statusFilter: string, activeView: ViewKey, stale: boolean) => {
      setLoading(true)
      setError('')
      try {
        const [response, summary, assignable] = await Promise.all([
          listLeads({
            search: term || undefined,
            status: activeView === 'archived' ? 'archived' : statusFilter || undefined,
            owner: ownerFilter[activeView],
            stale: stale ? 'true' : undefined,
          }),
          getLeadSummary(),
          getAssignableUsers(),
        ])
        setLeads(response.data)
        setCounts(summary)
        setTargets(assignable)
        setSelected([])
      } catch {
        setError('Could not load leads. Please try again.')
      } finally {
        setLoading(false)
      }
    },
    [],
  )

  useEffect(() => {
    void load('', '', 'all', false)
  }, [load])

  const refreshCounts = async () => {
    try {
      setCounts(await getLeadSummary())
    } catch {
      // non-critical
    }
  }

  const replaceLead = (updated: Lead) =>
    setLeads((previous) => previous.map((lead) => (lead.id === updated.id ? updated : lead)))

  const onClaim = async (lead: Lead) => {
    setBusy(true)
    try {
      replaceLead(await claimLead(lead.id))
      await refreshCounts()
    } catch {
      setError(`Could not claim ${lead.reference}.`)
    } finally {
      setBusy(false)
    }
  }

  const onAssign = async (lead: Lead, ownerId: number) => {
    setBusy(true)
    try {
      replaceLead(await assignLead(lead.id, ownerId))
      await refreshCounts()
    } catch {
      setError(`Could not reassign ${lead.reference}.`)
    } finally {
      setBusy(false)
    }
  }

  const onBulkAssign = async () => {
    if (!bulkOwner || selected.length === 0) return
    setBusy(true)
    try {
      await bulkAssignLeads(selected, Number(bulkOwner))
      setBulkOwner('')
      await load(search, status, view, staleOnly)
    } catch {
      setError('Could not assign the selected leads.')
    } finally {
      setBusy(false)
    }
  }

  const toggleSelected = (id: string) =>
    setSelected((previous) =>
      previous.includes(id) ? previous.filter((value) => value !== id) : [...previous, id],
    )

  const tabs: { key: ViewKey; label: string }[] = [
    { key: 'all', label: 'All' },
    { key: 'mine', label: 'My Leads' },
    { key: 'unassigned', label: 'Unassigned' },
    ...(canReassign ? [{ key: 'team' as ViewKey, label: 'Team' }] : []),
    { key: 'archived', label: 'Archived' },
  ]

  const changeView = (next: ViewKey) => {
    setView(next)
    void load(search, status, next, staleOnly)
  }

  const columns: DataTableColumn<Lead>[] = [
    {
      key: 'reference',
      header: 'Reference',
      render: (lead) => (
        <Link to={`/leads/${lead.id}`} className="font-mono text-xs text-primary">
          {lead.reference}
        </Link>
      ),
    },
    {
      key: 'company',
      header: 'Company',
      render: (lead) => (
        <>
          <Link to={`/leads/${lead.id}`} className="font-medium text-foreground">
            {lead.company_name}
          </Link>
          <div className="text-xs text-muted-foreground">
            {lead.contact_name}
            {lead.contact_email ? ` · ${lead.contact_email}` : ''}
          </div>
        </>
      ),
    },
    {
      key: 'source',
      header: 'Source',
      className: 'text-muted-foreground',
      render: (lead) => leadSourceLabels[lead.source],
    },
    {
      key: 'status',
      header: 'Status',
      render: (lead) => (
        <StatusBadge tone={leadStatusMeta[lead.status].tone}>
          {leadStatusMeta[lead.status].label}
        </StatusBadge>
      ),
    },
    {
      key: 'rating',
      header: 'Rating',
      render: (lead) =>
        lead.rating ? (
          <StatusBadge tone={leadRatingMeta[lead.rating].tone}>
            {leadRatingMeta[lead.rating].label}
          </StatusBadge>
        ) : (
          <span className="text-muted-foreground">—</span>
        ),
    },
    {
      key: 'aging',
      header: 'Aging',
      render: (lead) =>
        lead.aging ? (
          <LeadAgingBadge aging={lead.aging} days={lead.days_since_activity} />
        ) : (
          <span className="text-muted-foreground">—</span>
        ),
    },
    {
      key: 'owner',
      header: 'Owner',
      render: (lead) => (
        <LeadOwnerControl
          lead={lead}
          targets={targets}
          busy={busy}
          onClaim={() => void onClaim(lead)}
          onAssign={(ownerId) => void onAssign(lead, ownerId)}
        />
      ),
    },
    {
      key: 'created',
      header: 'Created',
      className: 'text-muted-foreground',
      render: (lead) => formatDate(lead.created_at),
    },
  ]

  return (
    <div>
      <PageHeader
        title="Leads"
        description="Capture, qualify, assign and track prospective clients."
        actions={
          <Link
            to="/leads/new"
            className="inline-flex items-center gap-2 rounded-md bg-primary px-3 py-2 text-sm font-medium text-primary-foreground"
          >
            <Plus className="h-4 w-4" aria-hidden="true" />
            New lead
          </Link>
        }
      />

      <div className="mb-4 flex flex-wrap gap-1 rounded-lg border border-border bg-card p-1">
        {tabs.map((tab) => {
          const active = tab.key === view
          return (
            <button
              key={tab.key}
              type="button"
              onClick={() => changeView(tab.key)}
              className={`inline-flex items-center gap-2 rounded-md px-3 py-1.5 text-sm font-medium transition-colors ${
                active ? 'bg-secondary text-primary' : 'text-muted-foreground hover:bg-muted'
              }`}
            >
              {tab.label}
              <span
                className={`rounded px-1.5 text-xs ${
                  active ? 'bg-primary/10 text-primary' : 'bg-muted text-muted-foreground'
                }`}
              >
                {counts[tab.key]}
              </span>
            </button>
          )
        })}
      </div>

      <form
        className="mb-4 flex flex-wrap items-center gap-2"
        onSubmit={(event) => {
          event.preventDefault()
          void load(search, status, view, staleOnly)
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
            placeholder="Search company, contact or reference"
            className="w-full rounded-md border border-input bg-card py-2 pl-9 pr-3 text-sm text-foreground outline-none focus:ring-2 focus:ring-ring"
          />
        </div>

        {view !== 'archived' && (
          <select
            value={status}
            onChange={(event) => setStatus(event.target.value)}
            className="rounded-md border border-input bg-card px-3 py-2 text-sm text-foreground outline-none focus:ring-2 focus:ring-ring"
          >
            {statusOptions.map((option) => (
              <option key={option.value} value={option.value}>
                {option.label}
              </option>
            ))}
          </select>
        )}

        <label className="inline-flex items-center gap-2 rounded-md border border-input bg-card px-3 py-2 text-sm text-foreground">
          <input
            type="checkbox"
            checked={staleOnly}
            onChange={(event) => {
              setStaleOnly(event.target.checked)
              void load(search, status, view, event.target.checked)
            }}
            className="h-4 w-4 rounded border-input"
          />
          Stale only
        </label>

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

      <DataTable
        columns={columns}
        rows={leads}
        rowKey={(lead) => lead.id}
        loading={loading}
        selection={
          canReassign
            ? {
                selected,
                onToggle: toggleSelected,
                onToggleAll: (keys, checked) => setSelected(checked ? keys : []),
                onClear: () => setSelected([]),
              }
            : undefined
        }
        bulkActions={
          <>
            <select
              value={bulkOwner}
              onChange={(event) => setBulkOwner(event.target.value)}
              className="rounded-md border border-input bg-card px-2 py-1.5 text-sm text-foreground outline-none focus:ring-2 focus:ring-ring"
            >
              <option value="">Assign to…</option>
              {targets.map((target) => (
                <option key={target.id} value={target.id}>
                  {target.name}
                </option>
              ))}
            </select>
            <button
              type="button"
              disabled={busy || !bulkOwner}
              onClick={() => void onBulkAssign()}
              className="rounded-md bg-primary px-3 py-1.5 text-sm font-medium text-primary-foreground disabled:opacity-60"
            >
              Assign selected
            </button>
          </>
        }
        empty={
          <EmptyState
            title="No leads found"
            description="Capture the first inquiry to start the pipeline."
            action={
              <Link
                to="/leads/new"
                className="inline-flex items-center gap-2 rounded-md bg-primary px-3 py-2 text-sm font-medium text-primary-foreground"
              >
                <Plus className="h-4 w-4" aria-hidden="true" />
                New lead
              </Link>
            }
          />
        }
      />
    </div>
  )
}
