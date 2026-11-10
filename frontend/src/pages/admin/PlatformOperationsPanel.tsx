import StatusBadge from '../../components/common/StatusBadge'
import { entityTotalOrder, schemaStateLabel } from '../../lib/operationsMeta'
import type { OperationsPlatformSummary } from '../../types'

interface PlatformOperationsPanelProps {
  platform: OperationsPlatformSummary
}

/**
 * The platform-only summary (module 08 slice S6, spec 19 §Summary contract).
 *
 * Rendered only because the server sent it: an application admin's payload has `platform: null`,
 * so this component is never the authorization boundary. It shows build/runtime labels, a safe
 * schema state, bounded job and entity counts and the last day's security-event counts — never
 * an environment value, a credential, a token, a path or a raw table name supplied by a caller.
 */
export default function PlatformOperationsPanel({ platform }: PlatformOperationsPanelProps) {
  const schema = schemaStateLabel(platform.schema.state)

  return (
    <section className="rounded-lg border border-border bg-card p-4" data-panel="platform">
      <div className="mb-4 flex items-center justify-between gap-2">
        <div>
          <h3 className="text-sm font-semibold text-foreground">Platform diagnostics</h3>
          <p className="text-xs text-muted-foreground">
            Visible to platform operators only. Read-only; no maintenance runs from here.
          </p>
        </div>
        <StatusBadge tone="activity">Superadmin</StatusBadge>
      </div>

      <dl className="grid grid-cols-2 gap-3 text-sm sm:grid-cols-4">
        <div>
          <dt className="text-xs text-muted-foreground">Build</dt>
          <dd className="font-medium text-foreground">{platform.application.build}</dd>
        </div>
        <div>
          <dt className="text-xs text-muted-foreground">Runtime</dt>
          <dd className="font-medium text-foreground">{platform.application.runtime}</dd>
        </div>
        <div>
          <dt className="text-xs text-muted-foreground">Environment</dt>
          <dd className="font-medium text-foreground">{platform.application.environment}</dd>
        </div>
        <div>
          <dt className="text-xs text-muted-foreground">Schema</dt>
          <dd className="flex items-center gap-2">
            <StatusBadge tone={schema.tone}>{schema.label}</StatusBadge>
            {platform.schema.pending !== null && platform.schema.pending > 0 && (
              <span className="text-xs text-muted-foreground">
                {platform.schema.pending} pending
              </span>
            )}
          </dd>
        </div>
        <div>
          <dt className="text-xs text-muted-foreground">Queue driver</dt>
          <dd className="font-medium text-foreground">{platform.queue.driver}</dd>
        </div>
        <div>
          <dt className="text-xs text-muted-foreground">Failed jobs</dt>
          <dd className="font-medium text-foreground">
            {platform.queue.failed_jobs === null ? 'Not available' : platform.queue.failed_jobs}
          </dd>
        </div>
        <div>
          <dt className="text-xs text-muted-foreground">Security warnings</dt>
          <dd className="font-medium text-foreground">{platform.security_events.warning}</dd>
        </div>
        <div>
          <dt className="text-xs text-muted-foreground">Security critical</dt>
          <dd className="font-medium text-foreground">{platform.security_events.critical}</dd>
        </div>
      </dl>

      <div className="mt-5">
        <p className="mb-2 text-xs font-medium text-muted-foreground">Allow-listed record totals</p>
        <dl className="grid grid-cols-3 gap-3 text-sm sm:grid-cols-7">
          {entityTotalOrder.map(({ key, label }) => {
            const value = platform.entity_totals[key]

            return (
              <div key={key}>
                <dt className="text-xs text-muted-foreground">{label}</dt>
                <dd className="font-medium text-foreground">
                  {value === undefined || value === null ? '—' : value}
                </dd>
              </div>
            )
          })}
        </dl>
      </div>
    </section>
  )
}
