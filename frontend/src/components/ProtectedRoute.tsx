import { Navigate, Outlet, useLocation } from 'react-router-dom'
import { useAuth } from '../lib/auth-context'
import { loginPath } from '../lib/authRedirect'

export default function ProtectedRoute() {
  const { token } = useAuth()
  const location = useLocation()

  if (token) {
    return <Outlet />
  }

  const next = `${location.pathname}${location.search}${location.hash}`

  return <Navigate to={loginPath(next)} replace />
}
