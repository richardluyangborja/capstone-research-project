import { useCallback, useEffect, useState } from 'react'
import { Link, useSearchParams } from 'react-router-dom'
import {
  Activity,
  Clock,
  ExternalLink,
  Filter,
  Lock,
  RefreshCw,
  Search,
  Truck,
  Users,
} from 'lucide-react'
import DataTable, { type DataTableColumn } from '../components/common/DataTable'
import EmptyState from '../components/common/EmptyState'
import PageHeader from '../components/common/PageHeader'
import StatCard from '../components/common/StatCard'
import StatusBadge from '../components/common/StatusBadge'
import { listCompanies } from '../lib/companies'
import { listContracts } from '../lib/contracts'
import { listDeployments } from '../lib/deliveries'
import { deploymentMeta, formatHours, timesheetMeta } from '../lib/deliveryMeta'
import { formatDate, formatDateTime } from '../lib/format'
import { getAssignableUsers } from '../lib/leads'
import { useAuth } from '../lib/auth-context'
import type {
  AssignmentTarget,
  Company,
  Contract,
  DeploymentBook,
  DeploymentBookFilters,
  DeploymentBookRow,
  DeploymentBookSummary,
} from '../types'

/**
 * The cross-account deployment book (module 01 refresh S10, spec 16 §Read-only external
 * feeds). It flattens the Deployment and Timekeeping feeds over the accounts the caller may
 * see. Everything is read-only; there is no create, edit, complete or approve action.
 */
