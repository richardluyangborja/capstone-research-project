import { Route, Routes } from 'react-router-dom'
import DashboardShell from '../components/layout/DashboardShell'
import PublicLayout from '../components/layout/PublicLayout'
import ProtectedRoute from '../components/ProtectedRoute'
import { useAuth } from '../lib/auth-context'
import Assignment from '../pages/Assignment'
import DashboardHome from '../pages/DashboardHome'
import LeadDetail from '../pages/LeadDetail'
import LeadsList from '../pages/LeadsList'
import Lifecycle from '../pages/Lifecycle'
import Login from '../pages/Login'
import NewLead from '../pages/NewLead'
import NotFound from '../pages/NotFound'
import PublicHome from '../pages/PublicHome'
import Qualification from '../pages/Qualification'

// CRM dashboard routes are added here as submodules are implemented (see resources/specs/).
export default function AppRouter() {
  return (
    <Routes>
      <Route element={<PublicLayout />}>
        <Route path="/" element={<PublicHome />} />
      </Route>

      <Route path="/login" element={<Login />} />

      <Route element={<ProtectedRoute />}>
        <Route element={<DashboardLayout />}>
          <Route path="/dashboard" element={<DashboardHome />} />
          <Route path="/leads" element={<LeadsList />} />
          <Route path="/leads/new" element={<NewLead />} />
          <Route path="/leads/:id" element={<LeadDetail />} />
          <Route path="/qualification" element={<Qualification />} />
          <Route path="/assignment" element={<Assignment />} />
          <Route path="/lifecycle" element={<Lifecycle />} />
        </Route>
      </Route>

      <Route path="*" element={<NotFound />} />
    </Routes>
  )
}

// Single shared shell for every role (design system rule) — role comes from the session.
function DashboardLayout() {
  const { user } = useAuth()

  return <DashboardShell role={user?.role ?? 'salesrep'} />
}
