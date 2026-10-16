import { Bell, Moon, Sun } from 'lucide-react'

interface TopbarProps {
  title?: string
}

export default function Topbar({ title }: TopbarProps) {
  const toggleTheme = () => {
    document.documentElement.classList.toggle('dark')
  }

  return (
    <header className="flex h-14 items-center justify-between border-b border-border bg-card px-4">
      <h1 className="text-base font-semibold text-foreground">{title ?? 'Dashboard'}</h1>
      <div className="flex items-center gap-1">
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
      </div>
    </header>
  )
}
