const DEFAULT_AFTER_LOGIN = '/dashboard'

/**
 * Validate a post-login destination (spec 16 §Sign-in modal). Only same-origin, internal paths
 * are accepted, so `next` cannot become an open redirect. A path back to the sign-in surface is
 * rejected too, otherwise the modal would reopen immediately after a successful login.
 */
export function safeNextPath(value: string | null | undefined): string | null {
  if (!value || !value.startsWith('/') || /^\/[\\/]/.test(value)) {
    return null
  }

  try {
    const url = new URL(value, window.location.origin)

    if (url.origin !== window.location.origin) {
      return null
    }

    if (url.pathname === '/login' || (url.pathname === '/' && url.searchParams.has('login'))) {
      return null
    }

    return `${url.pathname}${url.search}${url.hash}`
  } catch {
    return null
  }
}

/** Where a successful sign-in goes when no valid `next` was supplied. */
export function afterLoginPath(next: string | null | undefined): string {
  return safeNextPath(next) ?? DEFAULT_AFTER_LOGIN
}

/** The linkable public sign-in surface: `/?login=1[&next=…]`. */
export function loginPath(next?: string | null): string {
  const params = new URLSearchParams({ login: '1' })
  const safeNext = safeNextPath(next)

  if (safeNext) {
    params.set('next', safeNext)
  }

  return `/?${params.toString()}`
}
