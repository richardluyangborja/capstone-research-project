import { useState } from 'react'
import { BadgeCheck, ShieldAlert } from 'lucide-react'
import StatusBadge from '../common/StatusBadge'
import EmptyState from '../common/EmptyState'
import { formatDateTime } from '../../lib/format'
import type { EmployeeDiscrepancy } from '../../lib/employees'

interface EmployeeDiscrepancyPanelProps {
  discrepancies: EmployeeDiscrepancy[]
  canResolve: boolean
  /** The flag currently being settled, if any. */
  busyId: string | null
  onResolve: (id: string, note?: string) => void
}

/**
 * The employee's escalation trail (module 09 slice T4, spec 21 §T4, ADR-0033).
 *
 * Open flags carry the amber **Under Review** pill; a manager or admin settles one here and the row
 * stays as history. The CRM records what was disputed and what was done about it — it never edits
 * Core 2, so this panel has no figure to change.
 */
export default function EmployeeDiscrepancyPanel({
  discrepancies,
  canResolve,
  busyId,
  onResolve,
}: EmployeeDiscrepancyPanelProps) {
  const [notes, setNotes] = useState<Record<string, string>>({})
  const open = discrepancies.filter((item) => item.status === 'open')

  return (
    <section className="space-y-4 rounded-lg border border-border bg-card p-4" aria-label="Discrepancies under review">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div className="flex items-center gap-2">
          <ShieldAlert className="h-4 w-4 text-muted-foreground" aria-hidden="true" />
          <h3 className="font-semibold text-foreground">Discrepancies</h3>
        </div>
        <p className="text-xs text-muted-foreground">
          {open.length === 0 ? 'Nothing under review' : `${open.length} under review`}
          {discrepancies.length > 0 ? ` · ${discrepancies.length} raised in total` : ''}
        </p>
      </div>

      {discrepancies.length === 0 ? (
        <EmptyState
          title="No discrepancies reported"
          description="Attendance, leave-balance and commission questions raised here are reviewed by the employee's manager."
        />
      ) : (
        <ul className="space-y-3">
          {discrepancies.map((discrepancy) => (
            <li key={discrepancy.id} className="rounded-md border border-border p-3">
              <div className="flex flex-wrap items-start justify-between gap-2">
                <div>
                  <p className="text-sm font-medium text-foreground">{discrepancy.category_label}</p>
                  <p className="mt-0.5 text-xs text-muted-foreground">
                    {discrepancy.raised_by?.name ?? 'An employee'}
                    {discrepancy.raised_at ? ` · ${formatDateTime(discrepancy.raised_at)}` : ''}
                    {discrepancy.subject ? ` · ${discrepancy.subject.label}` : ''}
                  </p>
                </div>
                <StatusBadge tone={discrepancy.status === 'open' ? 'warm' : 'success'}>
                  {discrepancy.status_label}
                </StatusBadge>
              </div>

              <p className="mt-2 text-sm text-muted-foreground">{discrepancy.explanation}</p>

              {discrepancy.resolved ? (
                <p className="mt-2 flex items-center gap-2 text-xs text-muted-foreground">
                  <BadgeCheck className="h-3.5 w-3.5" aria-hidden="true" />
                  Resolved{discrepancy.resolved.by ? ` by ${discrepancy.resolved.by.name}` : ''}
                  {discrepancy.resolved.note ? ` — ${discrepancy.resolved.note}` : ''}
                </p>
              ) : canResolve ? (
                <div className="mt-3 space-y-2 border-t border-border pt-3">
                  <label className="block text-xs font-medium text-foreground">
                    Resolution note <span className="font-normal text-muted-foreground">(optional)</span>
                    <textarea
                      rows={2}
                      value={notes[discrepancy.id] ?? ''}
                      onChange={(event) => setNotes((current) => ({ ...current, [discrepancy.id]: event.target.value }))}
                      placeholder="What did HR or payroll confirm?"
                      className="mt-1 w-full rounded-md border border-input bg-card px-3 py-2 text-sm text-foreground outline-none focus:ring-2 focus:ring-ring"
                    />
                  </label>
                  <button
                    type="button"
                    disabled={busyId === discrepancy.id}
                    onClick={() => {
                      const note = (notes[discrepancy.id] ?? '').trim()
                      onResolve(discrepancy.id, note === '' ? undefined : note)
                    }}
                    className="rounded-md bg-primary px-3 py-1.5 text-sm font-medium text-primary-foreground disabled:opacity-60"
                  >
                    {busyId === discrepancy.id ? 'Resolving…' : 'Mark resolved'}
                  </button>
                </div>
              ) : (
                <p className="mt-2 text-xs text-muted-foreground">Awaiting review by the employee's manager.</p>
              )}
            </li>
          ))}
        </ul>
      )}
    </section>
  )
}
