import { useEffect, useRef, useState } from 'react'
import { Link } from 'react-router-dom'
import { X } from 'lucide-react'
import StatusBadge from '../common/StatusBadge'
import StaffingBadge from '../common/StaffingBadge'
import DealFollowUpBadge from '../common/DealFollowUpBadge'
import { getOpportunityTimeline } from '../../lib/opportunities'
import { activityTypeMeta } from '../../lib/leadMeta'
import { opportunityStageMeta } from '../../lib/opportunityMeta'
import { formatCurrency, formatDate, formatDateTime } from '../../lib/format'
import type { Activity, Opportunity } from '../../types'

interface DealPeekDrawerProps {
  deal: Opportunity | null
  onClose: () => void
}

/**
 * The quick deal peek (spec 18 S5): a side drawer over the board, so a rep can read the
 * deal's demand, next step, upcoming follow-up and recent timeline without leaving the
 * column they were working. The timeline is the only extra read.
 */
export default function DealPeekDrawer({ deal, onClose }: DealPeekDrawerProps) {
  const closeRef = useRef<HTMLButtonElement>(null)
  const [timeline, setTimeline] = useState<Activity[]>([])
  const [loading, setLoading] = useState(false)
  const [error, setError] = useState('')

  useEffect(() => {
    if (!deal) return

    let active = true
    setLoading(true)
    setError('')
    setTimeline([])

    getOpportunityTimeline(deal.id)
      .then((history) => {
        if (active) setTimeline(history.data)
      })
      .catch(() => {
        if (active) setError('Could not load the deal timeline.')
      })
      .finally(() => {
        if (active) setLoading(false)
      })

    return () => {
      active = false
    }
  }, [deal])

  useEffect(() => {
    if (!deal) return

    const onKeyDown = (event: KeyboardEvent) => {
      if (event.key === 'Escape') onClose()
    }

    document.addEventListener('keydown', onKeyDown)
    closeRef.current?.focus()

    return () => document.removeEventListener('keydown', onKeyDown)
  }, [deal, onClose])

  if (!deal) return null

  return (
    <div
      className="fixed inset-0 z-50 flex justify-end bg-foreground/30"
      onMouseDown={(event) => {
        if (event.target === event.currentTarget) onClose()
      }}
    >
      <aside
        role="dialog"
        aria-modal="true"
        aria-labelledby="deal-peek-title"
        className="h-full w-full max-w-md overflow-y-auto border-l border-border bg-card p-5 shadow-xl"
      >
        <div className="flex items-start justify-between gap-3">
          <div>
            <p className="font-mono text-xs text-muted-foreground">{deal.reference}</p>
            <h2 id="deal-peek-title" className="mt-1 text-lg font-semibold text-foreground">
              {deal.name}
            </h2>
            <p className="mt-1 text-sm text-muted-foreground">{deal.company?.name ?? 'Account'}</p>
          </div>
          <button
            ref={closeRef}
            type="button"
            onClick={onClose}
            aria-label="Close deal peek"
            className="rounded-md border border-border p-1.5 text-muted-foreground hover:bg-muted hover:text-foreground"
          >
            <X className="h-4 w-4" aria-hidden="true" />
          </button>
        </div>

        <div className="mt-3 flex flex-wrap items-center gap-2">
          <StatusBadge tone={opportunityStageMeta[deal.stage].tone}>
            {opportunityStageMeta[deal.stage].label}
          </StatusBadge>
          <StaffingBadge
            estimatedHeadcount={deal.estimated_headcount}
            serviceType={deal.service_type}
          />
          <DealFollowUpBadge deal={deal} />
        </div>

        <dl className="mt-4 grid grid-cols-2 gap-3 text-sm">
          <div>
            <dt className="text-xs text-muted-foreground">Value</dt>
            <dd className="font-semibold text-cost">{formatCurrency(deal.amount)}</dd>
          </div>
          <div>
            <dt className="text-xs text-muted-foreground">Weighted</dt>
            <dd className="font-medium text-foreground">{formatCurrency(deal.weighted_value)}</dd>
          </div>
          <div>
            <dt className="text-xs text-muted-foreground">Expected close</dt>
            <dd className="text-foreground">{formatDate(deal.expected_close_date) || 'Not set'}</dd>
          </div>
          <div>
            <dt className="text-xs text-muted-foreground">Target start</dt>
            <dd className="text-foreground">{formatDate(deal.target_start_date) || 'Not set'}</dd>
          </div>
          <div>
            <dt className="text-xs text-muted-foreground">Owner</dt>
            <dd className="text-foreground">{deal.owner?.name ?? 'Unassigned'}</dd>
          </div>
          <div>
            <dt className="text-xs text-muted-foreground">Next follow-up</dt>
            <dd className="text-foreground">
              {deal.next_follow_up_at ? formatDateTime(deal.next_follow_up_at) : 'Nothing owed'}
            </dd>
          </div>
        </dl>

        <div className="mt-4 rounded-md border border-border p-3">
          <p className="text-xs text-muted-foreground">Next step</p>
          <p className="mt-1 text-sm text-foreground">{deal.next_step ?? 'No next step set.'}</p>
        </div>

        {deal.estimated_headcount !== null && (
          <div className="mt-3 rounded-md border border-border p-3">
            <p className="text-xs text-muted-foreground">Staffing demand</p>
            <p className="mt-1 text-sm text-foreground">
              {deal.estimated_headcount} {deal.estimated_headcount === 1 ? 'worker' : 'workers'}{' '}
              requested
            </p>
          </div>
        )}

        <div className="mt-5">
          <h3 className="text-sm font-medium text-foreground">Recent timeline</h3>
          {loading ? (
            <p className="mt-2 text-sm text-muted-foreground">Loading the timeline…</p>
          ) : error ? (
            <p className="mt-2 text-sm text-destructive">{error}</p>
          ) : timeline.length === 0 ? (
            <p className="mt-2 text-sm text-muted-foreground">No activity yet.</p>
          ) : (
            <ul className="mt-2 flex flex-col gap-2">
              {timeline.slice(0, 6).map((entry) => (
                <li key={entry.id} className="rounded-md border border-border p-2.5">
                  <div className="flex items-start justify-between gap-2">
                    <span className="text-sm text-foreground">{entry.subject}</span>
                    <StatusBadge tone={activityTypeMeta[entry.type].tone}>
                      {activityTypeMeta[entry.type].label}
                    </StatusBadge>
                  </div>
                  <p className="mt-1 text-xs text-muted-foreground">
                    {formatDateTime(entry.occurred_at ?? entry.created_at)}
                  </p>
                </li>
              ))}
            </ul>
          )}
        </div>

        <Link
          to={`/opportunities/${deal.id}`}
          className="mt-5 inline-flex w-full items-center justify-center rounded-md bg-primary px-3 py-2 text-sm font-medium text-primary-foreground"
        >
          Open full deal
        </Link>
      </aside>
    </div>
  )
}
