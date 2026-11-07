import { formatCurrency } from '../../lib/format'
import { opportunityStageMeta, openStageOrder } from '../../lib/opportunityMeta'
import type { OpportunityStage, PipelineColumn } from '../../types'

interface PipelineFunnelProps {
  columns: PipelineColumn[]
  /** The focused Funnel view adds a stage breakdown below the bar. */
  detailed?: boolean
}

// Stage fills stay token-based; dynamic `bg-${tone}` classes would not survive Tailwind.
const stageFill: Record<OpportunityStage, string> = {
  new_inquiry: 'bg-muted-foreground/40',
  qualified: 'bg-counts',
  discovery: 'bg-counts/70',
  proposal_sent: 'bg-activity',
  negotiation: 'bg-activity/70',
  on_hold: 'bg-rating-warm',
  won: 'bg-people',
  lost: 'bg-destructive',
}

/**
 * The pipeline funnel (spec 18 S5): open-stage share by value (falling back to count when
 * nothing carries a value), with a compact legend on the board or a full stage breakdown in
 * the Funnel view. Derived from the board payload — never a second request.
 */
export default function PipelineFunnel({ columns, detailed = false }: PipelineFunnelProps) {
  const stages = openStageOrder.map((stage) => {
    const column = columns.find((entry) => entry.stage === stage)
    return {
      stage,
      count: column?.count ?? 0,
      value: column?.value ?? 0,
      weighted: column?.weighted_value ?? 0,
    }
  })

  const totalCount = stages.reduce((total, stage) => total + stage.count, 0)
  const totalValue = stages.reduce((total, stage) => total + stage.value, 0)
  const shareOf = (stage: (typeof stages)[number]): number => {
    if (totalValue > 0) return (stage.value / totalValue) * 100
    if (totalCount > 0) return (stage.count / totalCount) * 100
    return 0
  }

  const summary = `${totalCount} open ${totalCount === 1 ? 'deal' : 'deals'} worth ${formatCurrency(totalValue)}`

  return (
    <section
      aria-label="Pipeline funnel"
      className="mb-4 rounded-lg border border-border bg-card p-4"
    >
      <div className="flex flex-wrap items-baseline justify-between gap-2">
        <h2 className="text-sm font-medium text-foreground">Stage funnel</h2>
        <span className="text-xs text-muted-foreground">{summary}</span>
      </div>

      <div
        className="mt-3 flex h-3 overflow-hidden rounded-full bg-muted"
        role="img"
        aria-label={summary}
      >
        {stages.map((stage) => {
          const share = shareOf(stage)
          if (share === 0) return null

          return (
            <div
              key={stage.stage}
              className={stageFill[stage.stage]}
              style={{ width: `${share}%` }}
              title={`${opportunityStageMeta[stage.stage].label}: ${stage.count} · ${formatCurrency(stage.value)}`}
            />
          )
        })}
      </div>

      {detailed ? (
        <div className="mt-4 grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
          {stages.map((stage) => (
            <div key={stage.stage} className="rounded-md border border-border p-3">
              <div className="flex items-center justify-between gap-2">
                <span className="text-sm font-medium text-foreground">
                  {opportunityStageMeta[stage.stage].label}
                </span>
                <span className="text-xs text-muted-foreground">{shareOf(stage).toFixed(1)}%</span>
              </div>
              <p className="mt-1 text-sm font-semibold text-cost">{formatCurrency(stage.value)}</p>
              <p className="mt-1 text-xs text-muted-foreground">
                {stage.count} {stage.count === 1 ? 'deal' : 'deals'} ·{' '}
                {formatCurrency(stage.weighted)} weighted
              </p>
            </div>
          ))}
        </div>
      ) : (
        <div className="mt-3 flex flex-wrap gap-x-4 gap-y-1.5">
          {stages.map((stage) => (
            <span
              key={stage.stage}
              className="inline-flex items-center gap-1.5 text-xs text-muted-foreground"
            >
              <span
                className={`h-2 w-2 rounded-full ${stageFill[stage.stage]}`}
                aria-hidden="true"
              />
              {opportunityStageMeta[stage.stage].label} · {stage.count} ·{' '}
              {formatCurrency(stage.value)}
            </span>
          ))}
        </div>
      )}
    </section>
  )
}
