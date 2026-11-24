import { useState } from 'react'
import { discrepancyCategoryLabels } from '../../lib/employeeMeta'
import type { DiscrepancyCategory } from '../../lib/employees'

interface DiscrepancyDialogProps {
  busy?: boolean
  /** Receives the category and the explanation a manager can act on (module 09 T4). */
  onConfirm: (category: DiscrepancyCategory, explanation: string) => void
  onCancel: () => void
}

const MIN_LENGTH = 10
const MAX_LENGTH = 1000

/**
 * Dispute one figure on your own record (module 09 slice T4, spec 21 §T4, ADR-0033).
 *
 * The CRM records the disagreement and tells the manager — it never edits Core 2, so the form is an
 * escalation, not a correction. The explanation bound (10–1000) matches the API rule, so the
 * field's own error state is the same reason the server would give.
 */
export default function DiscrepancyDialog({ busy = false, onConfirm, onCancel }: DiscrepancyDialogProps) {
  const [category, setCategory] = useState<DiscrepancyCategory>('attendance')
  const [explanation, setExplanation] = useState('')
  const [touched, setTouched] = useState(false)

  const trimmed = explanation.trim()
  const tooShort = trimmed.length > 0 && trimmed.length < MIN_LENGTH
  const invalid = trimmed.length < MIN_LENGTH
  const remaining = MAX_LENGTH - explanation.length

  return (
    <div
      role="dialog"
      aria-modal="true"
      aria-label="Report a discrepancy"
      className="fixed inset-0 z-50 flex items-center justify-center bg-foreground/40 p-4"
    >
      <div className="w-full max-w-md rounded-lg border border-border bg-card p-5">
        <h2 className="text-sm font-semibold text-foreground">Report a discrepancy</h2>
        <p className="mt-1 text-sm text-muted-foreground">
          Flag a figure you believe is wrong. Your manager and the administrators are notified and
          review it with HR; nothing here changes Core 2.
        </p>

        <label className="mt-4 block text-sm font-medium text-foreground">
          What is disputed?
          <select
            value={category}
            onChange={(event) => setCategory(event.target.value as DiscrepancyCategory)}
            className="mt-1 w-full rounded-md border border-input bg-card px-3 py-2 text-sm text-foreground outline-none focus:ring-2 focus:ring-ring"
          >
            {(Object.keys(discrepancyCategoryLabels) as DiscrepancyCategory[]).map((value) => (
              <option key={value} value={value}>
                {discrepancyCategoryLabels[value]}
              </option>
            ))}
          </select>
        </label>

        <label className="mt-3 block text-sm font-medium text-foreground">
          What happened?
          <textarea
            rows={4}
            value={explanation}
            onChange={(event) => setExplanation(event.target.value)}
            onBlur={() => setTouched(true)}
            maxLength={MAX_LENGTH}
            aria-invalid={touched && invalid}
            aria-describedby="discrepancy-explanation-hint"
            placeholder="Dates, the figure you expected, and anything your manager can check against HR."
            className="mt-1 w-full rounded-md border border-input bg-card px-3 py-2 text-sm text-foreground outline-none focus:ring-2 focus:ring-ring"
          />
        </label>
        <p id="discrepancy-explanation-hint" className={`mt-1 text-xs ${tooShort ? 'text-destructive' : 'text-muted-foreground'}`}>
          {tooShort
            ? `Add at least ${MIN_LENGTH} characters so the manager can act on it.`
            : `${trimmed.length}/${MAX_LENGTH} characters · at least ${MIN_LENGTH} needed`}
        </p>
        {remaining < 100 && <p className="mt-1 text-xs text-muted-foreground">{remaining} characters left</p>}

        <div className="mt-5 flex justify-end gap-2">
          <button
            type="button"
            onClick={onCancel}
            className="rounded-md border border-border bg-card px-3 py-2 text-sm font-medium text-foreground hover:bg-muted"
          >
            Cancel
          </button>
          <button
            type="button"
            disabled={busy || invalid}
            onClick={() => {
              setTouched(true)
              if (!invalid) onConfirm(category, trimmed)
            }}
            className="rounded-md bg-primary px-3 py-2 text-sm font-medium text-primary-foreground disabled:opacity-60"
          >
            {busy ? 'Sending…' : 'Submit for review'}
          </button>
        </div>
      </div>
    </div>
  )
}
