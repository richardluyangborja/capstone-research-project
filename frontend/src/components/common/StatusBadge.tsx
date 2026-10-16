import type { ReactNode } from 'react'

export type Tone =
  | 'counts'
  | 'people'
  | 'activity'
  | 'cost'
  | 'neutral'
  | 'danger'
  | 'success'
  | 'hot'
  | 'warm'
  | 'cold'

// Token-mapped tones only — no hardcoded colors (design system rule).
const toneClass: Record<Tone, string> = {
  counts: 'bg-counts/15 text-counts',
  people: 'bg-people/15 text-people',
  activity: 'bg-activity/15 text-activity',
  cost: 'bg-cost/15 text-cost',
  neutral: 'bg-muted text-muted-foreground',
  danger: 'bg-destructive/15 text-destructive',
  success: 'bg-people/15 text-people',
  hot: 'bg-rating-hot/15 text-rating-hot',
  warm: 'bg-rating-warm/15 text-rating-warm',
  cold: 'bg-rating-cold/15 text-rating-cold',
}

interface StatusBadgeProps {
  tone?: Tone
  children: ReactNode
  className?: string
}

export default function StatusBadge({ tone = 'neutral', children, className }: StatusBadgeProps) {
  return (
    <span
      className={`inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium ${toneClass[tone]} ${className ?? ''}`}
    >
      {children}
    </span>
  )
}