export default function Deployments() {
  const { user } = useAuth()
  const isManagerOrAdmin =
    user?.role === 'manager' || user?.role === 'admin' || user?.role === 'superadmin'

  const [searchParams, setSearchParams] = useSearchParams()

  const [deployments, setDeployments] = useState<DeploymentBookRow[]>([])
  const [summary, setSummary] = useState<DeploymentBookSummary | null>(null)
  const [meta, setMeta] = useState<DeploymentBook['meta'] | null>(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')

  // Filter state backed by search params.
  const [search, setSearch] = useState(searchParams.get('search') ?? '')
  const [status, setStatus] = useState(searchParams.get('status') ?? '')
  const [company, setCompany] = useState(searchParams.get('company') ?? '')
  const [contract, setContract] = useState(searchParams.get('contract') ?? '')
  const [owner, setOwner] = useState(searchParams.get('owner') ?? '')

  const [companies, setCompanies] = useState<Company[]>([])
  const [contracts, setContracts] = useState<Contract[]>([])
  const [owners, setOwners] = useState<AssignmentTarget[]>([])

  const [page, setPage] = useState(1)
  const [lastPage, setLastPage] = useState(1)
  const [total, setTotal] = useState(0)

  const buildFilters = useCallback(
    (targetPage: number): DeploymentBookFilters => {
      const filters: DeploymentBookFilters = { page: targetPage }
      if (search.trim()) filters.search = search.trim()
      if (status) filters.status = status
      if (company) filters.company = company
      if (contract) filters.contract = contract
      if (owner) filters.owner = owner
      return filters
    },
    [search, status, company, contract, owner],
  )

  const loadDeployments = useCallback(
    async (targetPage: number) => {
      setLoading(true)
      setError('')
      try {
        const res = await listDeployments(buildFilters(targetPage))
        setDeployments(res.data)
        setSummary(res.summary)
        setMeta(res.meta)
        setTotal(res.meta.total)
        setLastPage(res.meta.last_page)
        setPage(res.meta.current_page)
      } catch (err) {
        console.error(err)
        setError('Could not load the deployment book. Please try again.')
      } finally {
        setLoading(false)
      }
    },
    [buildFilters],
  )

  useEffect(() => {
    void listCompanies({ per_page: 100 })
      .then((response) => setCompanies(response.data))
      .catch(() => undefined)
    void listContracts({ per_page: 100 })
      .then((response) => setContracts(response.data))
      .catch(() => undefined)

    if (isManagerOrAdmin) {
      void getAssignableUsers()
        .then(setOwners)
        .catch(() => undefined)
    }
  }, [isManagerOrAdmin])

  useEffect(() => {
    void loadDeployments(1)
  }, [loadDeployments])

  // Keep the book's view in the URL so a filtered deployment list is shareable.
  useEffect(() => {
    const params = new URLSearchParams()
    if (search.trim()) params.set('search', search.trim())
    if (status) params.set('status', status)
    if (company) params.set('company', company)
    if (contract) params.set('contract', contract)
    if (owner) params.set('owner', owner)
    setSearchParams(params, { replace: true })
  }, [search, status, company, contract, owner, setSearchParams])

  const contractOptions = company
    ? contracts.filter((item) => item.company?.id === company)
    : contracts

  const columns: DataTableColumn<DeploymentBookRow>[] = [
    {
      key: 'reference',
      header: 'Deployment',
      render: (deployment) => (
        <div className="flex flex-col">
          <span className="font-mono text-xs font-semibold text-foreground">
            {deployment.reference}
          </span>
          <Link
            to={`/contracts/${deployment.contract_id}`}
            className="font-mono text-xs text-primary hover:underline"
          >
            {deployment.contract_reference}
          </Link>
        </div>
      ),
    },
    {
      key: 'account',
      header: 'Account',
      render: (deployment) => (
        <div className="flex flex-col">
          <Link
            to={`/accounts/${deployment.company.id}?tab=delivery`}
            className="font-medium text-foreground hover:underline"
          >
            {deployment.company.name}
          </Link>
          <span className="text-xs text-muted-foreground">
            {deployment.owner?.name ?? 'No account owner'}
          </span>
        </div>
      ),
    },
    {
      key: 'role',
      header: 'Role / Site',
      render: (deployment) => (
        <div className="flex flex-col">
          <span className="font-medium text-foreground">{deployment.role}</span>
          <span className="text-xs text-muted-foreground">{deployment.site}</span>
          {deployment.operations_owner && (
            <span className="text-xs text-muted-foreground">{deployment.operations_owner}</span>
          )}
        </div>
      ),
    },
    {
      key: 'status',
      header: 'Status',
      render: (deployment) => (
        <StatusBadge tone={deploymentMeta[deployment.status].tone}>
          {deploymentMeta[deployment.status].label}
        </StatusBadge>
      ),
    },
    {
      key: 'headcount',
      header: 'Headcount',
      className: 'text-right',
      headerClassName: 'text-right',
      render: (deployment) => (
        <div className="flex flex-col items-end">
          <span className="font-medium text-foreground">
            {deployment.active_headcount} / {deployment.requested_headcount}
          </span>
          <span className="text-xs text-muted-foreground">{deployment.fill_rate}% filled</span>
        </div>
      ),
    },
    {
      key: 'term',
      header: 'Term',
      className: 'text-muted-foreground',
      render: (deployment) => (
        <span className="text-xs">
          {formatDate(deployment.start_date) || '—'} – {formatDate(deployment.end_date) || '—'}
        </span>
      ),
    },
    {
      key: 'timesheet',
      header: 'Timesheet',
      render: (deployment) =>
        deployment.timesheet === null ? (
          <span className="text-xs text-muted-foreground">—</span>
        ) : (
          <div className="flex flex-col gap-0.5">
            <StatusBadge tone={timesheetMeta[deployment.timesheet.status].tone}>
              {timesheetMeta[deployment.timesheet.status].label}
            </StatusBadge>
            <span className="text-xs text-muted-foreground">
              {formatHours(deployment.timesheet.approved_hours)} approved
            </span>
            {deployment.timesheet.pending_hours > 0 && (
              <span className="text-xs text-muted-foreground">
                {formatHours(deployment.timesheet.pending_hours)} pending
              </span>
            )}
          </div>
        ),
    },
    {
      key: 'open',
      header: '',
      className: 'text-right',
      render: (deployment) => (
        <Link
          to={`/accounts/${deployment.company.id}?tab=delivery`}
          title="Open the account delivery feed"
          className="inline-flex h-8 w-8 items-center justify-center rounded-md border border-input bg-background text-muted-foreground hover:bg-accent hover:text-foreground"
        >
          <ExternalLink className="h-4 w-4" />
        </Link>
      ),
    },
  ]

  const hasFilters = Boolean(search || status || company || contract || owner)

  return (
    <div className="space-y-6">
      <PageHeader
        title="Deployments"
        description="Cross-account Deployment and Timekeeping feed: who is on site, how full the crew is and which hours still need a decision."
      />

      <div className="flex flex-wrap items-center justify-between gap-2 text-xs text-muted-foreground">
        <span className="inline-flex items-center gap-1.5">
          <Lock className="h-3.5 w-3.5" aria-hidden="true" />
          Read-only feed — no deployment or timesheet can be changed here
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
            onClick={() => void loadDeployments(page)}
            className="inline-flex items-center gap-2 rounded-md border border-danger/40 px-3 py-1.5 text-xs font-medium hover:bg-danger/10"
          >
            <RefreshCw className="h-3.5 w-3.5" aria-hidden="true" />
            Retry
          </button>
        </div>
      )}

      <div className="grid grid-cols-2 gap-4 lg:grid-cols-4">
        <StatCard
          label="Total deployments"
          value={summary?.total ?? 0}
          domain="counts"
          icon={Truck}
          hint={`${summary?.active ?? 0} active · ${summary?.pending ?? 0} pending · ${
            summary?.ended ?? 0
          } ended`}
        />
        <StatCard
          label="Active deployments"
          value={summary?.active ?? 0}
          domain="people"
          icon={Users}
          hint={`${summary?.fill_rate ?? 0}% overall fill`}
        />
        <StatCard
          label="Overall fill rate"
          value={`${summary?.fill_rate ?? 0}%`}
          domain="people"
          icon={Activity}
          hint="Active heads ÷ requested heads on open deployments"
        />
        <StatCard
          label="Timesheets pending"
          value={summary?.deployments_pending ?? 0}
          domain="activity"
          icon={Clock}
          hint={`${formatHours(summary?.approved_hours ?? 0)} approved · ${formatHours(
            summary?.pending_hours ?? 0,
          )} pending`}
        />
      </div>

      <div className="flex flex-wrap items-center gap-3 rounded-lg border border-border bg-card p-4">
        <div className="relative min-w-0 flex-1">
          <Search className="absolute left-3 top-2.5 h-4 w-4 text-muted-foreground" />
          <input
            type="text"
            placeholder="Search deployment, role, site, account or contract…"
            value={search}
            onChange={(e) => setSearch(e.target.value)}
            className="w-full rounded-md border border-input bg-background py-2 pl-9 pr-3 text-sm placeholder:text-muted-foreground focus:outline-none focus:ring-2 focus:ring-ring"
          />
        </div>

        <select
          value={status}
          onChange={(e) => setStatus(e.target.value)}
          className="rounded-md border border-input bg-background px-3 py-2 text-sm text-foreground focus:outline-none focus:ring-2 focus:ring-ring"
          aria-label="Filter by deployment status"
        >
          <option value="">All statuses</option>
          <option value="active">Active</option>
          <option value="pending">Pending</option>
          <option value="ended">Ended</option>
        </select>

        <select
          value={company}
          onChange={(e) => {
            setCompany(e.target.value)
            setContract('')
          }}
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
          value={contract}
          onChange={(e) => setContract(e.target.value)}
          className="rounded-md border border-input bg-background px-3 py-2 text-sm text-foreground focus:outline-none focus:ring-2 focus:ring-ring"
          aria-label="Filter by contract"
        >
          <option value="">All contracts</option>
          {contractOptions.map((item) => (
            <option key={item.id} value={item.id}>
              {item.reference} · {item.title}
            </option>
          ))}
        </select>

        {isManagerOrAdmin && (
          <select
            value={owner}
            onChange={(e) => setOwner(e.target.value)}
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
        )}

        {hasFilters && (
          <button
            type="button"
            onClick={() => {
              setSearch('')
              setStatus('')
              setCompany('')
              setContract('')
              setOwner('')
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
        rows={deployments}
        rowKey={(deployment) => `${deployment.company.id}-${deployment.reference}`}
        loading={loading}
        loadingLabel="Loading deployments…"
        empty={
          <EmptyState
            title="No deployments found"
            description={
              hasFilters
                ? 'Try adjusting your search filters. The feed only covers accounts you may see.'
                : 'Deployments appear once an account has a signed agreement and the Service handoff is accepted.'
            }
          />
        }
        pagination={
          lastPage > 1
            ? {
                currentPage: page,
                lastPage,
                total,
                onPageChange: (newPage) => void loadDeployments(newPage),
              }
            : undefined
        }
      />
    </div>
  )
}
