import { Navigate, useLocation, useParams } from 'react-router-dom'

/**
 * Module 01 route cleanup (spec 16 §Navigation and routes).
 *
 * The old paths stay alive as redirects so bookmarks, seeded links and the report/dashboard
 * hrefs keep working. The query string is carried across; a saved view is set only when the
 * destination owns one.
 */
const withQuery = (
  pathname: string,
  search: string,
  updates: Record<string, string> = {},
): string => {
  if (Object.keys(updates).length === 0) {
    return `${pathname}${search}`
  }

  const params = new URLSearchParams(search)

  Object.entries(updates).forEach(([key, value]) => params.set(key, value))

  const query = params.toString()

  return query ? `${pathname}?${query}` : pathname
}

/** `/companies` → `/accounts` */
export function RedirectToAccounts() {
  const { search } = useLocation()

  return <Navigate to={withQuery('/accounts', search)} replace />
}

/** `/companies/new` → `/accounts/new` */
export function RedirectToNewAccount() {
  const { search } = useLocation()

  return <Navigate to={withQuery('/accounts/new', search)} replace />
}

/** `/companies/:id` → `/accounts/:id` */
export function RedirectToAccount() {
  const { id } = useParams()
  const { search } = useLocation()

  return <Navigate to={withQuery(`/accounts/${id ?? ''}`, search)} replace />
}

/** `/clients` → `/accounts?view=clients` */
export function RedirectToClientAccounts() {
  const { search } = useLocation()

  return <Navigate to={withQuery('/accounts', search, { view: 'clients' })} replace />
}

/** `/qualification`, `/assignment`, `/lifecycle` and `/conversion` → a Lead Workspace view. */
export function RedirectToLeadView({ view }: { view: string }) {
  const { search } = useLocation()

  return <Navigate to={withQuery('/leads', search, { view })} replace />
}
