import { fireEvent, render, screen } from '@testing-library/react'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import Login from './Login'

const { login } = vi.hoisted(() => ({ login: vi.fn() }))

vi.mock('../lib/auth-context', () => ({ useAuth: () => ({ login }) }))

const renderLogin = () =>
  render(
    <MemoryRouter initialEntries={['/login']}>
      <Routes>
        <Route path="/login" element={<Login />} />
        <Route path="/dashboard" element={<p>Dashboard home</p>} />
      </Routes>
    </MemoryRouter>,
  )

describe('login', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    login.mockResolvedValue(undefined)
  })

  it('opens the dashboard after a successful sign-in', async () => {
    renderLogin()

    fireEvent.change(screen.getByLabelText('Email'), {
      target: { value: 'manager@primepower.test' },
    })
    fireEvent.change(screen.getByLabelText('Password'), { target: { value: 'password' } })
    fireEvent.click(screen.getByRole('button', { name: 'Sign in' }))

    expect(await screen.findByText('Dashboard home')).toBeInTheDocument()
    expect(login).toHaveBeenCalledWith('manager@primepower.test', 'password')
  })

  it('stays on the form when the credentials are rejected', async () => {
    login.mockRejectedValue(new Error('invalid'))
    renderLogin()

    fireEvent.click(screen.getByRole('button', { name: 'Sign in' }))

    expect(await screen.findByText('Invalid email or password.')).toBeInTheDocument()
    expect(screen.queryByText('Dashboard home')).not.toBeInTheDocument()
  })
})
