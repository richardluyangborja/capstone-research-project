import { Outlet, useLocation } from 'react-router-dom'
import type { Role } from '../../types'
import Sidebar from './Sidebar'
import Topbar from './Topbar'

interface DashboardShellProps {
  role?: Role
  title?: string
}

/**
 * The single dashboard shell for every role (design system rule).
 * Roles are differentiated by the `role` prop — never build per-role layouts.
 */
export default function DashboardShell({ role = 'salesrep', title }: DashboardShellProps) {
  const { pathname } = useLocation()

  return (
    <div className="flex h-full">
      <Sidebar />
      <div className="flex min-w-0 flex-1 flex-col">
        <Topbar role={role} title={title ?? titleFromPath(pathname)} />
        <main className="flex-1 overflow-auto bg-background p-6">
          <Outlet />
        </main>
      </div>
    </div>
  )
}

function titleFromPath(pathname: string): string {
  const segment = pathname.split('/').filter(Boolean).at(-1)
  if (!segment || segment === 'dashboard') return 'Dashboard'
  return segment.charAt(0).toUpperCase() + segment.slice(1)
}
