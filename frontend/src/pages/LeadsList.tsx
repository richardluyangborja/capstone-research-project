import { useCallback, useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { Plus, Search } from 'lucide-react'
import PageHeader from '../components/common/PageHeader'
import { listLeads } from '../lib/leads'
import { formatDate } from '../lib/format'
import type { Lead, LeadSource, LeadStatus } from '../types'

const sourceLabels: Record<LeadSource, string> = {
  walk_in: 'Walk-in',
  email: 'Email',
  phone: 'Phone',
  referral: 'Referral',
  website: 'Website',
  outbound: 'Outbound',
  event: 'Event',
  partner: 'Partner',
  other: 'Other',
}

const statusLabels: Record<LeadStatus, string> = {
  new: 'New',
  contacted: 'Contacted',
  qualified: 'Qualified',
  converted: 'Converted',
  disqualified: 'Disqualified',
  nurture: 'Nurture',
  archived: 'Archived',
}

export default function LeadsList() {
  const [leads, setLeads] = useState<Lead[]>([])
  const [search, setSearch] = useState('')
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')

  const load = useCallback(async (term: string) => {
    setLoading(true)
    setError('')
    try {
      const response = await listLeads({ search: term || undefined })
      setLeads(response.data)
    } catch {
      setError('Could not load leads. Please try again.')
    } finally {
      setLoading(false)
    }
  }, [])

  useEffect(() => {
    void load('')
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
        className="mb-4 flex items-center gap-2"
        onSubmit={(event) => {
          event.preventDefault()
          void load(search)
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

      <div className="overflow-hidden rounded-lg border border-border bg-card">
        <table className="w-full text-left text-sm">
          <thead className="border-b border-border text-xs uppercase text-muted-foreground">
            <tr>
              <th className="px-4 py-3 font-medium">Reference</th>
              <th className="px-4 py-3 font-medium">Company</th>
              <th className="px-4 py-3 font-medium">Contact</th>
              <th className="px-4 py-3 font-medium">Source</th>
              <th className="px-4 py-3 font-medium">Status</th>
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

            {!loading && leads.length === 0 && (
              <tr>
                <td colSpan={7} className="px-4 py-6 text-center text-muted-foreground">
                  No leads yet. Capture the first one.
                </td>
              </tr>
            )}

            {!loading &&
              leads.map((lead) => (
                <tr key={lead.id} className="border-b border-border last:border-0">
                  <td className="px-4 py-3 font-mono text-xs text-muted-foreground">{lead.reference}</td>
                  <td className="px-4 py-3 font-medium text-foreground">{lead.company_name}</td>
                  <td className="px-4 py-3 text-foreground">
                    <div>{lead.contact_name}</div>
                    <div className="text-xs text-muted-foreground">
                      {lead.contact_email ?? lead.contact_phone}
                    </div>
                  </td>
                  <td className="px-4 py-3 text-muted-foreground">{sourceLabels[lead.source]}</td>
                  <td className="px-4 py-3">
                    <StatusBadge status={lead.status} />
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
    </div>
  )
}

function StatusBadge({ status }: { status: LeadStatus }) {
  const tone: Record<LeadStatus, string> = {
    new: 'bg-counts/15 text-counts',
    contacted: 'bg-activity/15 text-activity',
    qualified: 'bg-people/15 text-people',
    converted: 'bg-people/15 text-people',
    disqualified: 'bg-destructive/15 text-destructive',
    nurture: 'bg-muted text-muted-foreground',
    archived: 'bg-muted text-muted-foreground',
  }

  return (
    <span className={`rounded-full px-2 py-0.5 text-xs font-medium ${tone[status]}`}>
      {statusLabels[status]}
    </span>
  )
}
