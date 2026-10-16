import { useCallback, useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import PageHeader from '../components/common/PageHeader'
import StatusBadge from '../components/common/StatusBadge'
import LeadAgingBadge from '../components/common/LeadAgingBadge'
import EmptyState from '../components/common/EmptyState'
import AlertItem from '../components/common/AlertItem'
import { listLeads } from '../lib/leads'
import { leadStatusMeta } from '../lib/leadMeta'
import type { Lead } from '../types'

// Worklist for submodule 04 — non-terminal, non-archived leads with no recent
// activity (spec 04 §3). Thresholds come from config/crm.php (14/30/60 days).
export default function Lifecycle() {
  const [leads, setLeads] = useState<Lead[]>([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')

  const load = useCallback(async () => {
    setLoading(true)
    setError('')
    try {
      const response = await listLeads({ stale: 'true' })
      setLeads(response.data)
    } catch {
      setError('Could not load the lifecycle queue.')
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
        title="Lead Lifecycle & Status"
        description="Leads going cold — no recent activity. Re-engage, nurture or archive them."
      />

      {error && (
        <div className="mb-4">
          <AlertItem variant="danger">{error}</AlertItem>
        </div>
      )}

      {!loading && leads.length === 0 ? (
        <EmptyState
          title="Nothing going cold"
          description="Every open lead has activity within the configured threshold."
        />
      ) : (
        <div className="overflow-hidden rounded-lg border border-border bg-card">
          <table className="w-full text-left text-sm">
            <thead className="border-b border-border text-xs uppercase text-muted-foreground">
              <tr>
                <th className="px-4 py-3 font-medium">Reference</th>
                <th className="px-4 py-3 font-medium">Company</th>
                <th className="px-4 py-3 font-medium">Status</th>
                <th className="px-4 py-3 font-medium">Aging</th>
                <th className="px-4 py-3 font-medium">Owner</th>
                <th className="px-4 py-3 font-medium" />
              </tr>
            </thead>
            <tbody>
              {loading && (
                <tr>
                  <td colSpan={6} className="px-4 py-6 text-center text-muted-foreground">
                    Loading…
                  </td>
                </tr>
              )}

              {!loading &&
                leads.map((lead) => (
                  <tr key={lead.id} className="border-b border-border last:border-0">
                    <td className="px-4 py-3 font-mono text-xs text-muted-foreground">
                      {lead.reference}
                    </td>
                    <td className="px-4 py-3">
                      <span className="font-medium text-foreground">{lead.company_name}</span>
                      <div className="text-xs text-muted-foreground">{lead.contact_name}</div>
                    </td>
                    <td className="px-4 py-3">
                      <StatusBadge tone={leadStatusMeta[lead.status].tone}>
                        {leadStatusMeta[lead.status].label}
                      </StatusBadge>
                    </td>
                    <td className="px-4 py-3">
                      <LeadAgingBadge aging={lead.aging} days={lead.days_since_activity} />
                    </td>
                    <td className="px-4 py-3 text-muted-foreground">
                      {lead.owner?.name ?? 'Unassigned'}
                    </td>
                    <td className="px-4 py-3 text-right">
                      <Link to={`/leads/${lead.id}`} className="text-sm font-medium text-primary">
                        Open
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
