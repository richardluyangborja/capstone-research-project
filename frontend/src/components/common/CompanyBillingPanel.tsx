import { Lock } from 'lucide-react'
import DataTable, { type DataTableColumn } from './DataTable'
import EmptyState from './EmptyState'
import FormCard from './FormCard'
import StatusBadge from './StatusBadge'
import { billingAccountMeta, invoiceStatusMeta } from '../../lib/billingMeta'
import { formatCurrency, formatDate, formatDateTime } from '../../lib/format'
import type { BillingSummary, CompanyBilling, FinanceInvoice } from '../../types'

interface CompanyBillingPanelProps {
  billing: BillingSummary | null
  meta: CompanyBilling['meta'] | null
}

const invoiceColumns: DataTableColumn<FinanceInvoice>[] = [
  {
    key: 'reference',
    header: 'Invoice',
    render: (invoice) => (
      <span className="font-mono text-xs text-foreground">{invoice.reference}</span>
    ),
  },
  {
    key: 'period',
    header: 'Period',
    className: 'text-muted-foreground',
    render: (invoice) => (
      <span className="text-xs">
        {formatDate(invoice.period_start)} – {formatDate(invoice.period_end)}
      </span>
    ),
  },
  {
    key: 'issued',
    header: 'Issued / due',
    className: 'text-muted-foreground',
    render: (invoice) => (
      <span className="text-xs">
        {formatDate(invoice.issued_at)} → {formatDate(invoice.due_at)}
      </span>
    ),
  },
  {
    key: 'amount',
    header: 'Amount',
    className: 'text-right',
    headerClassName: 'text-right',
    render: (invoice) => <span className="text-foreground">{formatCurrency(invoice.amount)}</span>,
  },
  {
    key: 'balance',
    header: 'Balance',
    className: 'text-right',
    headerClassName: 'text-right',
    render: (invoice) => (
      <span
        className={Number(invoice.balance) > 0 ? 'font-medium text-cost' : 'text-muted-foreground'}
      >
        {formatCurrency(invoice.balance)}
      </span>
    ),
  },
  {
    key: 'status',
    header: 'Status',
    render: (invoice) => (
      <StatusBadge tone={invoiceStatusMeta[invoice.status].tone}>
        {invoiceStatusMeta[invoice.status].label}
      </StatusBadge>
    ),
  },
]

/**
 * Account 360's manager+ Finance section (module 01 refresh S11, spec 16 §Read-only
 * external feeds). Amounts, aging and invoices come from Finance (mocked); the CRM never
 * writes an invoice or posts a payment.
 */
export default function CompanyBillingPanel({ billing, meta }: CompanyBillingPanelProps) {
  return (
    <FormCard
      title="Billing"
      description="Read-only AR snapshot and invoices from the Finance department (mocked)."
    >
      <div className="mb-4 flex flex-wrap items-center justify-between gap-2 text-xs text-muted-foreground">
        <span className="inline-flex items-center gap-1.5">
          <Lock className="h-3.5 w-3.5" aria-hidden="true" />
          Read-only feed
        </span>
        <span>
          {meta ? (
            <>
              {meta.source} · As of {formatDateTime(meta.as_of)}
            </>
          ) : (
            'Loading source…'
          )}
        </span>
      </div>

      {billing === null ? (
        <p className="text-sm text-muted-foreground">Loading…</p>
      ) : (
        <div className="space-y-5">
          <div className="grid grid-cols-2 gap-4 lg:grid-cols-4">
            <div>
              <p className="text-xs font-medium text-muted-foreground">Account</p>
              <div className="mt-1">
                <StatusBadge tone={billingAccountMeta[billing.account_status].tone}>
                  {billingAccountMeta[billing.account_status].label}
                </StatusBadge>
              </div>
            </div>
            <div>
              <p className="text-xs font-medium text-muted-foreground">Outstanding</p>
              <p className="mt-1 text-lg font-semibold text-cost">
                {formatCurrency(billing.outstanding)}
              </p>
            </div>
            <div>
              <p className="text-xs font-medium text-muted-foreground">Overdue</p>
              <p
                className={`mt-1 text-lg font-semibold ${
                  Number(billing.overdue) > 0 ? 'text-danger' : 'text-foreground'
                }`}
              >
                {Number(billing.overdue) > 0 ? formatCurrency(billing.overdue) : '—'}
              </p>
            </div>
            <div>
              <p className="text-xs font-medium text-muted-foreground">Terms / next due</p>
              <p className="mt-1 text-sm font-medium text-foreground">
                {billing.credit_terms}
                {billing.next_due_date ? ` · ${formatDate(billing.next_due_date)}` : ''}
              </p>
            </div>
          </div>

          <div className="grid grid-cols-2 gap-3 border-t border-border pt-4 text-sm md:grid-cols-4">
            {(
              [
                ['Current', 'current'],
                ['1–30 days', '1_30'],
                ['31–60 days', '31_60'],
                ['60+ days', '60_plus'],
              ] as const
            ).map(([label, key]) => (
              <div key={key} className="rounded-md border border-border bg-background p-3">
                <p className="text-xs text-muted-foreground">{label}</p>
                <p className="mt-1 font-medium text-foreground">
                  {formatCurrency(billing.aging[key])}
                </p>
              </div>
            ))}
          </div>

          <div>
            <div className="mb-2 flex items-center justify-between">
              <h3 className="text-sm font-semibold text-foreground">Invoices</h3>
              <span className="text-xs text-muted-foreground">
                {billing.invoices.length} invoice{billing.invoices.length === 1 ? '' : 's'}
              </span>
            </div>
            <DataTable
              columns={invoiceColumns}
              rows={billing.invoices}
              rowKey={(invoice) => invoice.reference}
              empty={
                <EmptyState
                  title="No invoices yet"
                  description="Finance has not issued an invoice for this account."
                />
              }
            />
          </div>
        </div>
      )}
    </FormCard>
  )
}
