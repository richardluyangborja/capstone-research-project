import { UserPlus } from 'lucide-react'
import type { AssignmentTarget, Lead } from '../../types'

interface LeadOwnerControlProps {
  lead: Lead
  targets: AssignmentTarget[]
  onClaim: () => void
  onAssign: (ownerId: number) => void
  busy?: boolean
}

// Shared owner control (submodule 03): "Claim" from the queue, or a reassign picker
// for managers/admins. Falls back to a read-only owner name when neither applies.
export default function LeadOwnerControl({
  lead,
  targets,
  onClaim,
  onAssign,
  busy = false,
}: LeadOwnerControlProps) {
  if (lead.can_claim) {
    return (
      <button
        type="button"
        disabled={busy}
        onClick={onClaim}
        className="inline-flex items-center gap-1.5 rounded-md border border-border px-2.5 py-1.5 text-xs font-medium text-foreground hover:bg-muted disabled:opacity-60"
      >
        <UserPlus className="h-3.5 w-3.5" aria-hidden="true" />
        Claim
      </button>
    )
  }

  if (!lead.can_reassign) {
    return <span className="text-muted-foreground">{lead.owner?.name ?? 'Unassigned'}</span>
  }

  const hasCurrent = lead.owner_id !== null && targets.some((target) => target.id === lead.owner_id)

  return (
    <select
      value={lead.owner_id ?? ''}
      disabled={busy}
      aria-label="Assign owner"
      onChange={(event) => {
        const next = Number(event.target.value)
        if (next && next !== lead.owner_id) onAssign(next)
      }}
      className="rounded-md border border-input bg-card px-2 py-1.5 text-xs text-foreground outline-none focus:ring-2 focus:ring-ring disabled:opacity-60"
    >
      <option value="" disabled>
        Assign to…
      </option>
      {!hasCurrent && lead.owner_id !== null && (
        <option value={lead.owner_id}>{lead.owner?.name ?? 'Current owner'}</option>
      )}
      {targets.map((target) => (
        <option key={target.id} value={target.id}>
          {target.name}
          {typeof target.open_leads_count === 'number' ? ` (${target.open_leads_count})` : ''}
        </option>
      ))}
    </select>
  )
}
