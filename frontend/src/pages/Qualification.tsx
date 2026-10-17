import { useCallback, useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import PageHeader from '../components/common/PageHeader'
import StatusBadge from '../components/common/StatusBadge'
import EmptyState from '../components/common/EmptyState'
import AlertItem from '../components/common/AlertItem'
import DataTable, { type DataTableColumn } from '../components/common/DataTable'
import { listLeads } from '../lib/leads'
import { leadRatingMeta, leadStatusMeta } from '../lib/leadMeta'
import type { Lead } from '../types'

// Worklist for submodule 02 — leads still awaiting qualification (status new/contacted).
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
    key: 'headcount',
    header: 'Headcount',
    className: 'text-muted-foreground',
    render: (lead) => lead.estimated_headcount ?? '—',
  },
  {
    key: 'score',
    header: 'Score',
    className: 'text-muted-foreground',
    render: (lead) => `${lead.score ?? 0}/100`,
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
    key: 'actions',
    header: null,
    headerClassName: 'text-right',
    className: 'text-right',
    render: (lead) => (
      <Link to={`/leads/${lead.id}`} className="text-sm font-medium text-primary">
        Qualify
      </Link>
    ),
  },
]

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
            title="Queue is clear"
            description="No leads are currently awaiting qualification."
          />
        }
      />
    </div>
  )
}
