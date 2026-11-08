import { useCallback, useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { Briefcase, Lock, RefreshCw } from 'lucide-react'
import FormCard from '../common/FormCard'
import StatusBadge from '../common/StatusBadge'
import { getCompanyDelivery } from '../../lib/deliveries'
import { jobOrderMeta, priorityMeta } from '../../lib/deliveryMeta'
import { formatDate, formatDateTime } from '../../lib/format'
import type { CompanyDelivery } from '../../types'

interface OpportunityRecruitmentContextProps {
  companyId: string
}

/**
 * The deal's read-only recruitment seam (spec 18 S5): the account's active job orders as
 * Recruitment reports them, so a rep can see whether the demand behind the deal is being
 * filled. The CRM never creates, edits or closes a job order (ADR-0025).
 */
export default function OpportunityRecruitmentContext({
  companyId,
}: OpportunityRecruitmentContextProps) {
  const [delivery, setDelivery] = useState<CompanyDelivery | null>(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')

  const load = useCallback(async () => {
    setLoading(true)
    setError('')

    try {
      setDelivery(await getCompanyDelivery(companyId))
    } catch {
      setError('Could not load the recruitment feed.')
    } finally {
      setLoading(false)
    }
  }, [companyId])

  useEffect(() => {
    void load()
  }, [load])

  const activeOrders =
    delivery?.data.job_orders.filter(
      (order) => order.status !== 'closed' && order.status !== 'cancelled',
    ) ?? []

  return (
    <FormCard
      title="Staffing & Recruitment Context"
      description="Recruitment's read-only job orders for this account."
    >
      <div className="flex items-center justify-between gap-2 text-xs text-muted-foreground">
        <span className="inline-flex items-center gap-1.5">
          <Lock className="h-3.5 w-3.5" aria-hidden="true" />
          Read-only feed
        </span>
        <Link to={`/accounts/${companyId}?tab=delivery`} className="text-primary hover:underline">
          Account delivery
        </Link>
      </div>

      {loading ? (
        <p className="mt-3 text-sm text-muted-foreground">Loading recruitment context…</p>
      ) : error ? (
        <div className="mt-3 rounded-md border border-destructive/30 bg-destructive/10 p-3">
          <p className="text-sm text-destructive">{error}</p>
          <button
            type="button"
            onClick={() => void load()}
            className="mt-2 inline-flex items-center gap-1.5 rounded-md border border-border px-2.5 py-1 text-xs font-medium text-foreground hover:bg-muted"
          >
            <RefreshCw className="h-3 w-3" aria-hidden="true" />
            Retry
          </button>
        </div>
      ) : activeOrders.length === 0 ? (
        <p className="mt-3 text-sm text-muted-foreground">
          No active job orders on this account yet.
        </p>
      ) : (
        <ul className="mt-3 flex flex-col gap-2">
          {activeOrders.map((order) => (
            <li key={order.reference} className="rounded-md border border-border p-3">
              <div className="flex items-start justify-between gap-2">
                <div>
                  <p className="text-sm font-medium text-foreground">{order.role}</p>
                  <p className="mt-0.5 font-mono text-xs text-muted-foreground">
                    {order.reference}
                  </p>
                </div>
                <StatusBadge tone={jobOrderMeta[order.status].tone}>
                  {jobOrderMeta[order.status].label}
                </StatusBadge>
              </div>
              <div className="mt-2 flex flex-wrap items-center gap-2 text-xs text-muted-foreground">
                <span className="inline-flex items-center gap-1">
                  <Briefcase className="h-3.5 w-3.5" aria-hidden="true" />
                  {order.filled} / {order.requested} filled
                </span>
                <StatusBadge tone={priorityMeta[order.priority].tone}>
                  {priorityMeta[order.priority].label} priority
                </StatusBadge>
                {order.target_start_date && (
                  <span>Target {formatDate(order.target_start_date)}</span>
                )}
              </div>
              {order.recruiter && (
                <p className="mt-1 text-xs text-muted-foreground">Recruiter: {order.recruiter}</p>
              )}
            </li>
          ))}
        </ul>
      )}

      {delivery && (
        <p className="mt-3 text-xs text-muted-foreground">
          {delivery.meta.source} · As of {formatDateTime(delivery.meta.as_of)}
        </p>
      )}
    </FormCard>
  )
}
