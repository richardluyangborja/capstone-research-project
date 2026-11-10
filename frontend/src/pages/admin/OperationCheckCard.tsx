import { RefreshCw } from 'lucide-react'
import StatusBadge from '../../components/common/StatusBadge'
import { formatDateTime } from '../../lib/format'
import { operationSourceLabel, operationStatusMeta } from '../../lib/operationsMeta'
import type { OperationCheck } from '../../types'

interface OperationCheckCardProps {
  check: OperationCheck
}

/**
 * One dependency check card (module 08 slice S6, spec 19 §UI and acceptance).
 *
 * It shows the status as accessible text (a badge plus a status word, never colour alone), the
 * last-checked time in the business timezone, the safe summary and — only when the check has
 * one — the remediation an operator should try. A `mocked` source is labelled as such so a
 * simulated feed is never read as a live department connection.
 */
export default function OperationCheckCard({ check }: OperationCheckCardProps) {
  const status = operationStatusMeta[check.status]

  return (
    <div
      className="flex flex-col gap-3 rounded-lg border border-border bg-card p-4"
      data-check={check.key}
    >
      <div className="flex items-start justify-between gap-2">
        <div>
          <p className="text-sm font-medium text-foreground">{check.label}</p>
          <p className="text-xs text-muted-foreground">
            Last checked {formatDateTime(check.checked_at)}
          </p>
        </div>
        <StatusBadge tone={status.tone}>{status.label}</StatusBadge>
      </div>

      <p className="text-sm text-muted-foreground">{check.summary}</p>

      {check.remediation && (
        <div className="flex gap-2 rounded-md bg-muted px-3 py-2 text-xs text-muted-foreground">
          <RefreshCw className="mt-0.5 h-3.5 w-3.5 shrink-0 text-cost" aria-hidden />
          <span>
            <span className="font-medium text-foreground">Next step: </span>
            {check.remediation}
          </span>
        </div>
      )}

      <div className="mt-auto flex items-center justify-between gap-2 border-t border-border pt-2">
        <span className="text-xs text-muted-foreground">
          Source: {operationSourceLabel[check.source]}
        </span>
        <span className="sr-only">
          Status: {status.label}. {status.description}
        </span>
      </div>
    </div>
  )
}
