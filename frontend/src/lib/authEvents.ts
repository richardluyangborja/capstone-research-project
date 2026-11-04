/** The API reported an expired or invalid session (spec 16 §Sign-in modal). */
export const AUTH_UNAUTHORIZED_EVENT = 'auth:unauthorized'

/**
 * Clear the local session and tell the auth provider to drop its state. Only emit the event when
 * a session really existed — a rejected sign-in attempt must show the inline error, not pretend a
 * session expired.
 */
export function clearAuthSession() {
  const hadSession = localStorage.getItem('token') !== null || localStorage.getItem('user') !== null

  localStorage.removeItem('token')
  localStorage.removeItem('user')

  if (hadSession && typeof window !== 'undefined') {
    window.dispatchEvent(new Event(AUTH_UNAUTHORIZED_EVENT))
  }
}
