import { useCallback, useEffect } from 'react'
import { Link, Outlet, useNavigate, useSearchParams } from 'react-router-dom'
import logo from '../../assets/logo.png'
import LoginModal from '../auth/LoginModal'
import { useAuth } from '../../lib/auth-context'
import { afterLoginPath, loginPath } from '../../lib/authRedirect'

export default function PublicLayout() {
  const { user } = useAuth()
  const [searchParams, setSearchParams] = useSearchParams()
  const navigate = useNavigate()
  const loginOpen = searchParams.get('login') === '1'
  const next = searchParams.get('next')

  const closeLogin = useCallback(() => {
    setSearchParams(
      (current) => {
        const params = new URLSearchParams(current)
        params.delete('login')
        params.delete('next')
        return params
      },
      { replace: true },
    )
  }, [setSearchParams])

  useEffect(() => {
    if (user && loginOpen) {
      navigate(afterLoginPath(next), { replace: true })
    }
  }, [loginOpen, navigate, next, user])

  return (
    <div className="flex min-h-full flex-col bg-background">
      <a
        href="#main-content"
        className="sr-only focus:not-sr-only focus:absolute focus:left-4 focus:top-4 focus:z-50 focus:rounded-md focus:bg-primary focus:px-3 focus:py-2 focus:text-sm focus:font-medium focus:text-primary-foreground"
      >
        Skip to content
      </a>

      <header className="sticky top-0 z-30 border-b border-border bg-background/85 backdrop-blur">
        <div className="mx-auto flex h-16 max-w-6xl items-center justify-between gap-4 px-4">
          <Link to="/" className="flex min-w-0 items-center gap-2.5">
            <img
              src={logo}
              alt=""
              aria-hidden="true"
              className="h-9 w-auto shrink-0 object-contain"
            />
            <span className="flex min-w-0 flex-col leading-tight">
              <span className="truncate text-sm font-semibold tracking-wide text-logo-brand">
                PRIMEPOWER
              </span>
              <span className="truncate text-xs font-medium uppercase tracking-wide text-muted-foreground">
                Manpower Services
              </span>
            </span>
          </Link>

          <nav aria-label="Public" className="flex items-center gap-2 sm:gap-4">
            <a
              href="#request-staffing"
              className="hidden rounded-md px-2 py-1.5 text-sm font-medium text-muted-foreground hover:text-foreground sm:inline-block"
            >
              Request staffing
            </a>
            {user ? (
              <Link
                to="/dashboard"
                className="rounded-md bg-primary px-3 py-2 text-sm font-medium text-primary-foreground hover:bg-primary/90 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background"
              >
                Open dashboard
              </Link>
            ) : (
              <Link
                to={loginPath()}
                className="rounded-md border border-border bg-card px-3 py-2 text-sm font-medium text-foreground hover:bg-secondary focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background"
              >
                Sign in
              </Link>
            )}
          </nav>
        </div>
      </header>

      <main id="main-content" className="flex-1">
        <Outlet />
      </main>

      <footer className="border-t border-border bg-card">
        <div className="mx-auto grid max-w-6xl gap-8 px-4 py-10 md:grid-cols-[1.4fr_1fr_1fr]">
          <div>
            <p className="flex items-center gap-2 text-sm font-semibold text-logo-brand">
              <img src={logo} alt="" aria-hidden="true" className="h-7 w-auto object-contain" />
              Primepower Manpower Services
            </p>
            <p className="mt-3 max-w-sm text-sm text-muted-foreground">
              Recruitment, deployment and workforce visibility for Philippine businesses — built
              around one accountable team and one shared record.
            </p>
            <p className="mt-4 text-xs text-muted-foreground">
              © {new Date().getFullYear()} Primepower Manpower Services. All rights reserved.
            </p>
          </div>

          <div>
            <h2 className="text-xs font-semibold uppercase tracking-wide text-foreground">
              Talk to us
            </h2>
            <ul className="mt-3 flex flex-col gap-2 text-sm text-muted-foreground">
              <li>
                <a href="mailto:hello@primepower.com.ph" className="hover:text-foreground">
                  hello@primepower.com.ph
                </a>
              </li>
              <li>
                <a href="tel:+63288881000" className="hover:text-foreground">
                  +63 2 8888 1000
                </a>
              </li>
              <li>Metro Manila, Philippines</li>
            </ul>
          </div>

          <div>
            <h2 className="text-xs font-semibold uppercase tracking-wide text-foreground">
              Privacy
            </h2>
            <p className="mt-3 text-sm text-muted-foreground">
              We use the details you share only to respond to your staffing request. We do not sell
              or share your information. You can ask us to remove it at any time.
            </p>
          </div>
        </div>
      </footer>

      <LoginModal
        open={loginOpen && !user}
        next={next}
        onClose={closeLogin}
        onSuccess={(destination) => navigate(destination, { replace: true })}
      />
    </div>
  )
}
