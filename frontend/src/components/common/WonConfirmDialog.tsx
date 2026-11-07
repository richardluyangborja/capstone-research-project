import { useState } from 'react'
import { formatCurrency } from '../../lib/format'

export interface WonOutcome {
  /** The actual close date (YYYY-MM-DD). Defaults to today. */
  actual_close_date: string
  win_note?: string
  /** Final negotiated amount — offered only to a manager+ caller (`can_set_amount`). */
  amount?: number
}

interface WonConfirmDialogProps {
  dealName: string
  /** Whether the caller may confirm/adjust the final won amount (manager+). */
  canSetAmount: boolean
  /** The deal's current amount, pre-filled for a manager+ confirmation. */
  amount?: string | null
  busy?: boolean
  onConfirm: (outcome: WonOutcome) => void
  onCancel: () => void
}

/**
 * Winning a deal is a milestone, not a silent drop (spec 18 S3). Confirms the actual
 * close date, lets a manager+ settle the final won amount, captures optional win notes
 * and explains what happens next — the contract is prepared by Legal & Compliance
 * (Core Transaction 3) and the staffing demand goes to Recruitment (Core Transaction 2);
 * the CRM never activates a client or uploads a contract itself (ADR-0027).
 */
export default function WonConfirmDialog({
  dealName,
  canSetAmount,
  amount = null,
  busy = false,
  onConfirm,
  onCancel,
}: WonConfirmDialogProps) {
  const [closeDate, setCloseDate] = useState(new Date().toISOString().slice(0, 10))
  const [winNote, setWinNote] = useState('')
  const [wonAmount, setWonAmount] = useState(
    amount === null ? '' : String(Number(amount)),
  )

  const submit = () => {
    const outcome: WonOutcome = { actual_close_date: closeDate }
    if (winNote.trim() !== '') outcome.win_note = winNote.trim()
    if (canSetAmount && wonAmount !== '') outcome.amount = Number(wonAmount)
    onConfirm(outcome)
  }

  return (
    <div
      role="dialog"
      aria-modal="true"
      aria-label="Confirm won deal"
      className="fixed inset-0 z-50 flex items-center justify-center bg-foreground/40 p-4"
    >
      <div className="w-full max-w-md rounded-lg border border-border bg-card p-5">
        <h2 className="text-sm font-semibold text-foreground">Deal won — confirm the outcome</h2>
        <p className="mt-1 text-sm text-muted-foreground">
          Congratulations — “{dealName}” is won. Confirm the details before it moves to the Won
          column.
        </p>

        <label className="mt-4 block text-sm font-medium text-foreground">
          Actual close date
          <input
            type="date"
            value={closeDate}
            onChange={(event) => setCloseDate(event.target.value)}
            className="mt-1 w-full rounded-md border border-input bg-card px-3 py-2 text-sm text-foreground outline-none focus:ring-2 focus:ring-ring"
          />
        </label>

        {canSetAmount && (
          <label className="mt-3 block text-sm font-medium text-foreground">
            Final won amount
            <input
              type="number"
              min="0"
              step="0.01"
              value={wonAmount}
              onChange={(event) => setWonAmount(event.target.value)}
              placeholder={amount ? formatCurrency(amount) : 'Confirm or adjust the value'}
              className="mt-1 w-full rounded-md border border-input bg-card px-3 py-2 text-sm text-foreground outline-none focus:ring-2 focus:ring-ring"
            />
          </label>
        )}

        <label className="mt-3 block text-sm font-medium text-foreground">
          Win notes <span className="font-normal text-muted-foreground">(optional)</span>
          <textarea
            rows={3}
            value={winNote}
            onChange={(event) => setWinNote(event.target.value)}
            placeholder="Deployment requirements, agreements in principle, anything the next team should know"
            className="mt-1 w-full rounded-md border border-input bg-card px-3 py-2 text-sm text-foreground outline-none focus:ring-2 focus:ring-ring"
          />
        </label>

        <div className="mt-4 rounded-md border border-border bg-muted/40 p-3 text-xs text-muted-foreground">
          <p>What happens next (handled outside the CRM):</p>
          <ul className="mt-1 list-disc pl-4">
            <li>
              Legal &amp; Compliance (Core Transaction 3) prepares the contract — contracts are
              managed in Contract Management.
            </li>
            <li>Recruitment (Core Transaction 2) receives the staffing demand for fulfilment.</li>
          </ul>
        </div>

        <div className="mt-4 flex justify-end gap-2">
          <button
            type="button"
            onClick={onCancel}
            className="rounded-md border border-border bg-card px-3 py-2 text-sm font-medium text-foreground hover:bg-muted"
          >
            Cancel
          </button>
          <button
            type="button"
            disabled={busy || closeDate === ''}
            onClick={submit}
            className="rounded-md bg-primary px-3 py-2 text-sm font-medium text-primary-foreground disabled:opacity-60"
          >
            {busy ? 'Saving…' : 'Mark won'}
          </button>
        </div>
      </div>
    </div>
  )
}
