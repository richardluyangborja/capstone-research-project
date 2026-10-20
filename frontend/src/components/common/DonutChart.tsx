import { Link } from 'react-router-dom'
import type { DashboardDonutSegment } from '../../lib/dashboard'

interface DonutChartProps {
  segments: DashboardDonutSegment[]
  /** Pre-formatted total shown in the middle. */
  total: string
  centerLabel?: string
  ariaLabel?: string
}

const strokeTone: Record<DashboardDonutSegment['domain'], string> = {
  counts: 'stroke-counts',
  people: 'stroke-people',
  activity: 'stroke-activity',
  cost: 'stroke-cost',
}

const dotTone: Record<DashboardDonutSegment['domain'], string> = {
  counts: 'bg-counts',
  people: 'bg-people',
  activity: 'bg-activity',
  cost: 'bg-cost',
}

const SIZE = 200
const RADIUS = 72
const STROKE = 20
const CIRCUMFERENCE = 2 * Math.PI * RADIUS

/** A token-only donut with a clickable legend. Same rules as `LineChart` (spec 14, ADR-0020). */
export default function DonutChart({
  segments,
  total,
  centerLabel = 'open pipeline',
  ariaLabel = 'Donut chart',
}: DonutChartProps) {
  const sum = segments.reduce((acc, segment) => acc + segment.value, 0) || 1
  let offset = 0

  return (
    <div>
      <div className="relative mx-auto h-44 w-44">
        <svg
          viewBox={`0 0 ${SIZE} ${SIZE}`}
          className="h-full w-full -rotate-90"
          role="img"
          aria-label={`${ariaLabel}: ${segments
            .map((segment) => `${segment.label} ${Math.round((segment.value / sum) * 100)}%`)
            .join(', ')}`}
        >
          <circle
            cx={SIZE / 2}
            cy={SIZE / 2}
            r={RADIUS}
            fill="none"
            strokeWidth={STROKE}
            className="stroke-muted"
          />
          {segments.map((segment) => {
            const length = (segment.value / sum) * CIRCUMFERENCE
            const dash = Math.max(length - 2, 0)

            const circle = (
              <circle
                key={segment.label}
                cx={SIZE / 2}
                cy={SIZE / 2}
                r={RADIUS}
                fill="none"
                strokeWidth={STROKE}
                strokeDasharray={`${dash} ${CIRCUMFERENCE - dash}`}
                strokeDashoffset={-offset}
                strokeLinecap="butt"
                className={strokeTone[segment.domain]}
              >
                <title>{`${segment.label}: ${segment.display}`}</title>
              </circle>
            )

            offset += length
            return circle
          })}
        </svg>
        <div className="pointer-events-none absolute inset-0 flex flex-col items-center justify-center text-center">
          <span className="text-lg font-semibold text-foreground">{total}</span>
          <span className="text-xs text-muted-foreground">{centerLabel}</span>
        </div>
      </div>

      <ul className="mt-4 space-y-2">
        {segments.map((segment) => {
          const share = Math.round((segment.value / sum) * 100)
          const body = (
            <>
              <span className={`h-2.5 w-2.5 rounded-full ${dotTone[segment.domain]}`} aria-hidden />
              <span className="flex-1 text-sm text-foreground">{segment.label}</span>
              <span className="text-xs text-muted-foreground">{share}%</span>
              <span className="w-24 text-right text-xs font-medium text-foreground">
                {segment.display}
              </span>
            </>
          )

          return (
            <li key={segment.label}>
              {segment.to ? (
                <Link
                  to={segment.to}
                  className="flex items-center gap-2 rounded-md px-1 py-0.5 hover:bg-secondary"
                >
                  {body}
                </Link>
              ) : (
                <div className="flex items-center gap-2 px-1 py-0.5">{body}</div>
              )}
            </li>
          )
        })}
      </ul>
    </div>
  )
}
