import { useCallback, useEffect, useState } from 'react'
import { Link, useSearchParams } from 'react-router-dom'
import { ArrowRightLeft, Plus, Search } from 'lucide-react'
import PageHeader from '../components/common/PageHeader'
import StatusBadge from '../components/common/StatusBadge'
import EmptyState from '../components/common/EmptyState'
import AlertItem from '../components/common/AlertItem'
import StatCard from '../components/common/StatCard'
import LeadOwnerControl from '../components/common/LeadOwnerControl'
import LeadAgingBadge from '../components/common/LeadAgingBadge'
import DataTable, { type DataTableColumn } from '../components/common/DataTable'
import {
  assignLead,
  bulkAssignLeads,
  bulkTransferLeads,
  claimLead,
  getAssignableUsers,
  getLeadSummary,
  listLeads,
} from '../lib/leads'
import { getFollowUpCounts } from '../lib/followUps'
import { leadRatingMeta, leadSourceLabels, leadStatusMeta } from '../lib/leadMeta'
import { followUpDueMeta } from '../lib/followUpMeta'
import { formatDate, formatDateTime } from '../lib/format'
import { useAuth } from '../lib/auth-context'
import type { AssignmentTarget, Lead, LeadStatus } from '../types'

type ViewKey =
  'all' | 'mine' | 'unassigned' | 'team' | 'qualification' | 'aging' | 'conversion' | 'archived'

const viewKeys: ViewKey[] = [
  'all',
  'mine',
  'unassigned',
  'team',
  'qualification',
  'aging',
  'conversion',
  'archived',
]

// Owner filter per view (submodule 03). `mine` = the caller's own leads.
const ownerFilter: Record<ViewKey, string | undefined> = {
  all: undefined,
  mine: 'me',
  unassigned: 'unassigned',
  team: 'team',
  qualification: undefined,
  aging: undefined,
  conversion: undefined,
  archived: undefined,
}

const viewDescriptions: Record<ViewKey, string> = {
  all: 'Every lead you can see — capture, qualify, assign and track prospective clients.',
  mine: 'The leads assigned to you.',
  unassigned: 'The shared, claimable pool. Claim a lead before you work it.',
  team: 'Your team’s open work, assignment and bulk hand-over.',
  qualification: 'Leads still awaiting qualification (new and contacted).',
  aging: 'Leads going cold — stale, at risk or due for archive.',
  conversion: 'Qualified leads ready to become an account and first deal.',
  archived: 'Archived leads, kept for the record. Restore one from its detail page.',
}

const emptyCounts: Record<ViewKey, number> = {
  all: 0,
  mine: 0,
  unassigned: 0,
  team: 0,
  qualification: 0,
  aging: 0,
  conversion: 0,
  archived: 0,
}

const statusOptions: { value: LeadStatus | ''; label: string }[] = [
  { value: '', label: 'All statuses' },
  { value: 'new', label: 'New' },
  { value: 'contacted', label: 'Contacted' },
  { value: 'qualified', label: 'Qualified' },
  { value: 'nurture', label: 'Nurture' },
  { value: 'disqualified', label: 'Disqualified' },
]

const ratingOptions = [
  { value: '', label: 'All ratings' },
  { value: 'hot', label: 'Hot' },
  { value: 'warm', label: 'Warm' },
  { value: 'cold', label: 'Cold' },
]

const urgencyOptions = [
  { value: '', label: 'All urgency' },
  { value: 'high', label: 'High' },
  { value: 'medium', label: 'Medium' },
  { value: 'low', label: 'Low' },
]

const isViewKey = (value: string | null): value is ViewKey =>
  value !== null && (viewKeys as string[]).includes(value)

const resolveView = (value: string | null, canReassign: boolean): ViewKey => {
  const candidate = isViewKey(value) ? value : 'all'
  return candidate === 'team' && !canReassign ? 'all' : candidate
}

