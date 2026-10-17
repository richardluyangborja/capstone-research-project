import { useCallback, useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { Search } from 'lucide-react'
import PageHeader from '../components/common/PageHeader'
import StatusBadge from '../components/common/StatusBadge'
import EmptyState from '../components/common/EmptyState'
import AlertItem from '../components/common/AlertItem'
import DataTable, { type DataTableColumn } from '../components/common/DataTable'
import { listContacts } from '../lib/contacts'
import { listCompanies } from '../lib/companies'
import { contactRoleMeta, contactStatusMeta } from '../lib/contactMeta'
import type { Company, Contact, ContactRoleType, ContactStatus } from '../types'

const columns: DataTableColumn<Contact>[] = [
  {
    key: 'name',
    header: 'Contact',
    render: (contact) => (
      <div className="flex items-center gap-2">
        <span className="font-medium text-foreground">{contact.name}</span>
        {contact.is_primary && <StatusBadge tone="people">Primary</StatusBadge>}
      </div>
    ),
  },
  {
    key: 'company',
    header: 'Company',
    render: (contact) =>
      contact.company ? (
        <Link to={`/companies/${contact.company.id}`} className="text-primary">
          {contact.company.name}
        </Link>
      ) : (
        '—'
      ),
  },
  {
    key: 'position',
    header: 'Position',
    className: 'text-muted-foreground',
    render: (contact) => contact.position ?? '—',
  },
  {
    key: 'role',
    header: 'Role',
    render: (contact) => (
      <StatusBadge tone={contactRoleMeta[contact.role_type].tone}>
        {contactRoleMeta[contact.role_type].label}
      </StatusBadge>
    ),
  },
  {
    key: 'email',
    header: 'Email',
    className: 'text-muted-foreground',
    render: (contact) => contact.email ?? '—',
  },
  {
    key: 'phone',
    header: 'Phone',
    className: 'text-muted-foreground',
    render: (contact) => contact.mobile ?? contact.phone ?? '—',
  },
  {
    key: 'status',
    header: 'Status',
    render: (contact) => (
      <StatusBadge tone={contactStatusMeta[contact.status].tone}>
        {contactStatusMeta[contact.status].label}
      </StatusBadge>
    ),
  },
]

interface Filters {
  search: string
  role: string
  status: string
  companyId: string
}

// Submodule 07 — the contact book across every account the caller may see. Editing a
// contact happens on its company (spec 07 §UI), so rows link there.
export default function Contacts() {
  const [contacts, setContacts] = useState<Contact[]>([])
  const [companies, setCompanies] = useState<Company[]>([])
  const [search, setSearch] = useState('')
  const [role, setRole] = useState('')
  const [status, setStatus] = useState('')
  const [companyId, setCompanyId] = useState('')
  const [page, setPage] = useState(1)
  const [lastPage, setLastPage] = useState(1)
  const [total, setTotal] = useState(0)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')

  const load = useCallback(async (targetPage: number, filters: Filters) => {
    setLoading(true)
    setError('')
    try {
      const response = await listContacts({
        search: filters.search || undefined,
        role_type: filters.role || undefined,
        status: filters.status || undefined,
        company_id: filters.companyId || undefined,
        page: targetPage,
      })
      setContacts(response.data)
      setPage(response.meta.current_page)
      setLastPage(response.meta.last_page)
      setTotal(response.meta.total)
    } catch {
      setError('Could not load contacts. Please try again.')
    } finally {
      setLoading(false)
    }
  }, [])

  useEffect(() => {
    void load(1, { search: '', role: '', status: '', companyId: '' })
    // The company picker only needs the caller's first page of accounts.
    void listCompanies().then((response) => setCompanies(response.data))
  }, [load])

  const filters: Filters = { search, role, status, companyId }

  return (
    <div>
      <PageHeader
        title="Contacts"
        description="The people at every account you can see — decision-makers first."
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
            placeholder="Search name, email or number"
            className="w-full rounded-md border border-input bg-card py-2 pl-9 pr-3 text-sm text-foreground outline-none focus:ring-2 focus:ring-ring"
          />
        </div>

        <select
          value={role}
          onChange={(event) => setRole(event.target.value)}
          className="rounded-md border border-input bg-card px-3 py-2 text-sm text-foreground outline-none focus:ring-2 focus:ring-ring"
        >
          <option value="">All roles</option>
          {(Object.keys(contactRoleMeta) as ContactRoleType[]).map((value) => (
            <option key={value} value={value}>
              {contactRoleMeta[value].label}
            </option>
          ))}
        </select>

        <select
          value={status}
          onChange={(event) => setStatus(event.target.value)}
          className="rounded-md border border-input bg-card px-3 py-2 text-sm text-foreground outline-none focus:ring-2 focus:ring-ring"
        >
          <option value="">All statuses</option>
          {(Object.keys(contactStatusMeta) as ContactStatus[]).map((value) => (
            <option key={value} value={value}>
              {contactStatusMeta[value].label}
            </option>
          ))}
        </select>

        <select
          value={companyId}
          onChange={(event) => setCompanyId(event.target.value)}
          className="max-w-xs rounded-md border border-input bg-card px-3 py-2 text-sm text-foreground outline-none focus:ring-2 focus:ring-ring"
        >
          <option value="">All companies</option>
          {companies.map((company) => (
            <option key={company.id} value={company.id}>
              {company.name}
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
        rows={contacts}
        rowKey={(contact) => contact.id}
        loading={loading}
        pagination={{
          currentPage: page,
          lastPage,
          total,
          onPageChange: (next) => void load(next, filters),
        }}
        empty={
          <EmptyState
            title="No contacts yet"
            description="Open a company and add the people we work with there."
          />
        }
      />
    </div>
  )
}
