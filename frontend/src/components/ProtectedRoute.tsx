import { Navigate, Outlet } from 'react-router-dom'
import { useAuth } from '../lib/auth-context'

export default function ProtectedRoute() {
  const { token } = useAuth()

  return token ? <Outlet /> : <Navigate to="/login" replace />
}
