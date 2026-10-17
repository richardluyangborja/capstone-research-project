import { useState } from 'react'
import type { LostReason } from '../../types'
import { lostReasonLabels } from '../../lib/opportunityMeta'

interface LostReasonDialogProps {
  dealName: string
  busy?: boolean
  onConfirm: (reason: LostReason) => void
  onCancel: () => void
}

/**
 * A lost deal needs a reason (module 02, ADR-0015 §1), whether it was dragged into the
 * Lost column or chosen from the move menu. Shared by the board and the deal page.
 */
export default function LostReasonDialog({
  dealName,
  busy = false,
  onConfirm,
  onCancel,
}: LostReasonDialogProps) {
  const [reason, setReason] = useState<LostReason>('price')

  return (
    <div
      role="dialog"
      aria-modal="true"
      aria-label="Reason for loss"
      className="fixed inset-0 z-50 flex items-center justify-center bg-foreground/40 p-4"
    >
      <div className="w-full max-w-sm rounded-lg border border-border bg-card p-5">
        <h2 className="text-sm font-semibold text-foreground">Mark deal as lost</h2>
        <p className="mt-1 text-sm text-muted-foreground">Why was “{dealName}” lost?</p>

        <label className="mt-4 block text-sm font-medium text-foreground">
          Reason
          <select
            value={reason}
            onChange={(event) => setReason(event.target.value as LostReason)}
            className="mt-1 w-full rounded-md border border-input bg-card px-3 py-2 text-sm text-foreground outline-none focus:ring-2 focus:ring-ring"
          >
            {(Object.keys(lostReasonLabels) as LostReason[]).map((value) => (
              <option key={value} value={value}>
                {lostReasonLabels[value]}
              </option>
            ))}
          </select>
        </label>

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
            disabled={busy}
            onClick={() => onConfirm(reason)}
            className="rounded-md bg-destructive px-3 py-2 text-sm font-medium text-destructive-foreground disabled:opacity-60"
          >
            {busy ? 'Saving…' : 'Mark lost'}
          </button>
        </div>
      </div>
    </div>
  )
}
