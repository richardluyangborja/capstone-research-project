import { surveyBandMeta } from '../../lib/surveyMeta'

/**
 * The promoter split every satisfaction view shows (module 05 slice S3, spec 13 §S3).
 *
 * A response with no promoter answer has no band, so the three counts need not add up to the
 * number of responses — `answered` is the denominator for the bars.
 */
export default function BandSplit({
  promoters,
  passives,
  detractors,
  answered,
}: {
  promoters: number
  passives: number
  detractors: number
  answered: number
}) {
  const rows = [
    { band: 'promoter' as const, count: promoters, bar: 'bg-people' },
    { band: 'passive' as const, count: passives, bar: 'bg-muted-foreground' },
    { band: 'detractor' as const, count: detractors, bar: 'bg-destructive' },
  ]

  if (answered === 0) {
    return (
      <p className="text-sm text-muted-foreground">
        No promoter answer yet — the split appears once a survey asks the recommendation question.
      </p>
    )
  }

  return (
    <ul className="flex flex-col gap-3">
      {rows.map((row) => {
        const share = Math.round((row.count / answered) * 100)

        return (
          <li key={row.band}>
            <div className="flex items-center justify-between text-sm">
              <span className="font-medium text-foreground">{surveyBandMeta[row.band].label}</span>
              <span className="text-muted-foreground">
                {row.count} · {share}%
              </span>
            </div>
            <div className="mt-1 h-2 overflow-hidden rounded-full bg-muted">
              <div className={`h-full rounded-full ${row.bar}`} style={{ width: `${share}%` }} />
            </div>
          </li>
        )
      })}
    </ul>
  )
}
