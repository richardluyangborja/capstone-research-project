import { useCallback, useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import PageHeader from '../components/common/PageHeader'
import StatusBadge from '../components/common/StatusBadge'
import EmptyState from '../components/common/EmptyState'
import AlertItem from '../components/common/AlertItem'
import DataTable, { type DataTableColumn } from '../components/common/DataTable'
import { listLeads } from '../lib/leads'
import { leadRatingMeta, leadStatusMeta } from '../lib/leadMeta'
import { formatDate } from '../lib/format'
import type { Lead } from '../types'

// Worklist for submodule 08 — qualified leads that have not been converted yet.
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
    className: 'font-medium text-foreground',
    render: (lead) => lead.company_name,
  },
  {
    key: 'contact',
    header: 'Contact',
    className: 'text-muted-foreground',
    render: (lead) => lead.contact_name,
  },
  {
    key: 'headcount',
    header: 'Headcount',
    className: 'text-muted-foreground',
    render: (lead) => lead.estimated_headcount ?? '—',
  },
  {
    key: 'rating',
    header: 'Rating',
    render: (lead) =>
      lead.rating ? (
        <StatusBadge tone={leadRatingMeta[lead.rating].tone}>
          {leadRatingMeta[lead.rating].label}
        </StatusBadge>
      ) : (
        <span className="text-muted-foreground">—</span>
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
    key: 'owner',
    header: 'Owner',
    className: 'text-muted-foreground',
    render: (lead) => lead.owner?.name ?? 'Unassigned',
  },
  {
    key: 'close',
    header: 'Target start',
    className: 'text-muted-foreground',
    render: (lead) => (lead.target_start_date ? formatDate(lead.target_start_date) : '—'),
  },
  {
    key: 'actions',
    header: null,
    headerClassName: 'text-right',
    className: 'text-right',
    render: (lead) =>
      lead.can_convert ? (
        <Link to={`/leads/${lead.id}/convert`} className="text-sm font-medium text-primary">
          Convert
        </Link>
      ) : (
        <span className="text-xs text-muted-foreground">Not yours</span>
      ),
  },
]

export default function Conversion() {
  const [leads, setLeads] = useState<Lead[]>([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')

  const load = useCallback(async () => {
    setLoading(true)
    setError('')
    try {
      const response = await listLeads({ status: 'qualified' })
      setLeads(response.data)
    } catch {
      setError('Could not load the conversion queue.')
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
        title="Lead-to-Client Conversion"
        description="Qualified leads ready to become an account, its people and a first deal."
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
            title="Nothing to convert"
            description="Leads appear here once they are qualified."
          />
        }
      />
    </div>
  )
}
