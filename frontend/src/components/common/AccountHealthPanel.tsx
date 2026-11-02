import { AlertTriangle, CheckCircle2, Info, ShieldAlert } from 'lucide-react'
import { Link } from 'react-router-dom'
import type { CompanyHealth, CompanyHealthSeverity } from '../../types'
import { healthSeverityMeta, healthStateMeta } from '../../lib/health'
import StatusBadge from './StatusBadge'

const severityClass: Record<CompanyHealthSeverity, string> = {
  critical: 'text-destructive',
  warning: 'text-cost',
  info: 'text-counts',
}

const severitySurface: Record<CompanyHealthSeverity, string> = {
  critical: 'border-destructive/20 bg-destructive/5',
  warning: 'border-cost/20 bg-cost/5',
  info: 'border-counts/20 bg-counts/5',
}

function SeverityIcon({ severity }: { severity: CompanyHealthSeverity }) {
  const className = `mt-0.5 h-4 w-4 shrink-0 ${severityClass[severity]}`
  if (severity === 'critical') return <ShieldAlert className={className} aria-hidden="true" />
  if (severity === 'warning') return <AlertTriangle className={className} aria-hidden="true" />
  return <Info className={className} aria-hidden="true" />
}

interface AccountHealthPanelProps {
  health: CompanyHealth
}

/**
 * Explainable account health (module 01 refresh S13, spec 16 §Account health).
 * Flags render the server's reason and source verbatim; the client never infers a flag.
 */
export default function AccountHealthPanel({ health }: AccountHealthPanelProps) {
  const state = healthStateMeta[health.state]

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div>
          <div className="flex flex-wrap items-center gap-2">
            <StatusBadge tone={state.tone}>{state.label}</StatusBadge>
            {health.flags.length > 0 && (
              <span className="text-xs text-muted-foreground">
                {health.flags.length} {health.flags.length === 1 ? 'flag' : 'flags'}
              </span>
            )}
          </div>
          <p className="mt-1 text-sm text-muted-foreground">{state.description}</p>
        </div>

        {health.suggested_action && (
          <Link
            to={health.suggested_action.url}
            className="inline-flex items-center gap-1 rounded-md border border-border px-2.5 py-1.5 text-xs font-medium text-primary hover:bg-muted"
          >
            {health.suggested_action.label}
          </Link>
        )}
      </div>

      {health.flags.length === 0 ? (
        <div className="flex items-center gap-2 rounded-md bg-people/10 px-3 py-2 text-sm text-people">
          <CheckCircle2 className="h-4 w-4" aria-hidden="true" />
          No flags on this account.
        </div>
      ) : (
        <ul className="space-y-2">
          {health.flags.map((flag) => {
            const severity = healthSeverityMeta[flag.severity]
            return (
              <li
                key={flag.code}
                className={`rounded-md border px-3 py-2.5 ${severitySurface[flag.severity]}`}
              >
                <div className="flex items-start justify-between gap-3">
                  <div className="flex min-w-0 items-start gap-2">
                    <SeverityIcon severity={flag.severity} />
                    <div className="min-w-0">
                      <div className="flex flex-wrap items-center gap-2">
                        <span className="text-sm font-medium text-foreground">{flag.title}</span>
                        <StatusBadge tone={severity.tone}>{severity.label}</StatusBadge>
                      </div>
                      <p className="mt-1 text-xs text-muted-foreground">{flag.reason}</p>
                      <p className="mt-1 text-xs text-muted-foreground">Source: {flag.source}</p>
                    </div>
                  </div>

                  {flag.action && (
                    <Link
                      to={flag.action.url}
                      className="shrink-0 rounded-md border border-border bg-card px-2 py-1 text-xs font-medium text-primary hover:bg-muted"
                    >
                      {flag.action.label}
                    </Link>
                  )}
                </div>
              </li>
            )
          })}
        </ul>
      )}

      {!health.can_view_financial_detail &&
        health.flags.some((flag) => flag.code === 'ar_overdue') && (
          <p className="text-xs text-muted-foreground">
            Finance amounts are hidden for your role. Ask your manager for the balance.
          </p>
        )}
    </div>
  )
}
