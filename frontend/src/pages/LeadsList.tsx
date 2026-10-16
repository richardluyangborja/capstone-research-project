import { useCallback, useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { Plus, Search } from 'lucide-react'
import PageHeader from '../components/common/PageHeader'
import StatusBadge from '../components/common/StatusBadge'
import EmptyState from '../components/common/EmptyState'
import { listLeads } from '../lib/leads'
import { leadRatingMeta, leadSourceLabels, leadStatusMeta } from '../lib/leadMeta'
import { formatDate } from '../lib/format'
import type { Lead, LeadStatus } from '../types'

const statusOptions: { value: LeadStatus | ''; label: string }[] = [
  { value: '', label: 'All statuses' },
  { value: 'new', label: 'New' },
  { value: 'contacted', label: 'Contacted' },
  { value: 'qualified', label: 'Qualified' },
  { value: 'nurture', label: 'Nurture' },
  { value: 'disqualified', label: 'Disqualified' },
]

export default function LeadsList() {
  const [leads, setLeads] = useState<Lead[]>([])
  const [search, setSearch] = useState('')
  const [status, setStatus] = useState<string>('')
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')

  const load = useCallback(async (term: string, statusFilter: string) => {
    setLoading(true)
    setError('')
    try {
      const response = await listLeads({
        search: term || undefined,
        status: statusFilter || undefined,
      })
      setLeads(response.data)
    } catch {
      setError('Could not load leads. Please try again.')
    } finally {
      setLoading(false)
    }
  }, [])

  useEffect(() => {
    void load('', '')
  }, [load])

  return (
    <div>
      <PageHeader
        title="Leads"
        description="Capture, qualify and track prospective clients."
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

      <form
        className="mb-4 flex flex-wrap items-center gap-2"
        onSubmit={(event) => {
          event.preventDefault()
          void load(search, status)
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

        <button
          type="submit"
          className="rounded-md border border-border bg-card px-3 py-2 text-sm font-medium text-foreground hover:bg-muted"
        >
          Search
        </button>
      </form>

      {error && (
        <p className="mb-4 rounded-md border border-destructive/40 bg-destructive/10 px-3 py-2 text-sm text-destructive">
          {error}
        </p>
      )}

      {!loading && leads.length === 0 ? (
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
      ) : (
        <div className="overflow-hidden rounded-lg border border-border bg-card">
          <table className="w-full text-left text-sm">
            <thead className="border-b border-border text-xs uppercase text-muted-foreground">
              <tr>
                <th className="px-4 py-3 font-medium">Reference</th>
                <th className="px-4 py-3 font-medium">Company</th>
                <th className="px-4 py-3 font-medium">Source</th>
                <th className="px-4 py-3 font-medium">Status</th>
                <th className="px-4 py-3 font-medium">Rating</th>
                <th className="px-4 py-3 font-medium">Owner</th>
                <th className="px-4 py-3 font-medium">Created</th>
              </tr>
            </thead>
            <tbody>
              {loading && (
                <tr>
                  <td colSpan={7} className="px-4 py-6 text-center text-muted-foreground">
                    Loading…
                  </td>
                </tr>
              )}

              {!loading &&
                leads.map((lead) => (
                  <tr key={lead.id} className="border-b border-border last:border-0">
                    <td className="px-4 py-3">
                      <Link to={`/leads/${lead.id}`} className="font-mono text-xs text-primary">
                        {lead.reference}
                      </Link>
                    </td>
                    <td className="px-4 py-3">
                      <Link to={`/leads/${lead.id}`} className="font-medium text-foreground">
                        {lead.company_name}
                      </Link>
                      <div className="text-xs text-muted-foreground">
                        {lead.contact_name}
                        {lead.contact_email ? ` · ${lead.contact_email}` : ''}
                      </div>
                    </td>
                    <td className="px-4 py-3 text-muted-foreground">{leadSourceLabels[lead.source]}</td>
                    <td className="px-4 py-3">
                      <StatusBadge tone={leadStatusMeta[lead.status].tone}>
                        {leadStatusMeta[lead.status].label}
                      </StatusBadge>
                    </td>
                    <td className="px-4 py-3">
                      {lead.rating ? (
                        <StatusBadge tone={leadRatingMeta[lead.rating].tone}>
                          {leadRatingMeta[lead.rating].label}
                        </StatusBadge>
                      ) : (
                        <span className="text-muted-foreground">—</span>
                      )}
                    </td>
                    <td className="px-4 py-3 text-muted-foreground">
                      {lead.owner?.name ?? 'Unassigned'}
                    </td>
                    <td className="px-4 py-3 text-muted-foreground">{formatDate(lead.created_at)}</td>
                  </tr>
                ))}
            </tbody>
          </table>
        </div>
      )}
    </div>
  )
}
