import { render, screen } from '@testing-library/react'
import { MemoryRouter } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import RoleRoute from './RoleRoute'

const { useAuth } = vi.hoisted(() => ({ useAuth: vi.fn() }))

vi.mock('../lib/auth-context', () => ({ useAuth }))

const renderRoute = (role: 'salesrep' | 'manager' | 'admin' | 'superadmin') => {
  useAuth.mockReturnValue({ user: { id: 1, name: 'Test User', email: 'test@example.test', role } })

  return render(
    <MemoryRouter initialEntries={['/admin']}>
      <RoleRoute allow={['admin', 'superadmin']}>
        <p>Application administration</p>
      </RoleRoute>
    </MemoryRouter>,
  )
}

describe('RoleRoute', () => {
  beforeEach(() => {
    useAuth.mockReset()
  })

  it('renders the administration content for an admin', () => {
    renderRoute('admin')

    expect(screen.getByText('Application administration')).toBeInTheDocument()
  })

  it('renders the administration content for a superadmin', () => {
    renderRoute('superadmin')

    expect(screen.getByText('Application administration')).toBeInTheDocument()
  })

  it('replaces the route with the 403 surface for a manager', () => {
    renderRoute('manager')

    expect(screen.queryByText('Application administration')).not.toBeInTheDocument()
    expect(screen.getByText('403')).toBeInTheDocument()
    expect(
      screen.getByText('This action is unauthorized. Ask a superadmin if you need access.'),
    ).toBeInTheDocument()
  })

  it('replaces the route with the 403 surface for a salesrep', () => {
    renderRoute('salesrep')

    expect(screen.queryByText('Application administration')).not.toBeInTheDocument()
    expect(screen.getByText('403')).toBeInTheDocument()
  })
})
