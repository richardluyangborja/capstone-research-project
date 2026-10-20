import type { ReactNode } from 'react'

interface ChartCardProps {
  title: string
  description?: string
  /** A link or control shown opposite the title (e.g. "Open board"). */
  action?: ReactNode
  children: ReactNode
  footer?: ReactNode
  className?: string
}

/**
 * The shared chart wrapper (design system, spec 14). Purely presentational: the page owns the
 * data, the chart component draws it, and this card supplies the frame — title, description,
 * action and an optional footer (legend, caption).
 */
export default function ChartCard({
  title,
  description,
  action,
  children,
  footer,
  className = '',
}: ChartCardProps) {
  return (
    <section className={`rounded-lg border border-border bg-card p-4 ${className}`}>
      <div className="flex items-start justify-between gap-3">
        <div>
          <h3 className="text-sm font-semibold text-foreground">{title}</h3>
          {description && <p className="mt-0.5 text-xs text-muted-foreground">{description}</p>}
        </div>
        {action}
      </div>
      <div className="mt-4">{children}</div>
      {footer && <div className="mt-3 border-t border-border pt-3">{footer}</div>}
    </section>
  )
}
