import type { DashboardDomain, DashboardLineSeries } from '../../lib/dashboard'

interface LineChartProps {
  labels: string[]
  series: DashboardLineSeries[]
  /** Defaults to a plain count; pass a formatter when the series carries money or percentages. */
  formatValue?: (value: number) => string
}

const strokeTone: Record<DashboardDomain, string> = {
  counts: 'stroke-counts',
  people: 'stroke-people',
  activity: 'stroke-activity',
  cost: 'stroke-cost',
}

const dotTone: Record<DashboardDomain, string> = {
  counts: 'bg-counts',
  people: 'bg-people',
  activity: 'bg-activity',
  cost: 'bg-cost',
}

const WIDTH = 640
const HEIGHT = 220
const PAD = { top: 16, right: 16, bottom: 24, left: 40 }

/**
 * A small, dependency-free line chart (spec 14, ADR-0020). Tokens only — each series is drawn
 * with a domain stroke class, so the fixed colour mapping holds in light and dark mode.
 */
export default function LineChart({ labels, series, formatValue = String }: LineChartProps) {
  const values = series.flatMap((line) => line.values)
  const rawMax = Math.max(...values, 1)
  // Round the axis up so the top line is not glued to the plot edge.
  const magnitude = Math.pow(10, Math.max(0, String(Math.floor(rawMax)).length - 1))
  const max = Math.max(magnitude, Math.ceil(rawMax / magnitude) * magnitude)
  const plotWidth = WIDTH - PAD.left - PAD.right
  const plotHeight = HEIGHT - PAD.top - PAD.bottom
  const step = labels.length > 1 ? plotWidth / (labels.length - 1) : 0

  const x = (index: number) => PAD.left + index * step
  const y = (value: number) => PAD.top + (1 - value / max) * plotHeight
  const ticks = [0, 0.5, 1]

  return (
    <div>
      <svg
        viewBox={`0 0 ${WIDTH} ${HEIGHT}`}
        className="h-56 w-full"
        role="img"
        aria-label={`Line chart: ${series.map((line) => line.name).join(', ')}`}
      >
        {ticks.map((tick) => (
          <g key={tick}>
            <line
              x1={PAD.left}
              x2={WIDTH - PAD.right}
              y1={y(max * tick)}
              y2={y(max * tick)}
              className="stroke-border"
              strokeWidth={1}
              strokeDasharray={tick === 0 ? undefined : '4 4'}
            />
            <text
              x={PAD.left - 8}
              y={y(max * tick)}
              textAnchor="end"
              dy="0.32em"
              className="fill-muted-foreground text-xs"
            >
              {formatValue(Math.round(max * tick))}
            </text>
          </g>
        ))}

        {series.map((line) => {
          const points = line.values.map((value, index) => `${x(index)},${y(value)}`).join(' ')

          return (
            <g key={line.name}>
              <polyline
                points={points}
                fill="none"
                strokeWidth={2}
                strokeLinejoin="round"
                strokeLinecap="round"
                className={strokeTone[line.domain]}
              />
              {line.values.map((value, index) => (
                <circle
                  key={`${line.name}-${labels[index] ?? index}`}
                  cx={x(index)}
                  cy={y(value)}
                  r={3}
                  className={`${strokeTone[line.domain]} fill-card`}
                  strokeWidth={2}
                >
                  <title>{`${labels[index] ?? ''} · ${line.name}: ${formatValue(value)}`}</title>
                </circle>
              ))}
            </g>
          )
        })}

        {labels.map((label, index) => (
          <text
            key={`${label}-${index}`}
            x={x(index)}
            y={HEIGHT - 6}
            textAnchor={index === 0 ? 'start' : index === labels.length - 1 ? 'end' : 'middle'}
            className="fill-muted-foreground text-xs"
          >
            {label}
          </text>
        ))}
      </svg>

      <div className="mt-2 flex flex-wrap items-center gap-x-4 gap-y-1">
        {series.map((line) => (
          <span
            key={line.name}
            className="inline-flex items-center gap-1.5 text-xs text-muted-foreground"
          >
            <span className={`h-2 w-2 rounded-full ${dotTone[line.domain]}`} aria-hidden />
            {line.name}
          </span>
        ))}
      </div>
    </div>
  )
}
