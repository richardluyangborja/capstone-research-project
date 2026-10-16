import { Bell, LogOut, Moon, Sun } from 'lucide-react'
import { useAuth } from '../../lib/auth-context'
import type { Role } from '../../types'

interface TopbarProps {
  title?: string
  role: Role
}

export default function Topbar({ title, role }: TopbarProps) {
  const { user, logout } = useAuth()

  const toggleTheme = () => {
    document.documentElement.classList.toggle('dark')
  }

  return (
    <header className="flex h-14 items-center justify-between border-b border-border bg-card px-4">
      <h1 className="text-base font-semibold text-foreground">{title ?? 'Dashboard'}</h1>
      <div className="flex items-center gap-3">
        {user && <span className="hidden text-sm text-muted-foreground sm:inline">{user.name}</span>}
        <span className="rounded-full bg-secondary px-3 py-1 text-xs font-medium capitalize text-secondary-foreground">
          {role}
        </span>
        <button
          type="button"
          onClick={toggleTheme}
          aria-label="Toggle theme"
          className="rounded-md p-2 text-muted-foreground hover:bg-muted hover:text-foreground"
        >
          <Sun className="h-4 w-4 dark:hidden" aria-hidden="true" />
          <Moon className="hidden h-4 w-4 dark:block" aria-hidden="true" />
        </button>
        <button
          type="button"
          aria-label="Notifications"
          className="rounded-md p-2 text-muted-foreground hover:bg-muted hover:text-foreground"
        >
          <Bell className="h-4 w-4" aria-hidden="true" />
        </button>
        <button
          type="button"
          onClick={() => void logout()}
          aria-label="Log out"
          className="rounded-md p-2 text-muted-foreground hover:bg-muted hover:text-foreground"
        >
          <LogOut className="h-4 w-4" aria-hidden="true" />
        </button>
      </div>
    </header>
  )
}
