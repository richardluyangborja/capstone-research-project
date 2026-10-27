import { useState } from 'react'
import { NavLink } from 'react-router-dom'
import {
  Building2,
  CalendarClock,
  ChevronDown,
  ClipboardList,
  FileText,
  Gauge,
  Inbox,
  LayoutDashboard,
  LogOut,
  MessageSquare,
  MessagesSquare,
  Send,
  Sparkles,
  SquareKanban,
  Target,
  UserPlus,
  Users,
  type LucideIcon,
} from 'lucide-react'
import logo from '../../assets/logo.png'
import { useAuth } from '../../lib/auth-context'
import type { Role } from '../../types'

interface NavItem {
  to: string
  label: string
  icon: LucideIcon
  /** Match the path exactly, for an entry that would otherwise stay lit on its own child. */
  end?: boolean
}

interface NavSection {
  label?: string
  items: NavItem[]
}

interface NavGroup {
  label: string
  sections: NavSection[]
}

// The CRM module map (resources/system-modules.md, spec 16 §Navigation and routes). Only
// **implemented** destinations are listed; later slices add their entries as their pages land,
// so the sidebar never carries a dead link.
const primaryItems: NavItem[] = [{ to: '/dashboard', label: 'Dashboard', icon: LayoutDashboard }]

const groups: NavGroup[] = [
  {
    label: 'Lead & Client Tracking',
    sections: [
      {
        label: 'Leads',
        items: [
          { to: '/leads', label: 'Lead Workspace', icon: Inbox, end: true },
          { to: '/leads/new', label: 'Capture Lead', icon: UserPlus },
        ],
      },
      {
        label: 'Accounts',
        items: [{ to: '/accounts', label: 'Account Book', icon: Building2, end: true }],
      },
      {
        label: 'Contacts',
        items: [{ to: '/contacts', label: 'Contact Book', icon: Users }],
      },
    ],
  },
  {
    // Module 02 (ADR-0014 §1). Submodules 3–5 (stage/outcome, activity & timeline, forecast)
    // live inside these two screens rather than as separate destinations.
    label: 'Opportunity Pipeline Visualization',
    sections: [
      {
        label: 'Pipeline',
        items: [
          { to: '/pipeline', label: 'Pipeline Board', icon: SquareKanban },
          { to: '/opportunities', label: 'Deal Book', icon: Target },
        ],
      },
    ],
  },
  {
    // Module 03 (ADR-0014 §1, ADR-0016). The log, its conversations and the reusable wording.
    label: 'Communication History Management',
    sections: [
      {
        label: 'History',
        items: [
          { to: '/communications', label: 'Log & History', icon: MessageSquare, end: true },
          { to: '/communications/threads', label: 'Conversations', icon: MessagesSquare },
        ],
      },
      {
        label: 'Templates',
        items: [{ to: '/communications/templates', label: 'Template Library', icon: FileText }],
      },
    ],
  },
  {
    // Module 04 (ADR-0014 §1, ADR-0018, ADR-0016). One worklist over tasks and scheduled sends.
    label: 'Follow-up Reminder System',
    sections: [
      {
        label: 'Worklist',
        items: [{ to: '/follow-ups', label: 'Follow-up Worklist', icon: CalendarClock }],
      },
    ],
  },
  {
    // Module 05 (ADR-0014 §1, ADR-0019, ADR-0016). The library and its sends, then the answers.
    label: 'Client Satisfaction & Survey',
    sections: [
      {
        label: 'Surveys',
        items: [
          { to: '/surveys', label: 'Survey Library', icon: ClipboardList },
          { to: '/survey-invitations', label: 'Invitations', icon: Send },
        ],
      },
      {
        label: 'Feedback',
        items: [
          { to: '/satisfaction', label: 'Satisfaction', icon: Gauge, end: true },
          { to: '/satisfaction/responses', label: 'Responses', icon: MessagesSquare },
        ],
      },
    ],
  },
  {
    // Module 07 (ADR-0021, spec 15). S1 owns the page and its rules variants; later AI
    // submodules join this group, never as dead links.
    label: 'AI Reports',
    sections: [{ items: [{ to: '/reports', label: 'Management Reports', icon: Sparkles }] }],
  },
]

export default function Sidebar({ role }: { role: Role }) {
  const { user, logout } = useAuth()

  return (
    <aside
      data-role={role}
      className="flex w-64 shrink-0 flex-col border-r border-border bg-sidebar"
    >
      <div className="flex items-center gap-3 border-b border-border px-4 py-4">
        <img src={logo} alt="" aria-hidden="true" className="h-10 w-auto shrink-0 object-contain" />
        <div className="min-w-0">
          <p className="text-sm font-semibold leading-tight text-logo-brand">
            Primepower Manpower Services
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
        className="flex w-full items-center justify-between px-2 pb-1 text-xs font-semibold uppercase tracking-wide text-primary hover:text-primary/80"
      >
        {group.label}
        <ChevronDown
          className={`h-3.5 w-3.5 transition-transform ${open ? '' : '-rotate-90'}`}
          aria-hidden="true"
        />
      </button>

      {open && (
        <div className="mt-1 flex flex-col">
          {group.sections.map((section, index) => (
            <div key={section.label ?? index} className={index > 0 ? 'mt-2' : ''}>
              {section.label && (
                <p className="px-2 pb-1 pt-1 text-xs font-medium text-primary">{section.label}</p>
              )}
              <ul className="flex flex-col gap-1">
                {section.items.map((item) => (
                  <NavRow key={item.to} item={item} end={item.end} />
                ))}
              </ul>
            </div>
          ))}
        </div>
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
