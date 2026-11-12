import { Link } from 'react-router-dom'
import { ArrowRight, MonitorCog, ShieldCheck } from 'lucide-react'
import PageHeader from '../../components/common/PageHeader'
import { useAuth } from '../../lib/auth-context'

/**
 * The platform-operations landing page (spec 19 S1). Only a superadmin reaches this route; the
 * page links to the bounded diagnostic surface delivered by S8.
 */
export default function PlatformHome() {
  const { user } = useAuth()

  return (
    <div className="flex flex-col gap-6">
      <PageHeader
        title="Platform operations"
        description="Bounded, read-only platform diagnostics and security-sensitive settings."
      />

      <section className="rounded-lg border border-border bg-card p-6">
        <div className="flex items-center gap-2">
          <ShieldCheck className="h-5 w-5 text-counts" aria-hidden />
          <h3 className="text-sm font-semibold text-foreground">Superadmin surface</h3>
        </div>
        <p className="mt-2 max-w-2xl text-sm text-muted-foreground">
          Signed in as <span className="font-medium text-foreground">{user?.name}</span>. This area
          is intentionally narrower than application administration: it exposes fixed, allow-listed
          diagnostics, never arbitrary SQL, credentials, migrations, backups or destructive
          operations.
        </p>
      </section>

      <Link
        to="/platform/diagnostics"
        className="group flex items-center justify-between gap-4 rounded-lg border border-border bg-card p-4 transition-colors hover:border-primary/50 hover:bg-secondary/40"
      >
        <div className="flex items-start gap-3">
          <MonitorCog className="mt-0.5 h-5 w-5 text-activity" aria-hidden />
          <div>
            <p className="text-sm font-semibold text-foreground">Diagnostics</p>
            <p className="mt-1 text-xs text-muted-foreground">
              Read-only runtime, storage, failed work and allow-listed record totals. Every
              view is audited.
            </p>
          </div>
        </div>
        <ArrowRight
          className="h-4 w-4 text-muted-foreground group-hover:translate-x-0.5"
          aria-hidden
        />
      </Link>
    </div>
  )
}
