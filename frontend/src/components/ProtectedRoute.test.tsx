import { render, screen } from '@testing-library/react'
import { MemoryRouter, Route, Routes, useLocation } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import ProtectedRoute from './ProtectedRoute'

const { useAuth } = vi.hoisted(() => ({ useAuth: vi.fn() }))

vi.mock('../lib/auth-context', () => ({ useAuth }))

function LocationProbe() {
  const { pathname, search } = useLocation()

  return <p data-testid="location">{`${pathname}${search}`}</p>
}

const renderProtected = (entry = '/leads?view=mine') =>
  render(
    <MemoryRouter initialEntries={[entry]}>
      <Routes>
        <Route element={<ProtectedRoute />}>
          <Route path="/leads" element={<p>Lead workspace</p>} />
        </Route>
        <Route path="*" element={<LocationProbe />} />
      </Routes>
    </MemoryRouter>,
  )

describe('protected route', () => {
  beforeEach(() => {
    useAuth.mockReset()
  })

  it('renders the protected outlet for a signed-in visitor', () => {
    useAuth.mockReturnValue({ token: 'token-1' })

    renderProtected()

    expect(screen.getByText('Lead workspace')).toBeInTheDocument()
  })

  it('sends a signed-out visitor to the sign-in modal with the current path as next', () => {
    useAuth.mockReturnValue({ token: null })

    renderProtected()

    expect(screen.getByTestId('location')).toHaveTextContent(
      '/?login=1&next=%2Fleads%3Fview%3Dmine',
    )
  })
})
