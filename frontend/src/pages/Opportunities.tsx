import { useCallback, useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { SquareKanban, Plus, Search } from 'lucide-react'
import PageHeader from '../components/common/PageHeader'
import StatusBadge from '../components/common/StatusBadge'
import EmptyState from '../components/common/EmptyState'
import AlertItem from '../components/common/AlertItem'
import StatCard from '../components/common/StatCard'
import DataTable, { type DataTableColumn } from '../components/common/DataTable'
import { listOpportunities, type OpportunityFilters } from '../lib/opportunities'
import { opportunityStageMeta } from '../lib/opportunityMeta'
import { formatCurrency, formatDate } from '../lib/format'
import type { Opportunity, OpportunityStage, OpportunitySummary } from '../types'

const columns: DataTableColumn<Opportunity>[] = [
  {
    key: 'name',
    header: 'Deal',
    render: (deal) => (
      <>
        <Link to={`/opportunities/${deal.id}`} className="font-medium text-foreground">
          {deal.name}
        </Link>
        <div className="font-mono text-xs text-muted-foreground">{deal.reference}</div>
      </>
    ),
  },
  {
    key: 'company',
    header: 'Account',
    render: (deal) =>
      deal.company ? (
        <Link to={`/accounts/${deal.company.id}`} className="text-foreground hover:underline">
          {deal.company.name}
        </Link>
      ) : (
        '—'
      ),
  },
  {
    key: 'stage',
    header: 'Stage',
    render: (deal) => (
      <StatusBadge tone={opportunityStageMeta[deal.stage].tone}>
        {opportunityStageMeta[deal.stage].label}
      </StatusBadge>
    ),
  },
  {
    key: 'amount',
    header: 'Amount',
    className: 'text-right text-cost',
    render: (deal) => formatCurrency(deal.amount),
  },
  {
    key: 'close',
    header: 'Expected close',
    className: 'text-muted-foreground',
    render: (deal) => (deal.expected_close_date ? formatDate(deal.expected_close_date) : '—'),
  },
  {
    key: 'next_step',
    header: 'Next step',
    className: 'text-muted-foreground',
    render: (deal) => deal.next_step ?? '—',
  },
  {
    key: 'owner',
    header: 'Owner',
    className: 'text-muted-foreground',
    render: (deal) => deal.owner?.name ?? '—',
  },
]

// Module 02 §2 — the deal book: every deal the caller may see, with the same filters as the
// board. Stage moves live on the board and the deal page.
export default function Opportunities() {
  const [deals, setDeals] = useState<Opportunity[]>([])
  const [summary, setSummary] = useState<OpportunitySummary | null>(null)
  const [search, setSearch] = useState('')
  const [stage, setStage] = useState('')
  const [owner, setOwner] = useState('')
  const [openOnly, setOpenOnly] = useState(false)
  const [page, setPage] = useState(1)
  const [lastPage, setLastPage] = useState(1)
  const [total, setTotal] = useState(0)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')

  const buildFilters = useCallback(
    (targetPage: number): OpportunityFilters => ({
      search: search || undefined,
      stage: stage || undefined,
      owner: owner || undefined,
      open: openOnly || undefined,
      page: targetPage,
    }),
    [search, stage, owner, openOnly],
  )

  const load = useCallback(
    async (targetPage: number) => {
      setLoading(true)
      setError('')
      try {
        const response = await listOpportunities(buildFilters(targetPage))
        setDeals(response.data)
        setSummary(response.summary)
        setPage(response.meta.current_page)
        setLastPage(response.meta.last_page)
        setTotal(response.meta.total)
      } catch {
        setError('Could not load deals. Please try again.')
      } finally {
        setLoading(false)
      }
    },
    [buildFilters],
  )

  useEffect(() => {
    void load(1)
  }, [load])

  return (
    <div>
      <PageHeader
        title="Deals"
        description="Every opportunity, open or closed. Move a deal's stage from the board or its page."
        actions={
          <>
            <Link
              to="/pipeline"
              className="inline-flex items-center gap-2 rounded-md border border-border bg-card px-3 py-2 text-sm font-medium text-foreground hover:bg-muted"
            >
              <SquareKanban className="h-4 w-4" aria-hidden="true" />
              Pipeline board
            </Link>
            <Link
              to="/opportunities/new"
              className="inline-flex items-center gap-2 rounded-md bg-primary px-3 py-2 text-sm font-medium text-primary-foreground"
            >
              <Plus className="h-4 w-4" aria-hidden="true" />
              New deal
            </Link>
          </>
        }
      />

      {summary && (
        <div className="mb-6 grid grid-cols-2 gap-4 lg:grid-cols-4">
          <StatCard label="All deals" value={summary.all} />
          <StatCard label="Open" value={summary.open} domain="cost" />
          <StatCard label="Won" value={summary.won} domain="people" />
          <StatCard label="Lost" value={summary.lost} domain="activity" />
        </div>
      )}

      <form
        className="mb-4 flex flex-wrap items-center gap-2"
        onSubmit={(event) => {
          event.preventDefault()
          void load(1)
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
            placeholder="Search deal, account or reference"
            className="w-full rounded-md border border-input bg-card py-2 pl-9 pr-3 text-sm text-foreground outline-none focus:ring-2 focus:ring-ring"
          />
        </div>

        <select
          aria-label="Stage filter"
          value={stage}
          onChange={(event) => setStage(event.target.value)}
          className="rounded-md border border-input bg-card px-3 py-2 text-sm text-foreground outline-none focus:ring-2 focus:ring-ring"
        >
          <option value="">All stages</option>
          {(Object.keys(opportunityStageMeta) as OpportunityStage[]).map((value) => (
            <option key={value} value={value}>
              {opportunityStageMeta[value].label}
            </option>
          ))}
        </select>

        <select
          aria-label="Owner filter"
          value={owner}
          onChange={(event) => setOwner(event.target.value)}
          className="rounded-md border border-input bg-card px-3 py-2 text-sm text-foreground outline-none focus:ring-2 focus:ring-ring"
        >
          <option value="">All owners</option>
          <option value="me">My deals</option>
          <option value="team">My team</option>
        </select>

        <label className="inline-flex items-center gap-2 text-sm text-foreground">
          <input
            type="checkbox"
            checked={openOnly}
            onChange={(event) => setOpenOnly(event.target.checked)}
            className="h-4 w-4 rounded border-input"
          />
          Open only
        </label>

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

      <DataTable
        columns={columns}
        rows={deals}
        rowKey={(deal) => deal.id}
        loading={loading}
        loadingLabel="Loading deals…"
        pagination={{
          currentPage: page,
          lastPage,
          total,
          onPageChange: (next) => void load(next),
        }}
        empty={
          <EmptyState
            title="No deals yet"
            description="Open a deal on an account, or convert a qualified lead."
            action={
              <Link
                to="/opportunities/new"
                className="inline-flex items-center gap-2 rounded-md bg-primary px-3 py-2 text-sm font-medium text-primary-foreground"
              >
                <Plus className="h-4 w-4" aria-hidden="true" />
                New deal
              </Link>
            }
          />
        }
      />
    </div>
  )
}
