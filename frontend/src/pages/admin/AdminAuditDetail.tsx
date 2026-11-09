import { X } from 'lucide-react'
import AlertItem from '../../components/common/AlertItem'
import StatusBadge from '../../components/common/StatusBadge'
import { auditActionLabel, auditSeverityMeta } from '../../lib/auditMeta'
import { formatDateTime } from '../../lib/format'
import type { AuditLogEntry } from '../../types'

interface AdminAuditDetailProps {
  entry: AuditLogEntry
  loading: boolean
  error: string
  onClose: () => void
}

const snapshot = (value: Record<string, unknown> | null): string =>
  value === null ? 'No snapshot' : JSON.stringify(value, null, 2)

/**
 * The audit row's detail: the redacted before/after snapshots and the request metadata that
 * ties the event to a correlation id. The server has already removed secrets and masked
 * contact PII, so this panel renders the payload as-is.
 */
export default function AdminAuditDetail({
  entry,
  loading,
  error,
  onClose,
}: AdminAuditDetailProps) {
  return (
    <aside className="rounded-lg border border-border bg-card p-4" aria-label="Audit event detail">
      <div className="flex items-start justify-between gap-3">
        <div>
          <p className="text-sm font-semibold text-foreground">{auditActionLabel(entry.action)}</p>
          <p className="mt-0.5 font-mono text-xs text-muted-foreground">{entry.action}</p>
        </div>
        <button
          type="button"
          onClick={onClose}
          aria-label="Close detail"
          className="rounded-md p-1 text-muted-foreground hover:bg-muted hover:text-foreground"
        >
          <X className="h-4 w-4" aria-hidden />
        </button>
      </div>

      <div className="mt-3 flex flex-wrap items-center gap-2">
        <StatusBadge tone={auditSeverityMeta[entry.severity].tone}>
          {auditSeverityMeta[entry.severity].label}
        </StatusBadge>
        <span className="text-xs text-muted-foreground">{formatDateTime(entry.created_at)}</span>
      </div>

      {error && (
        <div className="mt-3">
          <AlertItem variant="danger">{error}</AlertItem>
        </div>
      )}

      <dl className="mt-4 space-y-3 text-sm">
        <div>
          <dt className="text-xs uppercase text-muted-foreground">Actor</dt>
          <dd className="text-foreground">
            {entry.actor ? `${entry.actor.name} · ${entry.actor.email}` : 'System'}
          </dd>
        </div>
        <div>
          <dt className="text-xs uppercase text-muted-foreground">Subject</dt>
          <dd className="text-foreground">
            {entry.subject
              ? [entry.subject.type ?? 'Record', entry.subject.name, entry.subject.id]
                  .filter(Boolean)
                  .join(' · ')
              : '—'}
          </dd>
        </div>
        <div>
          <dt className="text-xs uppercase text-muted-foreground">Request</dt>
          <dd className="font-mono text-xs text-foreground">
            {entry.request.method ?? '—'} {entry.request.path ?? ''}
            <br />
            {entry.request.ip ?? '—'} · {entry.request.id}
            {entry.request.user_agent && (
              <span className="mt-1 block break-all text-muted-foreground">
                {entry.request.user_agent}
              </span>
            )}
          </dd>
        </div>
      </dl>

      <div className="mt-4 grid grid-cols-1 gap-3">
        <div>
          <p className="text-xs uppercase text-muted-foreground">Before</p>
          <pre className="mt-1 max-h-48 overflow-auto rounded-md bg-muted p-2 text-xs text-foreground">
            {snapshot(entry.before)}
          </pre>
        </div>
        <div>
          <p className="text-xs uppercase text-muted-foreground">After</p>
          <pre className="mt-1 max-h-48 overflow-auto rounded-md bg-muted p-2 text-xs text-foreground">
            {snapshot(entry.after)}
          </pre>
        </div>
      </div>

      {loading && <p className="mt-3 text-xs text-muted-foreground">Refreshing detail…</p>}
    </aside>
  )
}
