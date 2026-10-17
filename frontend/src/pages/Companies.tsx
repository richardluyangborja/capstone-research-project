import { useCallback, useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { Plus, Search } from 'lucide-react'
import PageHeader from '../components/common/PageHeader'
import StatusBadge from '../components/common/StatusBadge'
import EmptyState from '../components/common/EmptyState'
import AlertItem from '../components/common/AlertItem'
import DataTable, { type DataTableColumn } from '../components/common/DataTable'
import { listCompanies } from '../lib/companies'
import { companyStatusMeta, companyTierLabels } from '../lib/companyMeta'
import { formatDate } from '../lib/format'
import type { Company, CompanyStatus, CompanyTier } from '../types'

const columns: DataTableColumn<Company>[] = [
  {
    key: 'name',
    header: 'Company',
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
    key: 'industry',
    header: 'Industry',
    className: 'text-muted-foreground',
    render: (company) => company.industry ?? '—',
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
    className: 'text-muted-foreground',
    render: (company) => (company.tier ? companyTierLabels[company.tier] : '—'),
  },
  {
    key: 'owner',
    header: 'Owner',
    className: 'text-muted-foreground',
    render: (company) => company.owner?.name ?? '—',
  },
  {
    key: 'sites',
    header: 'Sites',
    className: 'text-counts',
    render: (company) => company.counts.sites,
  },
  {
    key: 'branches',
    header: 'Branches',
    className: 'text-counts',
    render: (company) => company.counts.branches,
  },
  {
    key: 'created',
    header: 'Created',
    className: 'text-muted-foreground',
    render: (company) => formatDate(company.created_at),
  },
]

// Submodule 06 — the account book: every company (prospect or client) the caller may see.
export default function Companies() {
  const [companies, setCompanies] = useState<Company[]>([])
  const [search, setSearch] = useState('')
  const [status, setStatus] = useState('')
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
        setError('Could not load companies. Please try again.')
      } finally {
        setLoading(false)
      }
    },
    [],
  )

  useEffect(() => {
    void load(1, { search: '', status: '', tier: '' })
  }, [load])

  const filters = { search, status, tier }

  return (
    <div>
      <PageHeader
        title="Companies"
        description="Accounts and prospects, with their sites, branches and activity."
        actions={
          <Link
            to="/companies/new"
            className="inline-flex items-center gap-2 rounded-md bg-primary px-3 py-2 text-sm font-medium text-primary-foreground"
          >
            <Plus className="h-4 w-4" aria-hidden="true" />
            New company
          </Link>
        }
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
          <option value="">All statuses</option>
          {(Object.keys(companyStatusMeta) as CompanyStatus[]).map((value) => (
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
            title="No companies yet"
            description="Add an existing client, or convert a qualified lead."
            action={
              <Link
                to="/companies/new"
                className="inline-flex items-center gap-2 rounded-md bg-primary px-3 py-2 text-sm font-medium text-primary-foreground"
              >
                <Plus className="h-4 w-4" aria-hidden="true" />
                New company
              </Link>
            }
          />
        }
      />
    </div>
  )
}
