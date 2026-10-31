import { Plus, Trash2 } from 'lucide-react'
import type { FormEvent, ReactNode } from 'react'
import { billingUnitLabels, billingUnitOptions } from '../../lib/contractMeta'
import { formatCurrency } from '../../lib/format'
import { rateCardPreview, rateCardTotals, type RateCardForm } from '../../lib/rateCards'
import type { BillingUnit, CompanySite } from '../../types'

interface RateCardEditorProps {
  rows: RateCardForm[]
  sites: CompanySite[]
  /** Validation errors keyed `rate_cards.{index}.{field}`. */
  errors?: Record<string, string[]>
  canViewMargin: boolean
  saving: boolean
  submitLabel?: string
  onChange: (key: string, patch: Partial<RateCardForm>) => void
  onAdd: () => void
  onRemove: (key: string) => void
  onSubmit: (event: FormEvent) => void
  onCancel: () => void
}

const inputClass =
  'mt-1 w-full rounded-md border border-input bg-card px-3 py-2 text-sm text-foreground outline-none focus:ring-2 focus:ring-ring disabled:opacity-60'

function Field({
  label,
  error,
  className,
  children,
}: {
  label: string
  error?: string[]
  className?: string
  children: ReactNode
}) {
  return (
    <label className={`block text-sm font-medium text-foreground ${className ?? ''}`}>
      {label}
      {children}
      {error && <span className="mt-1 block text-xs text-destructive">{error[0]}</span>}
    </label>
  )
}

/**
 * The replace-the-set rate-card editor (module 01 refresh S7). It is shared by Account 360
 * and the contract detail so both surfaces offer the same fields — including site and notes.
 */
export default function RateCardEditor({
  rows,
  sites,
  errors = {},
  canViewMargin,
  saving,
  submitLabel = 'Save rate cards',
  onChange,
  onAdd,
  onRemove,
  onSubmit,
  onCancel,
}: RateCardEditorProps) {
  const totals = rateCardTotals(rows)

  return (
    <form onSubmit={onSubmit} className="flex flex-col gap-4">
      {rows.length === 0 && (
        <p className="text-sm text-muted-foreground">
          No lines yet. Add the roles, headcount and rates behind this agreement, or save an empty
          set to clear it.
        </p>
      )}

      {rows.map((row, index) => (
        <div key={row.key} className="rounded-lg border border-border p-3">
          <div className="flex items-center justify-between">
            <span className="text-sm font-medium text-foreground">Line {index + 1}</span>
            <button
              type="button"
              onClick={() => onRemove(row.key)}
              className="inline-flex items-center gap-1 text-xs font-medium text-destructive"
            >
              <Trash2 className="h-3.5 w-3.5" aria-hidden="true" />
              Remove
            </button>
          </div>

          <div className="mt-3 grid grid-cols-1 gap-3 md:grid-cols-2 lg:grid-cols-4">
            <Field label={`Role ${index + 1}`} error={errors[`rate_cards.${index}.role_title`]}>
              <input
                value={row.role_title}
                placeholder="Warehouse associate"
                onChange={(event) => onChange(row.key, { role_title: event.target.value })}
                className={inputClass}
              />
            </Field>

            <Field label={`Site ${index + 1}`} error={errors[`rate_cards.${index}.site_id`]}>
              <select
                value={row.site_id}
                onChange={(event) => onChange(row.key, { site_id: event.target.value })}
                className={inputClass}
              >
                <option value="">No site</option>
                {sites.map((site) => (
                  <option key={site.id} value={site.id}>
                    {site.label}
                  </option>
                ))}
              </select>
            </Field>

            <Field
              label={`Billing unit ${index + 1}`}
              error={errors[`rate_cards.${index}.billing_unit`]}
            >
              <select
                value={row.billing_unit}
                onChange={(event) => {
                  const unit = event.target.value as BillingUnit
                  onChange(row.key, {
                    billing_unit: unit,
                    units_per_period: unit === 'hourly' ? '176' : '1',
                  })
                }}
                className={inputClass}
              >
                {billingUnitOptions.map((unit) => (
                  <option key={unit} value={unit}>
                    {billingUnitLabels[unit]}
                  </option>
                ))}
              </select>
            </Field>

            <Field label={`Headcount ${index + 1}`} error={errors[`rate_cards.${index}.headcount`]}>
              <input
                type="number"
                min="1"
                value={row.headcount}
                onChange={(event) => onChange(row.key, { headcount: event.target.value })}
                className={inputClass}
              />
            </Field>

            <Field label={`Bill rate ${index + 1}`} error={errors[`rate_cards.${index}.bill_rate`]}>
              <input
                type="number"
                min="0"
                step="0.01"
                value={row.bill_rate}
                onChange={(event) => onChange(row.key, { bill_rate: event.target.value })}
                className={inputClass}
              />
            </Field>

            {canViewMargin && (
              <Field label={`Pay rate ${index + 1}`} error={errors[`rate_cards.${index}.pay_rate`]}>
                <input
                  type="number"
                  min="0"
                  step="0.01"
                  value={row.pay_rate}
                  placeholder="Manager+ only"
                  onChange={(event) => onChange(row.key, { pay_rate: event.target.value })}
                  className={inputClass}
                />
              </Field>
            )}

            {row.billing_unit === 'hourly' && (
              <Field
                label={`Units / month ${index + 1}`}
                error={errors[`rate_cards.${index}.units_per_period`]}
              >
                <input
                  type="number"
                  min="0"
                  step="0.01"
                  value={row.units_per_period}
                  onChange={(event) => onChange(row.key, { units_per_period: event.target.value })}
                  className={inputClass}
                />
              </Field>
            )}

            <Field
              label={`Notes ${index + 1}`}
              error={errors[`rate_cards.${index}.notes`]}
              className="lg:col-span-2"
            >
              <input
                value={row.notes}
                onChange={(event) => onChange(row.key, { notes: event.target.value })}
                className={inputClass}
              />
            </Field>
          </div>

          <div className="mt-2 flex items-center justify-between text-xs text-muted-foreground">
            <span>{row.billing_unit === 'placement' ? 'Placement fee' : 'Client monthly'}</span>
            <span className="text-foreground">{formatCurrency(rateCardPreview(row))}</span>
          </div>
        </div>
      ))}

      <div className="flex flex-wrap items-center justify-between gap-3 border-t border-border pt-3 text-sm">
        <button
          type="button"
          onClick={onAdd}
          className="inline-flex items-center gap-1.5 rounded-md border border-border px-3 py-1.5 text-xs font-medium text-foreground hover:bg-muted"
        >
          <Plus className="h-3.5 w-3.5" aria-hidden="true" />
          Add rate card
        </button>
        <div className="text-right text-muted-foreground">
          <div>
            Client monthly (preview){' '}
            <span className="font-medium text-foreground">
              {formatCurrency(totals.clientMonthly)}
            </span>
          </div>
          {totals.placement > 0 && (
            <div className="text-xs">
              Placement fees{' '}
              <span className="text-foreground">{formatCurrency(totals.placement)}</span>
            </div>
          )}
        </div>
      </div>

      <div className="flex items-center gap-2">
        <button
          type="submit"
          disabled={saving}
          className="rounded-md bg-primary px-4 py-2 text-sm font-medium text-primary-foreground disabled:opacity-60"
        >
          {saving ? 'Saving…' : submitLabel}
        </button>
        <button
          type="button"
          onClick={onCancel}
          className="rounded-md border border-border px-3 py-2 text-sm font-medium text-foreground hover:bg-muted"
        >
          Cancel
        </button>
      </div>
    </form>
  )
}
