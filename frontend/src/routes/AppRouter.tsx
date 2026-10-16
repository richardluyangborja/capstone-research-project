import { Route, Routes } from 'react-router-dom'
import DashboardShell from '../components/layout/DashboardShell'
import PublicLayout from '../components/layout/PublicLayout'
import DashboardHome from '../pages/DashboardHome'
import NotFound from '../pages/NotFound'
import PublicHome from '../pages/PublicHome'

// CRM dashboard routes are added here as submodules are implemented (see resources/specs/).
export default function AppRouter() {
  return (
    <Routes>
      <Route element={<PublicLayout />}>
        <Route path="/" element={<PublicHome />} />
      </Route>

      <Route path="/dashboard" element={<DashboardShell role="salesrep" />}>
        <Route index element={<DashboardHome />} />
      </Route>

      <Route path="*" element={<NotFound />} />
    </Routes>
  )
}
