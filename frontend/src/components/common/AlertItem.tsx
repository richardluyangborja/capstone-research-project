import type { ReactNode } from 'react'

export type AlertVariant = 'info' | 'success' | 'warning' | 'danger'

const variantClass: Record<AlertVariant, string> = {
  info: 'border-counts/40 bg-counts/10 text-foreground',
  success: 'border-people/40 bg-people/10 text-foreground',
  warning: 'border-cost/40 bg-cost/10 text-foreground',
  danger: 'border-destructive/40 bg-destructive/10 text-destructive',
}

interface AlertItemProps {
  variant?: AlertVariant
  title?: string
  children?: ReactNode
}

export default function AlertItem({ variant = 'info', title, children }: AlertItemProps) {
  return (
    <div className={`rounded-md border px-4 py-3 text-sm ${variantClass[variant]}`} role="alert">
      {title && <p className="font-medium">{title}</p>}
      {children && <div className={title ? 'mt-1 text-muted-foreground' : ''}>{children}</div>}
    </div>
  )
}
