import { render, screen } from '@testing-library/react'
import { MemoryRouter } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import AppRouter from './AppRouter'
import type { Role } from '../types'

const { useAuth } = vi.hoisted(() => ({ useAuth: vi.fn() }))

vi.mock('../lib/auth-context', () => ({ useAuth }))

const renderAt = (path: string, role: Role | null) => {
  useAuth.mockReturnValue({
    user: role ? { id: 1, name: 'Test User', email: 'test@example.test', role } : null,
    token: role ? 'token-1' : null,
    login: vi.fn(),
    logout: vi.fn(),
  })

  return render(
    <MemoryRouter initialEntries={[path]}>
      <AppRouter />
    </MemoryRouter>,
  )
}

describe('administration route protection', () => {
  beforeEach(() => {
    useAuth.mockReset()
  })

  it('lets an admin open an application administration route', () => {
    renderAt('/admin/users', 'admin')

    expect(screen.getByRole('heading', { name: 'Users', level: 2 })).toBeInTheDocument()
    expect(
      screen.getByRole('navigation', { name: 'Application administration' }),
    ).toBeInTheDocument()
    expect(screen.queryByText('403')).not.toBeInTheDocument()
  })

  it('blocks a manager and a salesrep from an application administration route', () => {
    ;(['manager', 'salesrep'] as const).forEach((role) => {
      const { unmount } = renderAt('/admin/users', role)

      expect(screen.getByText('403')).toBeInTheDocument()
      expect(
        screen.queryByText('Create, invite, suspend and restore CRM accounts.'),
      ).not.toBeInTheDocument()
      unmount()
    })
  })

  it('blocks an admin from the platform operations route but lets a superadmin in', () => {
    const admin = renderAt('/platform/diagnostics', 'admin')
    expect(screen.getByText('403')).toBeInTheDocument()
    admin.unmount()

    renderAt('/platform/diagnostics', 'superadmin')
    expect(
      screen.getByRole('heading', { name: 'Platform diagnostics', level: 2 }),
    ).toBeInTheDocument()
    expect(screen.queryByText('403')).not.toBeInTheDocument()
  })

  it('keeps personal settings open to every role', () => {
    ;(['salesrep', 'manager', 'admin', 'superadmin'] as const).forEach((role) => {
      const { unmount } = renderAt('/settings', role)

      expect(
        screen.getByRole('heading', { name: 'Personal settings', level: 2 }),
      ).toBeInTheDocument()
      unmount()
    })
  })
})
