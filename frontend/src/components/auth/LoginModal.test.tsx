import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import LoginModal from './LoginModal'

const { login } = vi.hoisted(() => ({ login: vi.fn() }))

vi.mock('../../lib/auth-context', () => ({ useAuth: () => ({ login }) }))

const renderModal = (
  props: Partial<{
    open: boolean
    next: string | null
    onClose: () => void
    onSuccess: () => void
  }> = {},
) => {
  const onClose = props.onClose ?? vi.fn()
  const onSuccess = props.onSuccess ?? vi.fn()

  render(
    <LoginModal
      open={props.open ?? true}
      next={props.next ?? null}
      onClose={onClose}
      onSuccess={onSuccess}
    />,
  )

  return { onClose, onSuccess }
}

const fillAndSubmit = (email = 'manager@primepower.test', password = 'password') => {
  fireEvent.change(screen.getByLabelText('Email'), { target: { value: email } })
  fireEvent.change(screen.getByLabelText('Password'), { target: { value: password } })
  fireEvent.click(screen.getByRole('button', { name: 'Sign in' }))
}

describe('login modal', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    login.mockResolvedValue(undefined)
  })

  it('renders nothing while closed', () => {
    renderModal({ open: false })

    expect(screen.queryByRole('dialog')).not.toBeInTheDocument()
  })

  it('focuses the email field and signs in to a validated next path', async () => {
    const { onSuccess } = renderModal({ next: '/leads?view=mine' })

    expect(screen.getByRole('dialog', { name: 'Sign in' })).toBeInTheDocument()
    expect(screen.getByLabelText('Email')).toHaveFocus()

    fillAndSubmit()

    await waitFor(() => expect(onSuccess).toHaveBeenCalledWith('/leads?view=mine'))
    expect(login).toHaveBeenCalledWith('manager@primepower.test', 'password')
  })

  it('shows an inline error and stays open when the credentials are rejected', async () => {
    login.mockRejectedValue(new Error('invalid'))
    const { onSuccess } = renderModal()

    fillAndSubmit()

    expect(await screen.findByRole('alert')).toHaveTextContent('Invalid email or password.')
    expect(onSuccess).not.toHaveBeenCalled()
    expect(screen.getByRole('dialog')).toBeInTheDocument()
  })

  it('closes on Escape, the backdrop and the close button', () => {
    const onClose = vi.fn()
    renderModal({ onClose })

    fireEvent.keyDown(document, { key: 'Escape' })
    expect(onClose).toHaveBeenCalledTimes(1)

    fireEvent.click(screen.getByRole('dialog').parentElement as HTMLElement)
    expect(onClose).toHaveBeenCalledTimes(2)

    fireEvent.click(screen.getByRole('button', { name: 'Close sign in' }))
    expect(onClose).toHaveBeenCalledTimes(3)
  })

  it('traps Tab inside the dialog', () => {
    renderModal()

    const closeButton = screen.getByRole('button', { name: 'Close sign in' })
    const submit = screen.getByRole('button', { name: 'Sign in' })

    closeButton.focus()
    fireEvent.keyDown(document, { key: 'Tab', shiftKey: true })
    expect(submit).toHaveFocus()

    fireEvent.keyDown(document, { key: 'Tab' })
    expect(closeButton).toHaveFocus()
  })
})
