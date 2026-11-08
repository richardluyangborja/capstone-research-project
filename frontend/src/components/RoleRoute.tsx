import type { ReactNode } from 'react'
import { useAuth } from '../lib/auth-context'
import { hasAnyRole } from '../lib/permissions'
import type { Role } from '../types'
import Forbidden from '../pages/Forbidden'

interface RoleRouteProps {
  allow: readonly Role[]
  children: ReactNode
}

/**
 * Role guard for the administration plane (spec 19 S1, ADR-0030).
 *
 * `ProtectedRoute` already proves there is a session; this guard proves the session is allowed to
 * see the route. An unauthorised role gets the application's 403 surface, never the page content.
 */
export default function RoleRoute({ allow, children }: RoleRouteProps) {
  const { user } = useAuth()

  if (!hasAnyRole(user?.role, allow)) {
    return <Forbidden />
  }

  return <>{children}</>
}
