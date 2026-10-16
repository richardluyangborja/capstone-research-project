import type { ReactNode } from 'react'

interface FormCardProps {
  title?: string
  description?: string
  children: ReactNode
  footer?: ReactNode
}

export default function FormCard({ title, description, children, footer }: FormCardProps) {
  return (
    <section className="rounded-lg border border-border bg-card">
      {(title || description) && (
        <header className="border-b border-border px-4 py-3">
          {title && <h3 className="text-sm font-semibold text-foreground">{title}</h3>}
          {description && <p className="mt-0.5 text-xs text-muted-foreground">{description}</p>}
        </header>
      )}
      <div className="p-4">{children}</div>
      {footer && (
        <footer className="flex flex-wrap items-center gap-3 border-t border-border px-4 py-3">
          {footer}
        </footer>
      )}
    </section>
  )
}
