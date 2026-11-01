import type { Tone } from '../components/common/StatusBadge'
import type { InvoiceStatus } from '../types'

/** Shared presentation for the read-only Finance feed (S11/S12). */
export const invoiceStatusMeta: Record<InvoiceStatus, { label: string; tone: Tone }> = {
  paid: { label: 'Paid', tone: 'success' },
  open: { label: 'Open', tone: 'counts' },
  overdue: { label: 'Overdue', tone: 'danger' },
}

export const billingAccountMeta: Record<
  'current' | 'overdue' | 'closed',
  { label: string; tone: Tone }
> = {
  current: { label: 'Current', tone: 'success' },
  overdue: { label: 'Overdue', tone: 'danger' },
  closed: { label: 'Closed', tone: 'neutral' },
}
