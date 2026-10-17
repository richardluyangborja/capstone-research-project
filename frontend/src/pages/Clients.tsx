import { useCallback, useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { Search } from 'lucide-react'
import PageHeader from '../components/common/PageHeader'
import StatusBadge from '../components/common/StatusBadge'
import EmptyState from '../components/common/EmptyState'
import AlertItem from '../components/common/AlertItem'
import DataTable, { type DataTableColumn } from '../components/common/DataTable'
import { listCompanies } from '../lib/companies'
import { companyStatusMeta, companyTierLabels } from '../lib/companyMeta'
import { formatDate } from '../lib/format'
import type { Company, CompanyStatus, CompanyTier } from '../types'

/** Every client state — a prospect is not a client yet (spec 00). */
const clientStatuses: CompanyStatus[] = ['onboarding', 'active', 'at_risk', 'dormant', 'churned']
const allClients = clientStatuses.join(',')

const columns: DataTableColumn<Company>[] = [
  {
    key: 'name',
    header: 'Account',
    render: (company) => (
      <>
        <Link to={`/companies/${company.id}`} className="font-medium text-foreground">
          {company.name}
        </Link>
        <div className="font-mono text-xs text-muted-foreground">{company.reference}</div>
      </>
    ),
  },
  {
    key: 'status',
    header: 'Lifecycle',
    render: (company) => (
      <StatusBadge tone={companyStatusMeta[company.status].tone}>
        {companyStatusMeta[company.status].label}
      </StatusBadge>
    ),
  },
  {
    key: 'client_since',
    header: 'Client since',
    className: 'text-muted-foreground',
    render: (company) => (company.client_since ? formatDate(company.client_since) : '—'),
  },
  {
    key: 'contracts',
    header: 'Contracts',
    className: 'text-counts',
    render: (company) => company.counts.contracts,
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
    key: 'churn',
    header: 'Churn reason',
    className: 'text-muted-foreground',
    render: (company) => company.churn_reason ?? '—',
  },
  {
    key: 'actions',
    header: null,
    headerClassName: 'text-right',
    className: 'text-right',
    render: (company) => (
      <Link to={`/companies/${company.id}`} className="text-sm font-medium text-primary">
        Open
      </Link>
    ),
  },
]

/**
 * Submodule 09 — the client book (spec 09 §UI): accounts past `prospect`, filtered by
 * lifecycle state and tier. Renewal reminders and automatic aging belong to the
 * follow-up module, so the lifecycle moves themselves live on the account.
 */
export default function Clients() {
  const [companies, setCompanies] = useState<Company[]>([])
  const [search, setSearch] = useState('')
  const [status, setStatus] = useState(allClients)
  const [tier, setTier] = useState('')
  const [page, setPage] = useState(1)
  const [lastPage, setLastPage] = useState(1)
  const [total, setTotal] = useState(0)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')

  const load = useCallback(
    async (targetPage: number, filters: { search: string; status: string; tier: string }) => {
      setLoading(true)
      setError('')
      try {
        const response = await listCompanies({
          search: filters.search || undefined,
          status: filters.status || undefined,
          tier: filters.tier || undefined,
          page: targetPage,
        })
        setCompanies(response.data)
        setPage(response.meta.current_page)
        setLastPage(response.meta.last_page)
        setTotal(response.meta.total)
      } catch {
        setError('Could not load the client accounts. Please try again.')
      } finally {
        setLoading(false)
      }
    },
    [],
  )

  useEffect(() => {
    void load(1, { search: '', status: allClients, tier: '' })
  }, [load])

  const filters = { search, status, tier }

  return (
    <div>
      <PageHeader
        title="Client Accounts"
        description="Companies that became clients, with their lifecycle state and contracts."
      />

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
          className="rounded-md border border-input bg-card px-3 py-2 text-sm text-foreground outline-none focus:ring-2 focus:ring-ring"
        >
          <option value={allClients}>All clients</option>
          {clientStatuses.map((value) => (
            <option key={value} value={value}>
              {companyStatusMeta[value].label}
            </option>
          ))}
        </select>

        <select
          value={tier}
          onChange={(event) => setTier(event.target.value)}
          className="rounded-md border border-input bg-card px-3 py-2 text-sm text-foreground outline-none focus:ring-2 focus:ring-ring"
        >
          <option value="">All tiers</option>
          {(Object.keys(companyTierLabels) as CompanyTier[]).map((value) => (
            <option key={value} value={value}>
              {companyTierLabels[value]}
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
            title="No clients yet"
            description="A company appears here once it moves past prospect — usually when its first contract is signed."
          />
        }
      />
    </div>
  )
}
