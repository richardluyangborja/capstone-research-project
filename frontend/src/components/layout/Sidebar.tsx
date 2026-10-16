import { NavLink } from 'react-router-dom'
import { Briefcase, Building2, Contact, LayoutDashboard, Users } from 'lucide-react'

const links = [
  { to: '/dashboard', label: 'Dashboard', icon: LayoutDashboard },
  { to: '/leads', label: 'Leads', icon: Users },
  { to: '/companies', label: 'Companies', icon: Building2 },
  { to: '/contacts', label: 'Contacts', icon: Contact },
  { to: '/opportunities', label: 'Opportunities', icon: Briefcase },
]

export default function Sidebar() {
  return (
    <aside className="flex w-60 shrink-0 flex-col border-r border-border bg-sidebar px-3 py-4">
      <div className="px-2 pb-4 text-lg font-semibold text-foreground">Primepower</div>
      <nav className="flex flex-col gap-1">
        {links.map(({ to, label, icon: Icon }) => (
          <NavLink
            key={to}
            to={to}
            className={({ isActive }) =>
              `flex items-center gap-3 rounded-md px-3 py-2 text-sm transition-colors ${
                isActive
                  ? 'bg-primary text-primary-foreground'
                  : 'text-muted-foreground hover:bg-muted hover:text-foreground'
              }`
            }
          >
            <Icon className="h-4 w-4" aria-hidden="true" />
            {label}
          </NavLink>
        ))}
      </nav>
    </aside>
  )
}
