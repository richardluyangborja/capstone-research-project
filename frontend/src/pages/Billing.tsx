import { useCallback, useEffect, useState } from 'react'
import { Link, useSearchParams } from 'react-router-dom'
import {
  AlertTriangle,
  Building2,
  ExternalLink,
  Filter,
  Lock,
  ReceiptText,
  RefreshCw,
  Search,
  Wallet,
} from 'lucide-react'
import DataTable, { type DataTableColumn } from '../components/common/DataTable'
import EmptyState from '../components/common/EmptyState'
import PageHeader from '../components/common/PageHeader'
import StatCard from '../components/common/StatCard'
import StatusBadge from '../components/common/StatusBadge'
import { useAuth } from '../lib/auth-context'
import { listBilling } from '../lib/billing'
import { billingAccountMeta } from '../lib/billingMeta'
import { listCompanies } from '../lib/companies'
import { formatCurrency, formatDate, formatDateTime } from '../lib/format'
import { getAssignableUsers } from '../lib/leads'
import type {
  AssignmentTarget,
  BillingBook,
  BillingBookFilters,
  BillingBookRow,
  BillingBookSummary,
  Company,
} from '../types'

const agingLabels = [
  ['Current', 'current'],
  ['1–30', '1_30'],
  ['31–60', '31_60'],
  ['60+', '60_plus'],
] as const

/**
 * The cross-account AR book (module 01 refresh S12, spec 16 §Read-only external feeds).
 * Finance owns the invoices and balances; the CRM only presents them, so there is no
 * create, edit or payment action anywhere on this page.
 */
