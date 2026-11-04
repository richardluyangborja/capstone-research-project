import { fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import PublicLayout from '../components/layout/PublicLayout'
import PublicHome from './PublicHome'

const { useAuth } = vi.hoisted(() => ({ useAuth: vi.fn() }))

vi.mock('../lib/auth-context', () => ({ useAuth }))

const renderHome = (entry = '/') =>
  render(
    <MemoryRouter initialEntries={[entry]}>
      <Routes>
        <Route element={<PublicLayout />}>
          <Route path="/" element={<PublicHome />} />
        </Route>
        <Route path="/leads" element={<p>Lead workspace</p>} />
        <Route path="/dashboard" element={<p>Dashboard</p>} />
      </Routes>
    </MemoryRouter>,
  )

describe('public landing', () => {
  beforeEach(() => {
    useAuth.mockReset()
    useAuth.mockReturnValue({ user: null })
  })

  it('renders the public header, hero, features and request section', () => {
    renderHome()

    expect(
      screen.getByRole('heading', {
        level: 1,
        name: /The right people, and the follow-through to match/,
      }),
    ).toBeInTheDocument()
    expect(screen.getByRole('navigation', { name: 'Public' })).toBeInTheDocument()

    const requestLinks = screen.getAllByRole('link', { name: 'Request staffing' })
    expect(requestLinks.length).toBeGreaterThan(0)
    expect(requestLinks.every((link) => link.getAttribute('href') === '#request-staffing')).toBe(
      true,
    )
    expect(screen.getByRole('link', { name: 'Sign in' })).toHaveAttribute('href', '/?login=1')

    expect(screen.getByRole('heading', { name: 'Capture every request' })).toBeInTheDocument()
    expect(screen.getByRole('heading', { name: 'Keep every conversation' })).toBeInTheDocument()
    expect(screen.getByRole('heading', { name: 'Tell us what you need.' })).toBeInTheDocument()
    expect(screen.getByLabelText(/Company name/)).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Send request' })).toBeInTheDocument()

    expect(screen.getByRole('link', { name: /Email the team/ })).toHaveAttribute(
      'href',
      'mailto:hello@primepower.com.ph?subject=Staffing%20request',
    )
    expect(screen.getByRole('link', { name: /Call \+63/ })).toHaveAttribute(
      'href',
      'tel:+63288881000',
    )
  })

  it('renders the product previews as real tables, not images', () => {
    const { container } = renderHome()

    const tables = screen.getAllByRole('table', { hidden: true })
    expect(tables.length).toBeGreaterThanOrEqual(3)
    expect(tables.some((table) => table.textContent?.includes('Negotiation'))).toBe(true)

    const externalImages = [...container.querySelectorAll('img')].filter((image) =>
      image.getAttribute('src')?.startsWith('http'),
    )
    expect(externalImages).toHaveLength(0)
  })

  it('offers an authenticated visitor the dashboard instead of sign-in', () => {
    useAuth.mockReturnValue({
      user: { id: 1, name: 'Sam Salesrep', email: 'sam@primepower.test', role: 'salesrep' },
    })
    renderHome()

    expect(screen.getByRole('link', { name: 'Open dashboard' })).toHaveAttribute(
      'href',
      '/dashboard',
    )
    expect(screen.queryByRole('link', { name: 'Sign in' })).not.toBeInTheDocument()
  })

  it('opens the modal from the query string and returns to the requested path', async () => {
    const login = vi.fn().mockResolvedValue(undefined)
    useAuth.mockReturnValue({ user: null, login })
    renderHome('/?login=1&next=%2Fleads%3Fview%3Dmine')

    expect(screen.getByRole('dialog', { name: 'Sign in' })).toBeInTheDocument()

    const dialog = screen.getByRole('dialog', { name: 'Sign in' })
    fireEvent.change(within(dialog).getByLabelText('Email'), {
      target: { value: 'manager@primepower.test' },
    })
    fireEvent.change(within(dialog).getByLabelText('Password'), { target: { value: 'password' } })
    fireEvent.click(screen.getByRole('button', { name: 'Sign in' }))

    expect(await screen.findByText('Lead workspace', { selector: 'p' })).toBeInTheDocument()
    expect(login).toHaveBeenCalledWith('manager@primepower.test', 'password')
  })

  it('closes the modal without leaving the public page', async () => {
    useAuth.mockReturnValue({ user: null, login: vi.fn() })
    renderHome('/?login=1')

    fireEvent.click(screen.getByRole('button', { name: 'Close sign in' }))

    await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument())
    expect(screen.getByRole('link', { name: 'Sign in' })).toHaveAttribute('href', '/?login=1')
  })

  it('sends an authenticated visitor with a login link straight to the destination', async () => {
    useAuth.mockReturnValue({
      user: { id: 1, name: 'Sam Salesrep', email: 'sam@primepower.test', role: 'salesrep' },
      login: vi.fn(),
    })
    renderHome('/?login=1&next=%2Fleads')

    expect(await screen.findByText('Lead workspace', { selector: 'p' })).toBeInTheDocument()
  })
})
