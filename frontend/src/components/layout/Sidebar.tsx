import { useState } from 'react'
import { NavLink } from 'react-router-dom'
import {
  Building2,
  ChevronDown,
  ClipboardCheck,
  Handshake,
  History,
  Inbox,
  LayoutDashboard,
  LogOut,
  UserCheck,
  UserPlus,
  Users,
  type LucideIcon,
} from 'lucide-react'
import { useAuth } from '../../lib/auth-context'
import type { Role } from '../../types'

interface NavItem {
  to: string
  label: string
  icon: LucideIcon
}

interface NavGroup {
  label: string
  items: NavItem[]
}

// The CRM module map (resources/system-modules.md). Only **implemented** submodules are listed;
// add new entries as their specs are built (resources/specs/).
const primaryItems: NavItem[] = [{ to: '/dashboard', label: 'Dashboard', icon: LayoutDashboard }]

const groups: NavGroup[] = [
  {
    label: 'Lead & Client Tracking',
    items: [
      { to: '/leads', label: 'Lead Capture & Intake', icon: Inbox },
      { to: '/qualification', label: 'Lead Qualification & Scoring', icon: ClipboardCheck },
      { to: '/assignment', label: 'Lead Assignment & Ownership', icon: UserCheck },
      { to: '/lifecycle', label: 'Lead Lifecycle & Status', icon: History },
      { to: '/companies', label: 'Company / Account Management', icon: Building2 },
      { to: '/contacts', label: 'Contact Management', icon: Users },
      { to: '/conversion', label: 'Lead-to-Client Conversion', icon: UserPlus },
    ],
  },
]

export default function Sidebar({ role }: { role: Role }) {
  const { user, logout } = useAuth()

  return (
    <aside className="flex w-64 shrink-0 flex-col border-r border-border bg-sidebar">
      <div className="flex items-center gap-3 border-b border-border px-4 py-4">
        <span className="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-primary text-primary-foreground">
          <Handshake className="h-5 w-5" aria-hidden="true" />
        </span>
        <div className="min-w-0">
          <p className="text-sm font-semibold leading-tight text-foreground">
            <span className="text-logo-brand">Primepower</span> Manpower Services
          </p>
          <p className="mt-0.5 text-xs leading-tight text-muted-foreground">
            Customer Relationship Management
          </p>
        </div>
      </div>

      <nav className="flex-1 overflow-y-auto px-3 py-4">
        <ul className="flex flex-col gap-1">
          {primaryItems.map((item) => (
            <NavRow key={item.to} item={item} end />
          ))}
        </ul>

        {groups.map((group) => (
          <NavGroupSection key={group.label} group={group} />
        ))}
      </nav>

      <div className="border-t border-border p-3">
        <div className="flex items-center gap-3 rounded-md px-2 py-2">
          <span className="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-primary text-xs font-semibold text-primary-foreground">
            {initials(user?.name)}
          </span>
          <div className="min-w-0 flex-1">
            <p className="truncate text-sm font-medium text-foreground">
              {user?.name ?? 'Signed out'}
            </p>
            <p className="truncate text-xs text-muted-foreground">{user?.email ?? ''}</p>
          </div>
          <span className="shrink-0 rounded bg-secondary px-1.5 py-0.5 text-xs font-medium uppercase text-secondary-foreground">
            {role}
          </span>
          <button
            type="button"
            onClick={() => void logout()}
            aria-label="Log out"
            title="Log out"
            className="shrink-0 rounded-md p-2 text-muted-foreground hover:bg-muted hover:text-foreground"
          >
            <LogOut className="h-4 w-4" aria-hidden="true" />
          </button>
        </div>
      </div>
    </aside>
  )
}

function NavGroupSection({ group }: { group: NavGroup }) {
  const [open, setOpen] = useState(true)

  return (
    <div className="mt-5">
      <button
        type="button"
        onClick={() => setOpen((value) => !value)}
        aria-expanded={open}
        className="flex w-full items-center justify-between px-2 pb-1 text-xs font-semibold uppercase tracking-wide text-muted-foreground hover:text-foreground"
      >
        {group.label}
        <ChevronDown
          className={`h-3.5 w-3.5 transition-transform ${open ? '' : '-rotate-90'}`}
          aria-hidden="true"
        />
      </button>

      {open && (
        <ul className="mt-1 flex flex-col gap-1">
          {group.items.map((item) => (
            <NavRow key={item.to} item={item} />
          ))}
        </ul>
      )}
    </div>
  )
}

function NavRow({ item, end }: { item: NavItem; end?: boolean }) {
  const Icon = item.icon

  return (
    <li>
      <NavLink
        to={item.to}
        end={end}
        className={({ isActive }) =>
          `relative flex items-center gap-3 rounded-md px-3 py-2 text-sm transition-colors ${
            isActive
              ? 'bg-secondary font-medium text-primary'
              : 'text-muted-foreground hover:bg-muted hover:text-foreground'
          }`
        }
      >
        {({ isActive }) => (
          <>
            {isActive && (
              <span
                className="absolute left-0 top-1/2 h-5 w-1 -translate-y-1/2 rounded-r bg-primary"
                aria-hidden="true"
              />
            )}
            <Icon className="h-4 w-4 shrink-0" aria-hidden="true" />
            <span className="truncate">{item.label}</span>
          </>
        )}
      </NavLink>
    </li>
  )
}

function initials(name?: string): string {
  if (!name) return '?'
  return name
    .split(' ')
    .filter(Boolean)
    .slice(0, 2)
    .map((part) => part[0]?.toUpperCase() ?? '')
    .join('')
}