export default function Billing() {
  const { user } = useAuth()
  const isManagerOrAdmin =
    user?.role === 'manager' || user?.role === 'admin' || user?.role === 'superadmin'

  const [searchParams, setSearchParams] = useSearchParams()

  const [rows, setRows] = useState<BillingBookRow[]>([])
  const [summary, setSummary] = useState<BillingBookSummary | null>(null)
  const [meta, setMeta] = useState<BillingBook['meta'] | null>(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')

  const [search, setSearch] = useState(searchParams.get('search') ?? '')
  const [status, setStatus] = useState(searchParams.get('status') ?? '')
  const [aging, setAging] = useState(searchParams.get('aging') ?? '')
  const [company, setCompany] = useState(searchParams.get('company') ?? '')
  const [owner, setOwner] = useState(searchParams.get('owner') ?? '')
  const [overdueOnly, setOverdueOnly] = useState(searchParams.get('overdue') === 'true')

  const [companies, setCompanies] = useState<Company[]>([])
  const [owners, setOwners] = useState<AssignmentTarget[]>([])

  const [page, setPage] = useState(1)
  const [lastPage, setLastPage] = useState(1)
  const [total, setTotal] = useState(0)

  const buildFilters = useCallback(
    (targetPage: number): BillingBookFilters => {
      const filters: BillingBookFilters = { page: targetPage }
      if (search.trim()) filters.search = search.trim()
      if (status) filters.status = status
      if (aging) filters.aging = aging
      if (company) filters.company = company
      if (owner) filters.owner = owner
      if (overdueOnly) filters.overdue = true
      return filters
    },
    [search, status, aging, company, owner, overdueOnly],
  )

  const loadBilling = useCallback(
    async (targetPage: number) => {
      setLoading(true)
      setError('')
      try {
        const res = await listBilling(buildFilters(targetPage))
        setRows(res.data)
        setSummary(res.summary)
        setMeta(res.meta)
        setTotal(res.meta.total)
        setLastPage(res.meta.last_page)
        setPage(res.meta.current_page)
      } catch (err) {
        console.error(err)
        setError('Could not load the AR book. Please try again.')
      } finally {
        setLoading(false)
      }
    },
    [buildFilters],
  )

  useEffect(() => {
    if (!isManagerOrAdmin) {
      setLoading(false)
      return
    }

    void listCompanies({ per_page: 100 })
      .then((response) => setCompanies(response.data))
      .catch(() => undefined)
    void getAssignableUsers()
      .then(setOwners)
      .catch(() => undefined)
  }, [isManagerOrAdmin])

  useEffect(() => {
    if (isManagerOrAdmin) {
      void loadBilling(1)
    }
  }, [isManagerOrAdmin, loadBilling])

  useEffect(() => {
    const params = new URLSearchParams()
    if (search.trim()) params.set('search', search.trim())
    if (status) params.set('status', status)
    if (aging) params.set('aging', aging)
    if (company) params.set('company', company)
    if (owner) params.set('owner', owner)
    if (overdueOnly) params.set('overdue', 'true')
    setSearchParams(params, { replace: true })
  }, [search, status, aging, company, owner, overdueOnly, setSearchParams])

  if (!isManagerOrAdmin) {
    return (
      <div className="space-y-6">
        <PageHeader
          title="Billing"
          description="Cross-account accounts receivable, aging and overdue balances."
        />
        <EmptyState
          title="Manager access only"
          description="The AR book is available to managers and administrators. Ask your manager for a billing summary."
        />
      </div>
    )
  }

  const columns: DataTableColumn<BillingBookRow>[] = [
    {
      key: 'account',
      header: 'Account',
      render: (row) => (
        <div className="flex flex-col">
          <Link
            to={`/accounts/${row.company.id}?tab=commercial&section=billing`}
            className="font-medium text-foreground hover:underline"
          >
            {row.company.name}
          </Link>
          <span className="font-mono text-xs text-muted-foreground">{row.company.reference}</span>
          <span className="text-xs text-muted-foreground">
            {row.owner?.name ?? 'No account owner'}
          </span>
        </div>
      ),
    },
    {
      key: 'status',
      header: 'AR status',
      render: (row) => (
        <StatusBadge tone={billingAccountMeta[row.account_status].tone}>
          {billingAccountMeta[row.account_status].label}
        </StatusBadge>
      ),
    },
    {
      key: 'outstanding',
      header: 'Outstanding',
      className: 'text-right',
      headerClassName: 'text-right',
      render: (row) => (
        <span className="font-medium text-cost">{formatCurrency(row.outstanding)}</span>
      ),
    },
    {
      key: 'overdue',
      header: 'Overdue',
      className: 'text-right',
      headerClassName: 'text-right',
      render: (row) => (
        <div className="flex flex-col items-end">
          <span
            className={Number(row.overdue) > 0 ? 'font-semibold text-danger' : 'text-foreground'}
          >
            {Number(row.overdue) > 0 ? formatCurrency(row.overdue) : '—'}
          </span>
          {row.overdue_invoices > 0 && (
            <span className="text-xs text-muted-foreground">
              {row.overdue_invoices} invoice{row.overdue_invoices === 1 ? '' : 's'}
            </span>
          )}
        </div>
      ),
    },
    {
      key: 'aging',
      header: 'Aging',
      render: (row) => (
        <div className="grid grid-cols-2 gap-x-3 gap-y-0.5 text-xs">
          {agingLabels.map(([label, key]) => (
            <div key={key} className="flex justify-between gap-2">
              <span className="text-muted-foreground">{label}</span>
              <span
                className={Number(row.aging[key]) > 0 ? 'text-foreground' : 'text-muted-foreground'}
              >
                {formatCurrency(row.aging[key])}
              </span>
            </div>
          ))}
        </div>
      ),
    },
    {
      key: 'invoices',
      header: 'Invoices',
      render: (row) => (
        <div className="flex flex-col">
          <span className="text-foreground">{row.invoice_count} total</span>
          <span className="text-xs text-muted-foreground">{row.open_invoices} open</span>
        </div>
      ),
    },
    {
      key: 'next_due',
      header: 'Terms / next due',
      className: 'text-muted-foreground',
      render: (row) => (
        <div className="flex flex-col text-xs">
          <span>{row.credit_terms}</span>
          <span>{row.next_due_date ? formatDate(row.next_due_date) : '—'}</span>
          {row.last_invoice_date && <span>Last invoice {formatDate(row.last_invoice_date)}</span>}
        </div>
      ),
    },
    {
      key: 'open',
      header: '',
      className: 'text-right',
      render: (row) => (
        <Link
          to={`/accounts/${row.company.id}?tab=commercial&section=billing`}
          title="Open the account billing feed"
          className="inline-flex h-8 w-8 items-center justify-center rounded-md border border-input bg-background text-muted-foreground hover:bg-accent hover:text-foreground"
        >
          <ExternalLink className="h-4 w-4" />
        </Link>
      ),
    },
  ]

  const hasFilters = Boolean(search || status || aging || company || owner || overdueOnly)

  return (
    <div className="space-y-6">
      <PageHeader
        title="Billing"
        description="Cross-account accounts receivable: outstanding balances, aging, overdue invoices and the accounts that need a Finance follow-up."
      />

      <div className="flex flex-wrap items-center justify-between gap-2 text-xs text-muted-foreground">
        <span className="inline-flex items-center gap-1.5">
          <Lock className="h-3.5 w-3.5" aria-hidden="true" />
          Read-only Finance feed — no invoice or payment can be changed here
        </span>
        <span>
          {meta ? (
            <>
              {meta.source} · As of {formatDateTime(meta.as_of)}
            </>
          ) : (
            'Loading source…'
          )}
        </span>
      </div>

      {error && (
        <div className="flex flex-wrap items-center justify-between gap-3 rounded-md border border-danger/30 bg-danger/10 p-4 text-sm text-danger">
          <span>{error}</span>
          <button
            type="button"
            onClick={() => void loadBilling(page)}
            className="inline-flex items-center gap-2 rounded-md border border-danger/40 px-3 py-1.5 text-xs font-medium hover:bg-danger/10"
          >
            <RefreshCw className="h-3.5 w-3.5" aria-hidden="true" />
            Retry
          </button>
        </div>
      )}

      <div className="grid grid-cols-2 gap-4 lg:grid-cols-4">
        <StatCard
          label="Accounts with AR"
          value={summary?.accounts ?? 0}
          domain="counts"
          icon={Building2}
          hint={`${summary?.open_invoices ?? 0} open invoices`}
        />
        <StatCard
          label="Outstanding"
          value={formatCurrency(summary?.outstanding ?? '0')}
          domain="cost"
          icon={Wallet}
          hint={`${formatCurrency(summary?.aging.current ?? '0')} current`}
        />
        <StatCard
          label="Overdue"
          value={formatCurrency(summary?.overdue ?? '0')}
          domain={Number(summary?.overdue ?? 0) > 0 ? 'cost' : 'counts'}
          icon={AlertTriangle}
          hint={`${summary?.overdue_accounts ?? 0} account${
            summary?.overdue_accounts === 1 ? '' : 's'
          } overdue`}
        />
        <StatCard
          label="Open invoices"
          value={summary?.open_invoices ?? 0}
          domain="activity"
          icon={ReceiptText}
          hint={`${formatCurrency(summary?.aging['1_30'] ?? '0')} in 1–30 days`}
        />
      </div>

      <div className="flex flex-wrap items-center gap-3 rounded-lg border border-border bg-card p-4">
        <div className="relative min-w-0 flex-1">
          <Search className="absolute left-3 top-2.5 h-4 w-4 text-muted-foreground" />
          <input
            type="text"
            placeholder="Search account, reference, owner or terms…"
            value={search}
            onChange={(event) => setSearch(event.target.value)}
            className="w-full rounded-md border border-input bg-background py-2 pl-9 pr-3 text-sm placeholder:text-muted-foreground focus:outline-none focus:ring-2 focus:ring-ring"
          />
        </div>

        <select
          value={status}
          onChange={(event) => setStatus(event.target.value)}
          className="rounded-md border border-input bg-background px-3 py-2 text-sm text-foreground focus:outline-none focus:ring-2 focus:ring-ring"
          aria-label="Filter by AR status"
        >
          <option value="">All AR statuses</option>
          <option value="current">Current</option>
          <option value="overdue">Overdue</option>
          <option value="closed">Closed</option>
        </select>

        <select
          value={aging}
          onChange={(event) => setAging(event.target.value)}
          className="rounded-md border border-input bg-background px-3 py-2 text-sm text-foreground focus:outline-none focus:ring-2 focus:ring-ring"
          aria-label="Filter by aging bucket"
        >
          <option value="">All aging buckets</option>
          <option value="current">Current</option>
          <option value="1_30">1–30 days</option>
          <option value="31_60">31–60 days</option>
          <option value="60_plus">60+ days</option>
        </select>

        <select
          value={company}
          onChange={(event) => setCompany(event.target.value)}
          className="rounded-md border border-input bg-background px-3 py-2 text-sm text-foreground focus:outline-none focus:ring-2 focus:ring-ring"
          aria-label="Filter by account"
        >
          <option value="">All accounts</option>
          {companies.map((item) => (
            <option key={item.id} value={item.id}>
              {item.name}
            </option>
          ))}
        </select>

        <select
          value={owner}
          onChange={(event) => setOwner(event.target.value)}
          className="rounded-md border border-input bg-background px-3 py-2 text-sm text-foreground focus:outline-none focus:ring-2 focus:ring-ring"
          aria-label="Filter by account owner"
        >
          <option value="">All owners</option>
          <option value="me">My accounts</option>
          <option value="team">Team accounts</option>
          {owners.map((target) => (
            <option key={target.id} value={target.id}>
              {target.name}
            </option>
          ))}
        </select>

        <button
          type="button"
          onClick={() => setOverdueOnly((value) => !value)}
          className={`inline-flex items-center gap-1.5 rounded-md px-3 py-2 text-sm font-medium transition-colors ${
            overdueOnly
              ? 'border border-danger/40 bg-danger/10 text-danger'
              : 'border border-input bg-background text-muted-foreground hover:bg-accent hover:text-foreground'
          }`}
        >
          <AlertTriangle className="h-4 w-4" />
          Overdue only
        </button>

        {hasFilters && (
          <button
            type="button"
            onClick={() => {
              setSearch('')
              setStatus('')
              setAging('')
              setCompany('')
              setOwner('')
              setOverdueOnly(false)
            }}
            className="inline-flex items-center gap-1 text-sm text-muted-foreground hover:text-foreground"
          >
            <Filter className="h-3.5 w-3.5" />
            Clear filters
          </button>
        )}
      </div>

      <DataTable
        columns={columns}
        rows={rows}
        rowKey={(row) => row.company.id}
        loading={loading}
        loadingLabel="Loading the AR book…"
        empty={
          <EmptyState
            title="No accounts with AR"
            description={
              hasFilters
                ? 'Try adjusting your filters. The book only covers accounts you may see.'
                : 'Accounts appear once Finance has issued an invoice for them.'
            }
          />
        }
        pagination={
          lastPage > 1
            ? {
                currentPage: page,
                lastPage,
                total,
                onPageChange: (newPage) => void loadBilling(newPage),
              }
            : undefined
        }
      />
    </div>
  )
}
