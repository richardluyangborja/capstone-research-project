import { useCallback, useEffect, useState } from 'react'
import { Link, useSearchParams } from 'react-router-dom'
import { Plus, Search } from 'lucide-react'
import PageHeader from '../components/common/PageHeader'
import StatusBadge from '../components/common/StatusBadge'
import EmptyState from '../components/common/EmptyState'
import AlertItem from '../components/common/AlertItem'
import DataTable, { type DataTableColumn } from '../components/common/DataTable'
import { listCompanies } from '../lib/companies'
import { getAssignableUsers } from '../lib/leads'
import { companyStatusMeta, companyTierLabels } from '../lib/companyMeta'
import { followUpDueMeta } from '../lib/followUpMeta'
import { formatDate, formatDateTime } from '../lib/format'
import { useAuth } from '../lib/auth-context'
import type { AssignmentTarget, Company, CompanyStatus, CompanyTier } from '../types'

type BookView = 'all' | 'clients'

/** Every client state — a prospect is not a client yet (spec 00). */
const clientStatuses: CompanyStatus[] = ['onboarding', 'active', 'at_risk', 'dormant', 'churned']
const allClients = clientStatuses.join(',')

interface AccountFilters {
  search: string
  status: string
  tier: string
  owner: string
  industry: string
  view: BookView
}

const statusOptionsFor = (view: BookView): { value: string; label: string }[] => {
  if (view === 'clients') {
    return [
      { value: '', label: 'All clients' },
      ...clientStatuses.map((value) => ({ value, label: companyStatusMeta[value].label })),
    ]
  }

  return [
    { value: '', label: 'All statuses' },
    ...(Object.keys(companyStatusMeta) as CompanyStatus[]).map((value) => ({
      value,
      label: companyStatusMeta[value].label,
    })),
  ]
}

const statusForView = (view: BookView, status: string): string | undefined =>
  view === 'clients' ? status || allClients : status || undefined

const columns: DataTableColumn<Company>[] = [
  {
    key: 'name',
    header: 'Account',
    render: (company) => (
      <>
        <Link to={`/accounts/${company.id}`} className="font-medium text-foreground">
          {company.name}
        </Link>
        <div className="font-mono text-xs text-muted-foreground">{company.reference}</div>
      </>
    ),
  },
  {
    key: 'status',
    header: 'Status',
    render: (company) => (
      <StatusBadge tone={companyStatusMeta[company.status].tone}>
        {companyStatusMeta[company.status].label}
      </StatusBadge>
    ),
  },
  {
    key: 'tier',
    header: 'Tier',
    render: (company) =>
      company.tier ? (
        <StatusBadge tone="cost">{companyTierLabels[company.tier]}</StatusBadge>
      ) : (
        <span className="text-muted-foreground">—</span>
      ),
  },
  {
    key: 'owner',
    header: 'Owner',
    className: 'text-muted-foreground',
    render: (company) => company.owner?.name ?? '—',
  },
  {
    key: 'contracts',
    header: 'Contracts',
    className: 'text-counts',
    render: (company) => (
      <Link to={`/accounts/${company.id}?tab=contracts`} className="font-medium hover:underline">
        {company.counts.contracts}
      </Link>
    ),
  },
  {
    key: 'open_deals',
    header: 'Open deals',
    className: 'text-counts',
    render: (company) => (
      <Link
        to={`/accounts/${company.id}?tab=opportunities`}
        className="font-medium hover:underline"
      >
        {company.counts.open_opportunities}
      </Link>
    ),
  },
  {
    key: 'next_follow_up',
    header: 'Next follow-up',
    className: 'whitespace-nowrap',
    render: (company) =>
      company.next_follow_up ? (
        <div className="flex flex-col items-start gap-1">
          <StatusBadge tone={followUpDueMeta[company.next_follow_up.state].tone}>
            {followUpDueMeta[company.next_follow_up.state].label}
          </StatusBadge>
          <span className="text-xs text-muted-foreground">
            {formatDateTime(company.next_follow_up_at)}
          </span>
        </div>
      ) : (
        <span className="text-muted-foreground">—</span>
      ),
  },
  {
    key: 'last_activity',
    header: 'Last activity',
    className: 'whitespace-nowrap text-muted-foreground',
    render: (company) => (company.last_activity_at ? formatDate(company.last_activity_at) : '—'),
  },
]

/**
 * Module 01's Account Book (spec 16 §Account Book): one scoped book for prospects and clients.
 * `?view=clients` is the old `/clients` book — the same rows and filters, no second page.
 */
