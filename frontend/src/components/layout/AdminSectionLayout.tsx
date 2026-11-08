import type { ReactNode } from 'react'
import { NavLink } from 'react-router-dom'
import type { AdminArea } from '../../types'

interface AdminSectionLayoutProps {
  area: AdminArea
  children: ReactNode
}

const applicationItems = [
  { to: '/admin', label: 'Overview', end: true },
  { to: '/admin/users', label: 'Users' },
  { to: '/admin/teams', label: 'Teams' },
  { to: '/admin/settings', label: 'Settings' },
  { to: '/admin/audit', label: 'Audit log' },
  { to: '/admin/operations', label: 'Operations' },
]

const platformItems = [
  { to: '/platform', label: 'Overview', end: true },
  { to: '/platform/diagnostics', label: 'Diagnostics' },
]

/**
 * The shared sub-navigation for the administration plane (spec 19 S1). It sits inside the one
 * `DashboardShell`, so administration is a role-scoped area of the same product rather than a
 * second application with its own layout.
 */
export default function AdminSectionLayout({ area, children }: AdminSectionLayoutProps) {
  const items = area === 'application' ? applicationItems : platformItems
  const label = area === 'application' ? 'Application administration' : 'Platform operations'

  return (
    <div className="flex flex-col gap-6">
      <nav
        aria-label={label}
        className="flex flex-wrap items-center gap-1 rounded-lg border border-border bg-card p-1"
      >
        {items.map((item) => (
          <NavLink
            key={item.to}
            to={item.to}
            end={item.end}
            className={({ isActive }) =>
              `rounded-md px-3 py-1.5 text-xs font-medium transition-colors ${
                isActive
                  ? 'bg-secondary text-primary'
                  : 'text-muted-foreground hover:bg-muted hover:text-foreground'
              }`
            }
          >
            {item.label}
          </NavLink>
        ))}
      </nav>
      {children}
    </div>
  )
}
