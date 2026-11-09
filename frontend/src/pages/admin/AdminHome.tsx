import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import {
  Activity,
  ArrowRight,
  ScrollText,
  Settings,
  ShieldCheck,
  UserCog,
  UsersRound,
} from 'lucide-react'
import PageHeader from '../../components/common/PageHeader'
import EmptyState from '../../components/common/EmptyState'
import { useAuth } from '../../lib/auth-context'
import { getAdminAccess } from '../../lib/admin'
import type { AdminAccess } from '../../types'

const applicationLinks = [
  {
    to: '/admin/users',
    label: 'Users',
    description: 'Accounts, roles, activation and invitations.',
    icon: UserCog,
  },
  {
    to: '/admin/teams',
    label: 'Teams',
    description: 'Team managers and membership.',
    icon: UsersRound,
  },
  {
    to: '/admin/settings',
    label: 'Settings',
    description: 'Typed CRM and organization settings.',
    icon: Settings,
  },
  {
    to: '/admin/audit',
    label: 'Audit log',
    description: 'Immutable administrative history.',
    icon: ScrollText,
  },
  {
    to: '/admin/operations',
    label: 'Operations',
    description: 'Application and integration health.',
    icon: Activity,
  },
]

/**
 * The application-administration landing page (spec 19 S1).
 *
 * It is a shell, not a dashboard: the cards link to the routes that exist, and each route names
 * the slice that will fill it. No control here pretends an API already exists.
 */
export default function AdminHome() {
  const { user } = useAuth()
  const [access, setAccess] = useState<AdminAccess | null>(null)
  const [error, setError] = useState('')

  useEffect(() => {
    let cancelled = false

    void getAdminAccess()
      .then((next) => {
        if (!cancelled) setAccess(next)
      })
      .catch(() => {
        if (!cancelled) setError('Could not verify the administration capability.')
      })

    return () => {
      cancelled = true
    }
  }, [])

  return (
    <div className="flex flex-col gap-6">
      <PageHeader
        title="Administration"
        description="Application operations for users, teams, settings, audit and health."
      />

      <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
        <section className="rounded-lg border border-border bg-card p-4">
          <div className="flex items-center gap-2">
            <ShieldCheck className="h-4 w-4 text-counts" aria-hidden />
            <h3 className="text-sm font-semibold text-foreground">Application administration</h3>
          </div>
          <p className="mt-1 text-sm text-muted-foreground">
            Signed in as <span className="font-medium text-foreground">{user?.name}</span>.
            {error
              ? ` ${error}`
              : access
                ? ' The application plane is available to your account.'
                : ' Checking your capability…'}
          </p>
        </section>

        <section className="rounded-lg border border-border bg-card p-4">
          <div className="flex items-center gap-2">
            <Activity className="h-4 w-4 text-activity" aria-hidden />
            <h3 className="text-sm font-semibold text-foreground">Platform operations</h3>
          </div>
          <p className="mt-1 text-sm text-muted-foreground">
            {access?.platform
              ? 'Platform diagnostics are available from the platform group.'
              : 'Bounded platform diagnostics are reserved for superadmins.'}
          </p>
          {access?.platform && (
            <Link
              to="/platform"
              className="mt-3 inline-flex items-center gap-1 text-xs font-semibold text-primary hover:underline"
            >
              Open platform operations
              <ArrowRight className="h-3.5 w-3.5" aria-hidden />
            </Link>
          )}
        </section>
      </div>

      <section aria-labelledby="admin-sections-heading">
        <h3 id="admin-sections-heading" className="text-sm font-semibold text-foreground">
          Administration areas
        </h3>
        <div className="mt-3 grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-3">
          {applicationLinks.map((item) => {
            const Icon = item.icon
            return (
              <Link
                key={item.to}
                to={item.to}
                className="group rounded-lg border border-border bg-card p-4 transition-colors hover:border-primary/50 hover:bg-secondary/40"
              >
                <div className="flex items-start justify-between gap-3">
                  <Icon className="h-5 w-5 text-counts" aria-hidden />
                  <ArrowRight
                    className="h-4 w-4 text-muted-foreground transition-transform group-hover:translate-x-0.5"
                    aria-hidden
                  />
                </div>
                <p className="mt-3 text-sm font-semibold text-foreground">{item.label}</p>
                <p className="mt-1 text-xs text-muted-foreground">{item.description}</p>
              </Link>
            )
          })}
        </div>
      </section>

      <EmptyState
        title="The shell is ready"
        description="The routes and role boundary are in place. User management is live; teams arrive with S3, settings with S4, audit with S5 and operations with S6."
      />
    </div>
  )
}
