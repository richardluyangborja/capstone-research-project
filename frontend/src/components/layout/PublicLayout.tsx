import { Link, Outlet } from 'react-router-dom'

export default function PublicLayout() {
  return (
    <div className="flex min-h-full flex-col bg-background">
      <header className="border-b border-border bg-card">
        <div className="mx-auto flex h-16 max-w-5xl items-center justify-between px-4">
          <Link to="/" className="text-lg font-semibold text-foreground">
            Primepower
          </Link>
          <nav className="flex items-center gap-4 text-sm text-muted-foreground">
            <Link to="/" className="hover:text-foreground">
              Home
            </Link>
          </nav>
        </div>
      </header>
      <main className="mx-auto w-full max-w-5xl flex-1 px-4 py-10">
        <Outlet />
      </main>
      <footer className="border-t border-border py-6 text-center text-xs text-muted-foreground">
        © {new Date().getFullYear()} Primepower Manpower Service
      </footer>
    </div>
  )
}