const statusForView = (view: ViewKey, statusFilter: string): string | undefined => {
  if (view === 'archived') return 'archived'
  if (view === 'qualification') return 'new,contacted'
  if (view === 'conversion') return 'qualified'
  return statusFilter || undefined
}

const staleForView = (view: ViewKey, staleOnly: boolean): string | undefined =>
  view === 'aging' || staleOnly ? 'true' : undefined

export default function LeadsList() {
  const [searchParams, setSearchParams] = useSearchParams()
  const { user } = useAuth()
  const canReassign = user?.role !== 'salesrep'

  const requestedView = searchParams.get('view')
  const view = resolveView(requestedView, canReassign)
  const canonicalView = view === 'all' ? null : view

  const [leads, setLeads] = useState<Lead[]>([])
  const [targets, setTargets] = useState<AssignmentTarget[]>([])
  const [counts, setCounts] = useState<Record<ViewKey, number>>(emptyCounts)
  const [overdueFollowUps, setOverdueFollowUps] = useState<number | null>(null)
  const [search, setSearch] = useState('')
  const [status, setStatus] = useState<string>('')
  const [rating, setRating] = useState('')
  const [urgency, setUrgency] = useState('')
  const [staleOnly, setStaleOnly] = useState(false)
  const [selected, setSelected] = useState<string[]>([])
  const [bulkOwner, setBulkOwner] = useState<string>('')
  const [bulkFrom, setBulkFrom] = useState('')
  const [bulkTo, setBulkTo] = useState('')
  const [busy, setBusy] = useState(false)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')
  const [notice, setNotice] = useState('')

  const load = useCallback(
    async (
      term: string,
      statusFilter: string,
      ratingFilter: string,
      urgencyFilter: string,
      activeView: ViewKey,
      stale: boolean,
    ) => {
      setLoading(true)
      setError('')
      try {
        const showWorkload = canReassign && (activeView === 'team' || activeView === 'unassigned')
        const [response, summary, assignable, followUps] = await Promise.all([
          listLeads({
            search: term || undefined,
            status: statusForView(activeView, statusFilter),
            rating: ratingFilter || undefined,
            urgency: urgencyFilter || undefined,
            owner: ownerFilter[activeView],
            stale: staleForView(activeView, stale),
          }),
          getLeadSummary(),
          getAssignableUsers(),
          showWorkload
            ? getFollowUpCounts({ owner: 'team' }).catch(() => null)
            : Promise.resolve(null),
        ])
        setLeads(response.data)
        setCounts(summary)
        setTargets(assignable)
        setOverdueFollowUps(followUps?.overdue ?? null)
        setSelected([])
      } catch {
        setError('Could not load leads. Please try again.')
      } finally {
        setLoading(false)
      }
    },
    [canReassign],
  )

  // The view owns the filter defaults: switching saved views clears the toolbar and reloads.
  useEffect(() => {
    setSearch('')
    setStatus('')
    setRating('')
    setUrgency('')
    setStaleOnly(false)
    setSelected([])
    setNotice('')
    void load('', '', '', '', view, false)
  }, [load, view])

  // A hidden/unknown view (for example a salesrep opening `?view=team`) falls back to the
  // scoped book without leaving a misleading address behind.
  useEffect(() => {
    if ((requestedView ?? null) === canonicalView) return

    const params = new URLSearchParams(searchParams)
    if (canonicalView) {
      params.set('view', canonicalView)
    } else {
      params.delete('view')
    }
    setSearchParams(params, { replace: true })
  }, [canonicalView, requestedView, searchParams, setSearchParams])

  const changeView = (next: ViewKey) => {
    if (next === view) return

    const params = new URLSearchParams(searchParams)
    if (next === 'all') {
      params.delete('view')
    } else {
      params.set('view', next)
    }
    setSearchParams(params)
  }

  const resetFilters = () => {
    setSearch('')
    setStatus('')
    setRating('')
    setUrgency('')
    setStaleOnly(false)
    setNotice('')
    void load('', '', '', '', view, false)
  }

  const onClaim = async (lead: Lead) => {
    setBusy(true)
    setError('')
    setNotice('')
    try {
      await claimLead(lead.id)
      setNotice(`Claimed ${lead.reference}.`)
      await load(search, status, rating, urgency, view, staleOnly)
    } catch {
      setError(`Could not claim ${lead.reference}.`)
    } finally {
      setBusy(false)
    }
  }

  const onAssign = async (lead: Lead, ownerId: number) => {
    setBusy(true)
    setError('')
    setNotice('')
    try {
      await assignLead(lead.id, ownerId)
      setNotice(`Assigned ${lead.reference}.`)
      await load(search, status, rating, urgency, view, staleOnly)
    } catch {
      setError(`Could not reassign ${lead.reference}.`)
    } finally {
      setBusy(false)
    }
  }

  const onBulkAssign = async () => {
    if (!bulkOwner || selected.length === 0) return
    setBusy(true)
    setError('')
    setNotice('')
    try {
      await bulkAssignLeads(selected, Number(bulkOwner))
      setBulkOwner('')
      setNotice('Assigned the selected leads.')
      await load(search, status, rating, urgency, view, staleOnly)
    } catch {
      setError('Could not assign the selected leads.')
    } finally {
      setBusy(false)
    }
  }

  const onBulkTransfer = async () => {
    if (!bulkFrom || !bulkTo) return
    setBusy(true)
    setError('')
    setNotice('')
    try {
      const { transferred } = await bulkTransferLeads(Number(bulkFrom), Number(bulkTo))
      const fromName = targets.find((target) => String(target.id) === bulkFrom)?.name ?? 'the rep'
      const toName = targets.find((target) => String(target.id) === bulkTo)?.name ?? 'the new owner'
      setBulkFrom('')
      setBulkTo('')
      setNotice(`${transferred} lead(s) transferred from ${fromName} to ${toName}.`)
      await load(search, status, rating, urgency, view, staleOnly)
    } catch {
      setError('Could not transfer leads. Check both users are in your team.')
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
    { key: 'qualification', label: 'Needs qualification' },
    { key: 'aging', label: 'Aging' },
    { key: 'conversion', label: 'Ready to convert' },
    { key: 'archived', label: 'Archived' },
  ]

  const showStatusFilter = !['qualification', 'conversion', 'archived'].includes(view)
  const showStaleFilter = !['aging', 'archived'].includes(view)
  const hasFilters = Boolean(search || status || rating || urgency || staleOnly)

  const showWorkload = canReassign && (view === 'team' || view === 'unassigned')
  const openLeads = targets.reduce((total, target) => total + (target.open_leads_count ?? 0), 0)
  const workload = [...targets].sort(
    (a, b) => (b.open_leads_count ?? 0) - (a.open_leads_count ?? 0),
  )

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
      key: 'next_follow_up',
      header: 'Next follow-up',
      className: 'whitespace-nowrap',
      render: (lead) =>
        lead.next_follow_up ? (
          <div className="flex flex-col items-start gap-1">
            <StatusBadge tone={followUpDueMeta[lead.next_follow_up.state].tone}>
              {followUpDueMeta[lead.next_follow_up.state].label}
            </StatusBadge>
            <span className="text-xs text-muted-foreground">
              {formatDateTime(lead.next_follow_up_at)}
            </span>
          </div>
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

  if (view === 'qualification') {
    columns.push({
      key: 'action',
      header: null,
      headerClassName: 'text-right',
      className: 'text-right',
      render: (lead) => (
        <Link to={`/leads/${lead.id}`} className="text-sm font-medium text-primary">
          Qualify
        </Link>
      ),
    })
  }

  if (view === 'conversion') {
    columns.push({
      key: 'action',
      header: null,
      headerClassName: 'text-right',
      className: 'text-right',
      render: (lead) =>
        lead.can_convert ? (
          <Link to={`/leads/${lead.id}/convert`} className="text-sm font-medium text-primary">
            Convert
          </Link>
        ) : (
          <span className="text-xs text-muted-foreground">Not yours</span>
        ),
    })
  }

  const empty = emptyCopy(view)

  return (
    <div>
      <PageHeader
        title="Lead Workspace"
        description={viewDescriptions[view]}
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

      {showWorkload && (
        <section className="mb-6 grid grid-cols-1 gap-4 xl:grid-cols-3">
          <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:col-span-2">
            <StatCard
              label="Open leads"
              value={openLeads}
              domain="counts"
              hint="Open leads across assignable salesreps"
            />
            <StatCard
              label="Overdue follow-ups"
              value={overdueFollowUps ?? '—'}
              domain="activity"
              hint="Team work past its due time"
            />
            <StatCard
              label="Unassigned queue"
              value={counts.unassigned}
              domain="counts"
              hint="Shared, claimable leads"
            />

            <div className="rounded-lg border border-border bg-card p-4 sm:col-span-2">
              <h2 className="text-sm font-semibold text-foreground">Workload by rep</h2>
              <p className="mt-1 text-xs text-muted-foreground">
                Open leads each assignable salesrep is carrying.
              </p>

              <ul className="mt-3 divide-y divide-border">
                {workload.length === 0 && (
                  <li className="py-2 text-sm text-muted-foreground">No assignable salesreps.</li>
                )}
                {workload.map((target) => (
                  <li
                    key={target.id}
                    className="flex items-center justify-between gap-3 py-2 text-sm"
                  >
                    <span className="truncate text-foreground">{target.name}</span>
                    <span className="shrink-0 rounded bg-secondary px-2 py-0.5 text-xs font-medium text-secondary-foreground">
                      {target.open_leads_count ?? 0} open
                    </span>
                  </li>
                ))}
              </ul>
            </div>
          </div>

          <div className="rounded-lg border border-border bg-card p-4">
            <h2 className="text-sm font-semibold text-foreground">Bulk transfer</h2>
            <p className="mt-1 text-xs text-muted-foreground">
              Move every lead from one salesrep to another — for leave or a changed patch.
            </p>

            <div className="mt-3 flex flex-col gap-3">
              <label className="text-xs font-medium text-muted-foreground">
                From
                <select
                  value={bulkFrom}
                  onChange={(event) => setBulkFrom(event.target.value)}
                  className="mt-1 w-full rounded-md border border-input bg-card px-2 py-2 text-sm text-foreground outline-none focus:ring-2 focus:ring-ring"
                >
                  <option value="">Select salesrep…</option>
                  {targets.map((target) => (
                    <option key={target.id} value={target.id}>
                      {target.name}
                    </option>
                  ))}
                </select>
              </label>

              <label className="text-xs font-medium text-muted-foreground">
                To
                <select
                  value={bulkTo}
                  onChange={(event) => setBulkTo(event.target.value)}
                  className="mt-1 w-full rounded-md border border-input bg-card px-2 py-2 text-sm text-foreground outline-none focus:ring-2 focus:ring-ring"
                >
                  <option value="">Select salesrep…</option>
                  {targets
                    .filter((target) => String(target.id) !== bulkFrom)
                    .map((target) => (
                      <option key={target.id} value={target.id}>
                        {target.name}
                      </option>
                    ))}
                </select>
              </label>

              <button
                type="button"
                disabled={busy || !bulkFrom || !bulkTo}
                onClick={() => void onBulkTransfer()}
                className="inline-flex items-center justify-center gap-2 rounded-md border border-border px-3 py-2 text-sm font-medium text-foreground hover:bg-muted disabled:opacity-60"
              >
                <ArrowRightLeft className="h-4 w-4" aria-hidden="true" />
                Transfer leads
              </button>
            </div>
          </div>
        </section>
      )}

      <form
        className="mb-4 flex flex-wrap items-center gap-2"
        onSubmit={(event) => {
          event.preventDefault()
          void load(search, status, rating, urgency, view, staleOnly)
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

        {showStatusFilter && (
          <select
            value={status}
            onChange={(event) => setStatus(event.target.value)}
            aria-label="Status"
            className="rounded-md border border-input bg-card px-3 py-2 text-sm text-foreground outline-none focus:ring-2 focus:ring-ring"
          >
            {statusOptions.map((option) => (
              <option key={option.value} value={option.value}>
                {option.label}
              </option>
            ))}
          </select>
        )}

        <select
          value={rating}
          onChange={(event) => setRating(event.target.value)}
          aria-label="Rating"
          className="rounded-md border border-input bg-card px-3 py-2 text-sm text-foreground outline-none focus:ring-2 focus:ring-ring"
        >
          {ratingOptions.map((option) => (
            <option key={option.value} value={option.value}>
              {option.label}
            </option>
          ))}
        </select>

        <select
          value={urgency}
          onChange={(event) => setUrgency(event.target.value)}
          aria-label="Urgency"
          className="rounded-md border border-input bg-card px-3 py-2 text-sm text-foreground outline-none focus:ring-2 focus:ring-ring"
        >
          {urgencyOptions.map((option) => (
            <option key={option.value} value={option.value}>
              {option.label}
            </option>
          ))}
        </select>

        {showStaleFilter && (
          <label className="inline-flex items-center gap-2 rounded-md border border-input bg-card px-3 py-2 text-sm text-foreground">
            <input
              type="checkbox"
              checked={staleOnly}
              onChange={(event) => {
                setStaleOnly(event.target.checked)
                void load(search, status, rating, urgency, view, event.target.checked)
              }}
              className="h-4 w-4 rounded border-input"
            />
            Stale only
          </label>
        )}

        <button
          type="submit"
          className="rounded-md border border-border bg-card px-3 py-2 text-sm font-medium text-foreground hover:bg-muted"
        >
          Search
        </button>

        {hasFilters && (
          <button
            type="button"
            onClick={resetFilters}
            className="rounded-md px-3 py-2 text-sm font-medium text-muted-foreground hover:text-foreground"
          >
            Clear filters
          </button>
        )}
      </form>

      {notice && (
        <div className="mb-4">
          <AlertItem variant="success">{notice}</AlertItem>
        </div>
      )}

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
            title={empty.title}
            description={empty.description}
            action={
              empty.offerNewLead ? (
                <Link
                  to="/leads/new"
                  className="inline-flex items-center gap-2 rounded-md bg-primary px-3 py-2 text-sm font-medium text-primary-foreground"
                >
                  <Plus className="h-4 w-4" aria-hidden="true" />
                  New lead
                </Link>
              ) : undefined
            }
          />
        }
      />
    </div>
  )
}

function emptyCopy(view: ViewKey): {
  title: string
  description: string
  offerNewLead: boolean
} {
  switch (view) {
    case 'mine':
      return {
        title: 'No leads assigned to you',
        description: 'Claim one from the shared queue or capture a new enquiry.',
        offerNewLead: true,
      }
    case 'unassigned':
      return {
        title: 'Queue is clear',
        description: 'Every lead has an owner. New captures land here when unowned.',
        offerNewLead: false,
      }
    case 'team':
      return {
        title: 'No team leads',
        description: 'Your team has no leads in this view.',
        offerNewLead: false,
      }
    case 'qualification':
      return {
        title: 'Nothing waiting to qualify',
        description: 'New and contacted leads will appear here for review.',
        offerNewLead: false,
      }
    case 'aging':
      return {
        title: 'Nothing going cold',
        description: 'Every open lead has activity inside the configured threshold.',
        offerNewLead: false,
      }
    case 'conversion':
      return {
        title: 'Nothing to convert',
        description: 'Leads appear here once they are qualified.',
        offerNewLead: false,
      }
    case 'archived':
      return {
        title: 'No archived leads',
        description: 'Leads archived from the workspace are kept here.',
        offerNewLead: false,
      }
    default:
      return {
        title: 'No leads found',
        description: 'Capture the first inquiry to start the pipeline.',
        offerNewLead: true,
      }
  }
}
