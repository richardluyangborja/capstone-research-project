import { useCallback, useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { ArrowRightLeft } from 'lucide-react'
import PageHeader from '../components/common/PageHeader'
import StatCard from '../components/common/StatCard'
import EmptyState from '../components/common/EmptyState'
import AlertItem from '../components/common/AlertItem'
import LeadOwnerControl from '../components/common/LeadOwnerControl'
import DataTable, { type DataTableColumn } from '../components/common/DataTable'
import {
  assignLead,
  bulkTransferLeads,
  claimLead,
  getAssignableUsers,
  getLeadSummary,
  listLeads,
} from '../lib/leads'
import { leadSourceLabels } from '../lib/leadMeta'
import { formatDate } from '../lib/format'
import { useAuth } from '../lib/auth-context'
import type { AssignmentTarget, Lead, LeadSummary } from '../types'

const emptySummary: LeadSummary = { all: 0, mine: 0, unassigned: 0, team: 0, archived: 0 }

// Submodule 03 workspace: the shared unassigned queue, team workload, and bulk transfer.
export default function Assignment() {
  const { user } = useAuth()
  const canTransfer = user?.role !== 'salesrep'

  const [queue, setQueue] = useState<Lead[]>([])
  const [targets, setTargets] = useState<AssignmentTarget[]>([])
  const [summary, setSummary] = useState<LeadSummary>(emptySummary)
  const [fromUser, setFromUser] = useState('')
  const [toUser, setToUser] = useState('')
  const [busy, setBusy] = useState(false)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')
  const [message, setMessage] = useState('')

  const load = useCallback(async () => {
    setLoading(true)
    setError('')
    try {
      const [queueResponse, assignable, counts] = await Promise.all([
        listLeads({ owner: 'unassigned' }),
        getAssignableUsers(),
        getLeadSummary(),
      ])
      setQueue(queueResponse.data)
      setTargets(assignable)
      setSummary(counts)
    } catch {
      setError('Could not load the assignment workspace.')
    } finally {
      setLoading(false)
    }
  }, [])

  useEffect(() => {
    void load()
  }, [load])

  const afterChange = async (note: string) => {
    setMessage(note)
    await load()
  }

  const onClaim = async (lead: Lead) => {
    setBusy(true)
    try {
      await claimLead(lead.id)
      await afterChange(`Claimed ${lead.reference}.`)
    } catch {
      setError(`Could not claim ${lead.reference}.`)
    } finally {
      setBusy(false)
    }
  }

  const onAssign = async (lead: Lead, ownerId: number) => {
    setBusy(true)
    try {
      await assignLead(lead.id, ownerId)
      await afterChange(`Assigned ${lead.reference}.`)
    } catch {
      setError(`Could not assign ${lead.reference}.`)
    } finally {
      setBusy(false)
    }
  }

  const onTransfer = async () => {
    if (!fromUser || !toUser) return
    setBusy(true)
    setError('')
    try {
      const { transferred } = await bulkTransferLeads(Number(fromUser), Number(toUser))
      setFromUser('')
      setToUser('')
      await afterChange(`Transferred ${transferred} lead(s).`)
    } catch {
      setError('Could not transfer leads. Check both users are in your team.')
    } finally {
      setBusy(false)
    }
  }

  const workload = [...targets].sort(
    (a, b) => (b.open_leads_count ?? 0) - (a.open_leads_count ?? 0),
  )

  const queueColumns: DataTableColumn<Lead>[] = [
    {
      key: 'reference',
      header: 'Reference',
      render: (lead) => (
        <Link to={`/leads/${lead.id}`} className="font-mono text-xs text-primary">
          {lead.reference}
        </Link>
      ),
    },
    {
      key: 'company',
      header: 'Company',
      render: (lead) => (
        <>
          <Link to={`/leads/${lead.id}`} className="font-medium text-foreground">
            {lead.company_name}
          </Link>
          <div className="text-xs text-muted-foreground">{lead.contact_name}</div>
        </>
      ),
    },
    {
      key: 'source',
      header: 'Source',
      className: 'text-muted-foreground',
      render: (lead) => leadSourceLabels[lead.source],
    },
    {
      key: 'captured',
      header: 'Captured',
      className: 'text-muted-foreground',
      render: (lead) => formatDate(lead.created_at),
    },
    {
      key: 'owner',
      header: 'Owner',
      render: (lead) => (
        <LeadOwnerControl
          lead={lead}
          targets={targets}
          busy={busy}
          onClaim={() => void onClaim(lead)}
          onAssign={(ownerId) => void onAssign(lead, ownerId)}
        />
      ),
    },
  ]

  return (
    <div>
      <PageHeader
        title="Lead Assignment & Ownership"
        description="Give every active lead a responsible salesrep from the shared queue."
      />

      <div className="mb-6 grid grid-cols-2 gap-4 lg:grid-cols-4">
        <StatCard label="All leads" value={summary.all} domain="counts" />
        <StatCard label="Unassigned" value={summary.unassigned} domain="counts" />
        <StatCard label="My leads" value={summary.mine} domain="counts" />
        <StatCard label="Team leads" value={summary.team} domain="counts" />
      </div>

      {message && (
        <div className="mb-4">
          <AlertItem variant="success">{message}</AlertItem>
        </div>
      )}

      {error && (
        <div className="mb-4">
          <AlertItem variant="danger">{error}</AlertItem>
        </div>
      )}

      <div className="grid grid-cols-1 gap-6 lg:grid-cols-3">
        <div className="lg:col-span-2">
          <h2 className="mb-2 text-sm font-semibold text-foreground">Unassigned queue</h2>

          <DataTable
            columns={queueColumns}
            rows={queue}
            rowKey={(lead) => lead.id}
            loading={loading}
            empty={
              <EmptyState
                title="Queue is clear"
                description="Every lead has an owner. New captures land here when unowned."
              />
            }
          />
        </div>

        <div className="flex flex-col gap-6">
          <div className="rounded-lg border border-border bg-card p-4">
            <h2 className="text-sm font-semibold text-foreground">Team workload</h2>
            <p className="mt-1 text-xs text-muted-foreground">
              Open leads per assignable salesrep.
            </p>

            <ul className="mt-3 flex flex-col gap-2">
              {workload.length === 0 && (
                <li className="text-sm text-muted-foreground">No assignable salesreps.</li>
              )}
              {workload.map((target) => (
                <li key={target.id} className="flex items-center justify-between text-sm">
                  <span className="text-foreground">{target.name}</span>
                  <span className="rounded bg-secondary px-2 py-0.5 text-xs font-medium text-secondary-foreground">
                    {target.open_leads_count ?? 0} open
                  </span>
                </li>
              ))}
            </ul>
          </div>

          {canTransfer && (
            <div className="rounded-lg border border-border bg-card p-4">
              <h2 className="text-sm font-semibold text-foreground">Bulk transfer</h2>
              <p className="mt-1 text-xs text-muted-foreground">
                Reassign every lead from one salesrep to another (e.g. on leave).
              </p>

              <div className="mt-3 flex flex-col gap-3">
                <label className="text-xs font-medium text-muted-foreground">
                  From
                  <select
                    value={fromUser}
                    onChange={(event) => setFromUser(event.target.value)}
                    className="mt-1 w-full rounded-md border border-input bg-card px-2 py-2 text-sm text-foreground outline-none focus:ring-2 focus:ring-ring"
                  >
                    <option value="">Select salesrep…</option>
                    {targets.map((target) => (
                      <option key={target.id} value={target.id}>
                        {target.name}
                      </option>
                    ))}
                  </select>
                </label>

                <label className="text-xs font-medium text-muted-foreground">
                  To
                  <select
                    value={toUser}
                    onChange={(event) => setToUser(event.target.value)}
                    className="mt-1 w-full rounded-md border border-input bg-card px-2 py-2 text-sm text-foreground outline-none focus:ring-2 focus:ring-ring"
                  >
                    <option value="">Select salesrep…</option>
                    {targets
                      .filter((target) => String(target.id) !== fromUser)
                      .map((target) => (
                        <option key={target.id} value={target.id}>
                          {target.name}
                        </option>
                      ))}
                  </select>
                </label>

                <button
                  type="button"
                  disabled={busy || !fromUser || !toUser}
                  onClick={() => void onTransfer()}
                  className="inline-flex items-center justify-center gap-2 rounded-md border border-border px-3 py-2 text-sm font-medium text-foreground hover:bg-muted disabled:opacity-60"
                >
                  <ArrowRightLeft className="h-4 w-4" aria-hidden="true" />
                  Transfer leads
                </button>
              </div>
            </div>
          )}
        </div>
      </div>
    </div>
  )
}