export default function Companies() {
  const [searchParams, setSearchParams] = useSearchParams()
  const { user } = useAuth()

  const requestedView = searchParams.get('view')
  const view: BookView = requestedView === 'clients' ? 'clients' : 'all'
  const canonicalView = view === 'clients' ? 'clients' : null

  const [companies, setCompanies] = useState<Company[]>([])
  const [targets, setTargets] = useState<AssignmentTarget[]>([])
  const [search, setSearch] = useState('')
  const [status, setStatus] = useState('')
  const [tier, setTier] = useState('')
  const [owner, setOwner] = useState('')
  const [industry, setIndustry] = useState('')
  const [page, setPage] = useState(1)
  const [lastPage, setLastPage] = useState(1)
  const [total, setTotal] = useState(0)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')

  const load = useCallback(async (targetPage: number, filters: AccountFilters) => {
    setLoading(true)
    setError('')
    try {
      const [response, assignable] = await Promise.all([
        listCompanies({
          search: filters.search || undefined,
          status: statusForView(filters.view, filters.status),
          tier: filters.tier || undefined,
          industry: filters.industry.trim() || undefined,
          owner_id: filters.owner ? Number(filters.owner) : undefined,
          page: targetPage,
        }),
        getAssignableUsers().catch(() => []),
      ])
      setCompanies(response.data)
      setTargets(assignable)
      setPage(response.meta.current_page)
      setLastPage(response.meta.last_page)
      setTotal(response.meta.total)
    } catch {
      setError('Could not load the Account Book. Please try again.')
    } finally {
      setLoading(false)
    }
  }, [])

  // The book view owns the filter defaults: switching all/clients starts the other book clean.
  useEffect(() => {
    setSearch('')
    setStatus('')
    setTier('')
    setOwner('')
    setIndustry('')
    setPage(1)
    void load(1, {
      search: '',
      status: '',
      tier: '',
      owner: '',
      industry: '',
      view,
    })
  }, [load, view])

  // `?view=all` is the default book, so it leaves no redundant parameter behind.
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

  const changeView = (next: BookView) => {
    if (next === view) return

    const params = new URLSearchParams(searchParams)
    if (next === 'all') {
      params.delete('view')
    } else {
      params.set('view', next)
    }
    setSearchParams(params)
  }

  const filters: AccountFilters = { search, status, tier, owner, industry, view }
  const hasFilters = Boolean(search || status || tier || owner || industry)
  const ownerOptions: AssignmentTarget[] =
    user && !targets.some((target) => target.id === user.id)
      ? [...targets, { id: user.id, name: user.name, team_id: null }]
      : targets

  const resetFilters = () => {
    setSearch('')
    setStatus('')
    setTier('')
    setOwner('')
    setIndustry('')
    void load(1, { search: '', status: '', tier: '', owner: '', industry: '', view })
  }

  return (
    <div>
      <PageHeader
        title="Account Book"
        description="Every prospect and client you can see, with its commercial book and next action."
        actions={
          <Link
            to="/accounts/new"
            className="inline-flex items-center gap-2 rounded-md bg-primary px-3 py-2 text-sm font-medium text-primary-foreground"
          >
            <Plus className="h-4 w-4" aria-hidden="true" />
            New account
          </Link>
        }
      />

      <div className="mb-4 flex flex-wrap gap-1 rounded-lg border border-border bg-card p-1">
        {(['all', 'clients'] as BookView[]).map((key) => {
          const active = key === view
          return (
            <button
              key={key}
              type="button"
              onClick={() => changeView(key)}
              className={`rounded-md px-3 py-1.5 text-sm font-medium transition-colors ${
                active ? 'bg-secondary text-primary' : 'text-muted-foreground hover:bg-muted'
              }`}
            >
              {key === 'all' ? 'All accounts' : 'Clients'}
            </button>
          )
        })}
      </div>

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
            placeholder="Search name, trade name or reference"
            className="w-full rounded-md border border-input bg-card py-2 pl-9 pr-3 text-sm text-foreground outline-none focus:ring-2 focus:ring-ring"
          />
        </div>

        <select
          value={status}
          onChange={(event) => setStatus(event.target.value)}
          aria-label="Status"
          className="rounded-md border border-input bg-card px-3 py-2 text-sm text-foreground outline-none focus:ring-2 focus:ring-ring"
        >
          {statusOptionsFor(view).map((option) => (
            <option key={option.value} value={option.value}>
              {option.label}
            </option>
          ))}
        </select>

        <select
          value={tier}
          onChange={(event) => setTier(event.target.value)}
          aria-label="Tier"
          className="rounded-md border border-input bg-card px-3 py-2 text-sm text-foreground outline-none focus:ring-2 focus:ring-ring"
        >
          <option value="">All tiers</option>
          {(Object.keys(companyTierLabels) as CompanyTier[]).map((value) => (
            <option key={value} value={value}>
              {companyTierLabels[value]}
            </option>
          ))}
        </select>

        <select
          value={owner}
          onChange={(event) => setOwner(event.target.value)}
          aria-label="Owner"
          className="rounded-md border border-input bg-card px-3 py-2 text-sm text-foreground outline-none focus:ring-2 focus:ring-ring"
        >
          <option value="">All owners</option>
          {ownerOptions.map((target) => (
            <option key={target.id} value={target.id}>
              {target.name}
            </option>
          ))}
        </select>

        <input
          type="search"
          value={industry}
          onChange={(event) => setIndustry(event.target.value)}
          aria-label="Industry"
          placeholder="Industry"
          className="w-40 rounded-md border border-input bg-card px-3 py-2 text-sm text-foreground outline-none focus:ring-2 focus:ring-ring"
        />

        <button
          type="submit"
          className="rounded-md border border-border bg-card px-3 py-2 text-sm font-medium text-foreground hover:bg-muted"
        >
          Filter
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

      {error && (
        <div className="mb-4">
          <AlertItem variant="danger">{error}</AlertItem>
        </div>
      )}

      <DataTable
        columns={columns}
        rows={companies}
        rowKey={(company) => company.id}
        loading={loading}
        pagination={{
          currentPage: page,
          lastPage,
          total,
          onPageChange: (next) => void load(next, filters),
        }}
        empty={
          <EmptyState
            title={view === 'clients' ? 'No clients yet' : 'No accounts yet'}
            description={
              view === 'clients'
                ? 'A company appears here once it moves past prospect — usually when its first contract is signed.'
                : 'Add an existing client, or convert a qualified lead.'
            }
            action={
              view === 'clients' ? undefined : (
                <Link
                  to="/accounts/new"
                  className="inline-flex items-center gap-2 rounded-md bg-primary px-3 py-2 text-sm font-medium text-primary-foreground"
                >
                  <Plus className="h-4 w-4" aria-hidden="true" />
                  New account
                </Link>
              )
            }
          />
        }
      />
    </div>
  )
}
