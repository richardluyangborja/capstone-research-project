import { useCallback, useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import PageHeader from '../components/common/PageHeader'
import StatusBadge from '../components/common/StatusBadge'
import LeadAgingBadge from '../components/common/LeadAgingBadge'
import EmptyState from '../components/common/EmptyState'
import AlertItem from '../components/common/AlertItem'
import DataTable, { type DataTableColumn } from '../components/common/DataTable'
import { listLeads } from '../lib/leads'
import { leadStatusMeta } from '../lib/leadMeta'
import type { Lead } from '../types'

const columns: DataTableColumn<Lead>[] = [
  {
    key: 'reference',
    header: 'Reference',
    className: 'font-mono text-xs text-muted-foreground',
    render: (lead) => lead.reference,
  },
  {
    key: 'company',
    header: 'Company',
    render: (lead) => (
      <>
        <span className="font-medium text-foreground">{lead.company_name}</span>
        <div className="text-xs text-muted-foreground">{lead.contact_name}</div>
      </>
    ),
  },
  {
    key: 'status',
    header: 'Status',
    render: (lead) => (
      <StatusBadge tone={leadStatusMeta[lead.status].tone}>
        {leadStatusMeta[lead.status].label}
      </StatusBadge>
    ),
  },
  {
    key: 'aging',
    header: 'Aging',
    render: (lead) => <LeadAgingBadge aging={lead.aging} days={lead.days_since_activity} />,
  },
  {
    key: 'owner',
    header: 'Owner',
    className: 'text-muted-foreground',
    render: (lead) => lead.owner?.name ?? 'Unassigned',
  },
  {
    key: 'actions',
    header: null,
    headerClassName: 'text-right',
    className: 'text-right',
    render: (lead) => (
      <Link to={`/leads/${lead.id}`} className="text-sm font-medium text-primary">
        Open
      </Link>
    ),
  },
]

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

      <DataTable
        columns={columns}
        rows={leads}
        rowKey={(lead) => lead.id}
        loading={loading}
        empty={
          <EmptyState
            title="Nothing going cold"
            description="Every open lead has activity within the configured threshold."
          />
        }
      />
    </div>
  )
}
