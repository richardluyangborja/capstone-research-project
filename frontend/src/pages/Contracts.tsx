import { useCallback, useEffect, useState } from 'react'
import { Link, useSearchParams } from 'react-router-dom'
import { Download, Eye, FileCheck, Filter, RefreshCw, Search, ShieldAlert } from 'lucide-react'
import PageHeader from '../components/common/PageHeader'
import StatusBadge from '../components/common/StatusBadge'
import EmptyState from '../components/common/EmptyState'
import StatCard from '../components/common/StatCard'
import DataTable, { type DataTableColumn } from '../components/common/DataTable'
import { downloadContractFile, listContracts } from '../lib/contracts'
import { listCompanies } from '../lib/companies'
import { getAssignableUsers } from '../lib/leads'
import { contractStatusMeta, formatExpiry } from '../lib/contractMeta'
import { formatCurrency, formatDate } from '../lib/format'
import { useAuth } from '../lib/auth-context'
import type {
  AssignmentTarget,
  Company,
  Contract,
  ContractBookFilters,
  ContractSummary,
} from '../types'

export default function Contracts() {
  const { user } = useAuth()
  const isManagerOrAdmin =
    user?.role === 'manager' || user?.role === 'admin' || user?.role === 'superadmin'

  const [searchParams, setSearchParams] = useSearchParams()

  const [contracts, setContracts] = useState<Contract[]>([])
  const [summary, setSummary] = useState<ContractSummary | null>(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')

  // Filter state backed by search params
  const [search, setSearch] = useState(searchParams.get('search') ?? '')
  const [status, setStatus] = useState(searchParams.get('status') ?? '')
  const [renewal, setRenewal] = useState(searchParams.get('renewal') ?? '')
  const [expiringOnly, setExpiringOnly] = useState(searchParams.get('expiring') === 'true')
  const [owner, setOwner] = useState(searchParams.get('owner') ?? '')
  const [company, setCompany] = useState(searchParams.get('company') ?? '')
  const [companies, setCompanies] = useState<Company[]>([])
  const [owners, setOwners] = useState<AssignmentTarget[]>([])
  const [page, setPage] = useState(1)
  const [lastPage, setLastPage] = useState(1)
  const [total, setTotal] = useState(0)

  const buildFilters = useCallback(
    (targetPage: number): ContractBookFilters => {
      const filters: ContractBookFilters = {
        page: targetPage,
      }
      if (search.trim()) filters.search = search.trim()
      if (status) filters.status = status
      if (renewal === 'renewals') filters.renewal = true
      if (renewal === 'original') filters.renewal = false
      if (expiringOnly) filters.expiring = true
      if (owner) filters.owner = owner
      if (company) filters.company = company
      return filters
    },
    [search, status, renewal, expiringOnly, owner, company],
  )

  const loadContracts = useCallback(
    async (targetPage: number) => {
      setLoading(true)
      setError('')
      try {
        const filters = buildFilters(targetPage)
        const res = await listContracts(filters)
        setContracts(res.data)
        setSummary(res.summary)
        setTotal(res.meta.total)
        setLastPage(res.meta.last_page)
        setPage(res.meta.current_page)
      } catch (err) {
        console.error(err)
        setError('Could not load contracts. Please try again.')
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

    if (isManagerOrAdmin) {
      void getAssignableUsers()
        .then(setOwners)
        .catch(() => undefined)
    }
  }, [isManagerOrAdmin])

  useEffect(() => {
    void loadContracts(1)
  }, [loadContracts])

  // Keep the filters in the URL so a contract book view is shareable.
  useEffect(() => {
    const params = new URLSearchParams()
    if (search.trim()) params.set('search', search.trim())
    if (status) params.set('status', status)
    if (renewal) params.set('renewal', renewal)
    if (expiringOnly) params.set('expiring', 'true')
    if (owner) params.set('owner', owner)
    if (company) params.set('company', company)
    setSearchParams(params, { replace: true })
  }, [search, status, renewal, expiringOnly, owner, company, setSearchParams])

  const handleDownload = async (contract: Contract) => {
    try {
      await downloadContractFile(contract)
    } catch (err) {
      console.error(err)
      setError(`Could not download document for contract ${contract.reference}.`)
    }
  }

  const columns: DataTableColumn<Contract>[] = [
    {
      key: 'reference',
      header: 'Reference',
      render: (contract) => (
        <div className="flex flex-col">
          <Link
            to={`/contracts/${contract.id}`}
            className="font-mono text-sm font-semibold text-primary hover:underline"
          >
            {contract.reference}
          </Link>
          {contract.renewal_of && (
            <span className="text-xs text-muted-foreground">
              Renewal of {contract.renewal_of.reference}
            </span>
          )}
        </div>
      ),
    },
    {
      key: 'company',
      header: 'Account',
      render: (contract) =>
        contract.company ? (
          <Link
            to={`/accounts/${contract.company.id}?tab=commercial`}
            className="font-medium text-foreground hover:underline"
          >
            {contract.company.name}
          </Link>
        ) : (
          '—'
        ),
    },
    {
      key: 'title',
      header: 'Agreement Title',
      render: (contract) => (
        <Link
          to={`/contracts/${contract.id}`}
          className="font-medium text-foreground hover:text-primary"
        >
          {contract.title}
        </Link>
      ),
    },
    {
      key: 'status',
      header: 'Status',
      render: (contract) => (
        <StatusBadge tone={contractStatusMeta[contract.status]?.tone ?? 'neutral'}>
          {contractStatusMeta[contract.status]?.label ?? contract.status}
        </StatusBadge>
      ),
    },
    {
      key: 'term',
      header: 'Term / Expiry',
      render: (contract) => {
        const start = contract.start_date ? formatDate(contract.start_date) : null
        const end = contract.end_date ? formatDate(contract.end_date) : null
        const expiryText = formatExpiry(contract.days_until_expiry)

        return (
          <div className="flex flex-col text-xs">
            <span className="text-foreground">
              {start || end ? `${start ?? '—'} to ${end ?? '—'}` : '—'}
            </span>
            <div className="mt-0.5 flex items-center gap-1">
              {contract.is_expiring_soon && <ShieldAlert className="h-3.5 w-3.5 text-cost" />}
              <span
                className={
                  contract.is_expiring_soon ? 'font-medium text-cost' : 'text-muted-foreground'
                }
              >
                {expiryText}
              </span>
            </div>
          </div>
        )
      },
    },
    {
      key: 'value',
      header: 'Commercial Value',
      className: 'text-right',
      render: (contract) => (
        <div className="flex flex-col items-end">
          <span className="font-medium text-cost">{formatCurrency(contract.value)}</span>
          {contract.commercial?.client_monthly_value && (
            <span className="text-xs text-muted-foreground">
              {formatCurrency(contract.commercial.client_monthly_value)}/mo
            </span>
          )}
        </div>
      ),
    },
    {
      key: 'rate_cards',
      header: 'Rate Cards',
      className: 'text-center',
      render: (contract) => {
        const count = contract.rate_cards?.length ?? contract.rate_cards_count ?? 0
        return (
          <span className="inline-flex items-center rounded-full bg-secondary px-2.5 py-0.5 text-xs font-medium text-secondary-foreground">
            {count} {count === 1 ? 'role' : 'roles'}
          </span>
        )
      },
    },
    {
      key: 'owner',
      header: 'Owner',
      className: 'text-muted-foreground',
      render: (contract) => contract.owner?.name ?? '—',
    },
    {
      key: 'actions',
      header: '',
      className: 'text-right',
      render: (contract) => (
        <div className="flex items-center justify-end gap-2">
          {contract.file?.present && (
            <button
              type="button"
              onClick={() => handleDownload(contract)}
              title="Download signed document"
              className="inline-flex h-8 w-8 items-center justify-center rounded-md border border-input bg-background text-muted-foreground hover:bg-accent hover:text-foreground"
            >
              <Download className="h-4 w-4" />
            </button>
          )}
          <Link
            to={`/contracts/${contract.id}`}
            title="View contract details"
            className="inline-flex h-8 w-8 items-center justify-center rounded-md border border-input bg-background text-muted-foreground hover:bg-accent hover:text-foreground"
          >
            <Eye className="h-4 w-4" />
          </Link>
        </div>
      ),
    },
  ]

  return (
    <div className="space-y-6">
      <PageHeader
        title="Contracts"
        description="Cross-account commercial agreements, rate cards and renewal terms."
      />

      {error && (
        <div className="rounded-md border border-danger/30 bg-danger/10 p-4 text-sm text-danger">
          {error}
        </div>
      )}

      {/* Summary KPI Cards */}
      <div className="grid grid-cols-2 gap-4 lg:grid-cols-4">
        <StatCard
          label="Total Contracts"
          value={summary?.all ?? 0}
          domain="counts"
          icon={FileCheck}
        />
        <StatCard
          label="Active / Signed"
          value={summary?.active ?? 0}
          domain="people"
          icon={FileCheck}
        />
        <StatCard
          label="Expiring Soon"
          value={summary?.expiring ?? 0}
          domain={summary?.expiring ? 'cost' : 'counts'}
          icon={ShieldAlert}
        />
        <StatCard
          label="Renewals"
          value={summary?.renewals ?? 0}
          domain="activity"
          icon={RefreshCw}
        />
      </div>

      {/* Filter and Search Bar */}
      <div className="flex flex-wrap items-center gap-3 rounded-lg border border-border bg-card p-4">
        <div className="relative min-w-0 flex-1">
          <Search className="absolute left-3 top-2.5 h-4 w-4 text-muted-foreground" />
          <input
            type="text"
            placeholder="Search reference, title, account…"
            value={search}
            onChange={(e) => setSearch(e.target.value)}
            className="w-full rounded-md border border-input bg-background py-2 pl-9 pr-3 text-sm placeholder:text-muted-foreground focus:outline-none focus:ring-2 focus:ring-ring"
          />
        </div>

        <select
          value={status}
          onChange={(e) => setStatus(e.target.value)}
          className="rounded-md border border-input bg-background px-3 py-2 text-sm text-foreground focus:outline-none focus:ring-2 focus:ring-ring"
          aria-label="Filter by status"
        >
          <option value="">All statuses</option>
          <option value="signed">Signed</option>
          <option value="draft">Draft</option>
          <option value="sent">Sent for signature</option>
          <option value="expired">Expired</option>
          <option value="terminated">Terminated</option>
        </select>

        <select
          value={company}
          onChange={(e) => setCompany(e.target.value)}
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
          value={renewal}
          onChange={(e) => setRenewal(e.target.value)}
          className="rounded-md border border-input bg-background px-3 py-2 text-sm text-foreground focus:outline-none focus:ring-2 focus:ring-ring"
          aria-label="Filter by renewal"
        >
          <option value="">All agreements</option>
          <option value="renewals">Renewals only</option>
          <option value="original">Original contracts only</option>
        </select>

        {isManagerOrAdmin && (
          <select
            value={owner}
            onChange={(e) => setOwner(e.target.value)}
            className="rounded-md border border-input bg-background px-3 py-2 text-sm text-foreground focus:outline-none focus:ring-2 focus:ring-ring"
            aria-label="Filter by owner"
          >
            <option value="">All owners</option>
            <option value="me">My contracts</option>
            <option value="team">Team contracts</option>
            {owners.map((target) => (
              <option key={target.id} value={target.id}>
                {target.name}
              </option>
            ))}
          </select>
        )}

        <button
          type="button"
          onClick={() => setExpiringOnly(!expiringOnly)}
          className={`inline-flex items-center gap-1.5 rounded-md px-3 py-2 text-sm font-medium transition-colors ${
            expiringOnly
              ? 'border border-cost/40 bg-cost/10 text-foreground'
              : 'border border-input bg-background text-muted-foreground hover:bg-accent hover:text-foreground'
          }`}
        >
          <ShieldAlert className="h-4 w-4 text-cost" />
          Expiring Soon
        </button>

        {(search || status || renewal || expiringOnly || owner || company) && (
          <button
            type="button"
            onClick={() => {
              setSearch('')
              setStatus('')
              setRenewal('')
              setExpiringOnly(false)
              setOwner('')
              setCompany('')
            }}
            className="inline-flex items-center gap-1 text-sm text-muted-foreground hover:text-foreground"
          >
            <Filter className="h-3.5 w-3.5" />
            Clear filters
          </button>
        )}
      </div>

      {/* Contracts Table */}
      <DataTable
        columns={columns}
        rows={contracts}
        rowKey={(c) => c.id}
        loading={loading}
        loadingLabel="Loading contracts…"
        empty={
          <EmptyState
            title="No contracts found"
            description={
              search || status || renewal || expiringOnly || owner || company
                ? 'Try adjusting your search filters to find what you are looking for.'
                : 'No contracts have been uploaded to accounts yet.'
            }
          />
        }
        pagination={
          lastPage > 1
            ? {
                currentPage: page,
                lastPage,
                total,
                onPageChange: (newPage) => loadContracts(newPage),
              }
            : undefined
        }
      />
    </div>
  )
}
