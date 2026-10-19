import { useState } from 'react'
import { toDateTimeLocal } from '../../lib/format'

interface RescheduleDialogProps {
  subject: string
  dueAt: string
  busy?: boolean
  onConfirm: (dueAt: string) => void
  onCancel: () => void
}

/**
 * Move a follow-up's due moment (module 04, spec 12 §UI). A small prompt rather than a page:
 * rescheduling is the most common action on the worklist, and it also re-arms the reminder
 * (rule 3). Shared by the worklist row and the task page.
 */
export default function RescheduleDialog({
  subject,
  dueAt,
  busy = false,
  onConfirm,
  onCancel,
}: RescheduleDialogProps) {
  const [value, setValue] = useState(toDateTimeLocal(dueAt))

  return (
    <div
      role="dialog"
      aria-modal="true"
      aria-label="Reschedule follow-up"
      className="fixed inset-0 z-50 flex items-center justify-center bg-foreground/40 p-4"
    >
      <div className="w-full max-w-sm rounded-lg border border-border bg-card p-5">
        <h2 className="text-sm font-semibold text-foreground">Reschedule follow-up</h2>
        <p className="mt-1 text-sm text-muted-foreground">When is “{subject}” owed now?</p>

        <label className="mt-4 block text-sm font-medium text-foreground">
          Due
          <input
            type="datetime-local"
            aria-label="New due moment"
            value={value}
            onChange={(event) => setValue(event.target.value)}
            className="mt-1 w-full rounded-md border border-input bg-card px-3 py-2 text-sm text-foreground outline-none focus:ring-2 focus:ring-ring"
          />
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
            disabled={busy || value === ''}
            onClick={() => onConfirm(new Date(value).toISOString())}
            className="rounded-md bg-primary px-3 py-2 text-sm font-medium text-primary-foreground disabled:opacity-60"
          >
            {busy ? 'Saving…' : 'Reschedule'}
          </button>
        </div>
      </div>
    </div>
  )
}
