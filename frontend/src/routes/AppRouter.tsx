import { Route, Routes } from 'react-router-dom'
import DashboardShell from '../components/layout/DashboardShell'
import PublicLayout from '../components/layout/PublicLayout'
import ProtectedRoute from '../components/ProtectedRoute'
import { useAuth } from '../lib/auth-context'
import NotFound from '../pages/NotFound'
import PublicHome from '../pages/PublicHome'
import PublicSurvey from '../pages/PublicSurvey'
import { dashboardRoutes } from './dashboardRoutes'
import {
  RedirectToAccount,
  RedirectToAccounts,
  RedirectToClientAccounts,
  RedirectToLeadView,
  RedirectToLogin,
  RedirectToNewAccount,
} from './redirects'

// CRM dashboard routes live in `dashboardRoutes.tsx` so the sidebar's reachability test can
// use the same inventory the router renders (spec 16 §Navigation and routes — S19).
export default function AppRouter() {
  return (
    <Routes>
      <Route element={<PublicLayout />}>
        <Route path="/" element={<PublicHome />} />
      </Route>

      <Route path="/login" element={<RedirectToLogin />} />

      {/* The client's survey page (module 05 slice S2): no session, no shell — the token in the
          link is the credential. */}
      <Route path="/survey/:token" element={<PublicSurvey />} />

      <Route element={<ProtectedRoute />}>
        {/* Module 01 route cleanup (spec 16 §Navigation and routes). The old paths stay alive
            so bookmarks and generated links keep working; they never render a page. */}
        <Route path="/companies" element={<RedirectToAccounts />} />
        <Route path="/companies/new" element={<RedirectToNewAccount />} />
        <Route path="/companies/:id" element={<RedirectToAccount />} />
        <Route path="/clients" element={<RedirectToClientAccounts />} />
        <Route path="/qualification" element={<RedirectToLeadView view="qualification" />} />
        <Route path="/assignment" element={<RedirectToLeadView view="unassigned" />} />
        <Route path="/lifecycle" element={<RedirectToLeadView view="aging" />} />
        <Route path="/conversion" element={<RedirectToLeadView view="conversion" />} />

        <Route element={<DashboardLayout />}>
          {dashboardRoutes.map((route) => (
            <Route key={route.path} path={route.path} element={route.element} />
          ))}
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
