import { act, render, screen } from '@testing-library/react'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { api } from './api'
import { AuthProvider } from './auth'
import { useAuth } from './auth-context'
import { AUTH_UNAUTHORIZED_EVENT, clearAuthSession } from './authEvents'

function SessionProbe() {
  const { token, user } = useAuth()

  return <p>{token ? `signed in as ${user?.name}` : 'signed out'}</p>
}

describe('expired sessions', () => {
  beforeEach(() => {
    localStorage.clear()
  })

  it('clears the stored session and notifies listeners when one existed', () => {
    localStorage.setItem('token', 'token-1')
    localStorage.setItem('user', '{"id":1,"name":"Sam"}')
    const listener = vi.fn()
    window.addEventListener(AUTH_UNAUTHORIZED_EVENT, listener)

    clearAuthSession()

    expect(localStorage.getItem('token')).toBeNull()
    expect(localStorage.getItem('user')).toBeNull()
    expect(listener).toHaveBeenCalledTimes(1)

    window.removeEventListener(AUTH_UNAUTHORIZED_EVENT, listener)
  })

  it('does not announce an expiry when there was no session', () => {
    const listener = vi.fn()
    window.addEventListener(AUTH_UNAUTHORIZED_EVENT, listener)

    clearAuthSession()

    expect(listener).not.toHaveBeenCalled()

    window.removeEventListener(AUTH_UNAUTHORIZED_EVENT, listener)
  })

  it('drops the auth provider state when the session expires', () => {
    localStorage.setItem('token', 'token-1')
    localStorage.setItem('user', '{"id":1,"name":"Sam Salesrep"}')
    render(
      <AuthProvider>
        <SessionProbe />
      </AuthProvider>,
    )

    expect(screen.getByText('signed in as Sam Salesrep')).toBeInTheDocument()

    act(() => clearAuthSession())

    expect(screen.getByText('signed out')).toBeInTheDocument()
  })

  it('clears the session when an API call receives a 401', async () => {
    localStorage.setItem('token', 'token-1')
    localStorage.setItem('user', '{"id":1,"name":"Sam"}')
    const listener = vi.fn()
    window.addEventListener(AUTH_UNAUTHORIZED_EVENT, listener)

    await expect(
      api.get('/leads', {
        adapter: async () => {
          throw Object.assign(new Error('Unauthorized'), { response: { status: 401 } })
        },
      }),
    ).rejects.toThrow('Unauthorized')

    expect(localStorage.getItem('token')).toBeNull()
    expect(listener).toHaveBeenCalledTimes(1)

    window.removeEventListener(AUTH_UNAUTHORIZED_EVENT, listener)
  })
})
