import { useCallback, useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import PageHeader from '../components/common/PageHeader'
import StatusBadge from '../components/common/StatusBadge'
import EmptyState from '../components/common/EmptyState'
import { listLeads } from '../lib/leads'
import { leadRatingMeta, leadStatusMeta } from '../lib/leadMeta'
import type { Lead } from '../types'

// Worklist for submodule 02 — leads still awaiting qualification (status new/contacted).
export default function Qualification() {
  const [leads, setLeads] = useState<Lead[]>([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')

  const load = useCallback(async () => {
    setLoading(true)
    setError('')
    try {
      const response = await listLeads({ status: 'new,contacted' })
      setLeads(response.data)
    } catch {
      setError('Could not load the qualification queue.')
    } finally {
      setLoading(false)
    }
  }, [])

  useEffect(() => {
    void load()
  }, [load])

  return (
    <div>
      <PageHeader
        title="Lead Qualification & Scoring"
        description="Leads awaiting qualification — open one to work its checklist and rating."
      />

      {error && (
        <p className="mb-4 rounded-md border border-destructive/40 bg-destructive/10 px-3 py-2 text-sm text-destructive">
          {error}
        </p>
      )}

      {!loading && leads.length === 0 ? (
        <EmptyState title="Queue is clear" description="No leads are currently awaiting qualification." />
      ) : (
        <div className="overflow-hidden rounded-lg border border-border bg-card">
          <table className="w-full text-left text-sm">
            <thead className="border-b border-border text-xs uppercase text-muted-foreground">
              <tr>
                <th className="px-4 py-3 font-medium">Reference</th>
                <th className="px-4 py-3 font-medium">Company</th>
                <th className="px-4 py-3 font-medium">Headcount</th>
                <th className="px-4 py-3 font-medium">Score</th>
                <th className="px-4 py-3 font-medium">Rating</th>
                <th className="px-4 py-3 font-medium">Status</th>
                <th className="px-4 py-3 font-medium" />
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
                    <td className="px-4 py-3 font-mono text-xs text-muted-foreground">{lead.reference}</td>
                    <td className="px-4 py-3 font-medium text-foreground">{lead.company_name}</td>
                    <td className="px-4 py-3 text-muted-foreground">
                      {lead.estimated_headcount ?? '—'}
                    </td>
                    <td className="px-4 py-3 text-muted-foreground">{lead.score ?? 0}/100</td>
                    <td className="px-4 py-3">
                      {lead.rating ? (
                        <StatusBadge tone={leadRatingMeta[lead.rating].tone}>
                          {leadRatingMeta[lead.rating].label}
                        </StatusBadge>
                      ) : (
                        <span className="text-muted-foreground">—</span>
                      )}
                    </td>
                    <td className="px-4 py-3">
                      <StatusBadge tone={leadStatusMeta[lead.status].tone}>
                        {leadStatusMeta[lead.status].label}
                      </StatusBadge>
                    </td>
                    <td className="px-4 py-3 text-right">
                      <Link to={`/leads/${lead.id}`} className="text-sm font-medium text-primary">
                        Qualify
                      </Link>
                    </td>
                  </tr>
                ))}
            </tbody>
          </table>
        </div>
      )}
    </div>
  )
}
