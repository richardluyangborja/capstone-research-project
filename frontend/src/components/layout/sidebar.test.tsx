import { render, screen } from '@testing-library/react'
import { MemoryRouter } from 'react-router-dom'
import { describe, expect, it, vi } from 'vitest'
import Sidebar from './Sidebar'

vi.mock('../../lib/auth-context', () => ({
  useAuth: () => ({
    user: {
      id: 1,
      name: 'Maria Manager',
      email: 'manager@primepower.test',
      role: 'manager',
    },
    token: 'test-token',
    login: vi.fn(),
    logout: vi.fn(),
  }),
}))

const renderSidebar = () =>
  render(
    <MemoryRouter initialEntries={['/dashboard']}>
      <Sidebar role="manager" />
    </MemoryRouter>,
  )

describe('Sidebar', () => {
  it('groups the implemented module 01 destinations under Leads, Accounts and Contacts', () => {
    renderSidebar()

    expect(screen.getByRole('button', { name: 'Lead & Client Tracking' })).toBeInTheDocument()
    expect(screen.getByText('Leads')).toBeInTheDocument()
    expect(screen.getByText('Accounts')).toBeInTheDocument()
    expect(screen.getByText('Contacts')).toBeInTheDocument()

    expect(screen.getByRole('link', { name: 'Lead Workspace' })).toHaveAttribute('href', '/leads')
    expect(screen.getByRole('link', { name: 'Capture Lead' })).toHaveAttribute('href', '/leads/new')
    expect(screen.getByRole('link', { name: 'Account Book' })).toHaveAttribute('href', '/accounts')
    expect(screen.getByRole('link', { name: 'Contact Book' })).toHaveAttribute('href', '/contacts')
  })

  it('keeps the module groups and uses the spec labels without dead links', () => {
    renderSidebar()

    const expectedLinks: [string, string][] = [
      ['Dashboard', '/dashboard'],
      ['Pipeline Board', '/pipeline'],
      ['Deal Book', '/opportunities'],
      ['Log & History', '/communications'],
      ['Conversations', '/communications/threads'],
      ['Template Library', '/communications/templates'],
      ['Follow-up Worklist', '/follow-ups'],
      ['Survey Library', '/surveys'],
      ['Invitations', '/survey-invitations'],
      ['Satisfaction', '/satisfaction'],
      ['Responses', '/satisfaction/responses'],
      ['Management Reports', '/reports'],
    ]

    expectedLinks.forEach(([label, href]) => {
      expect(screen.getByRole('link', { name: label })).toHaveAttribute('href', href)
    })

    expect(
      screen.getByRole('button', { name: 'Opportunity Pipeline Visualization' }),
    ).toBeInTheDocument()
    expect(
      screen.getByRole('button', { name: 'Communication History Management' }),
    ).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Follow-up Reminder System' })).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Client Satisfaction & Survey' })).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'AI Reports' })).toBeInTheDocument()
  })

  it('brands the sidebar and leaves the account block free of a role badge', () => {
    renderSidebar()

    expect(screen.getByText('Primepower Manpower Services')).toHaveClass('text-logo-brand')
    expect(screen.getByRole('button', { name: 'Lead & Client Tracking' })).toHaveClass(
      'text-primary',
    )
    expect(screen.getByText('Leads')).toHaveClass('text-primary')

    expect(screen.getByText('Maria Manager')).toBeInTheDocument()
    expect(screen.getByText('manager@primepower.test')).toBeInTheDocument()
    expect(screen.queryByText('manager')).not.toBeInTheDocument()
  })

  it('no longer exposes the consolidated module 01 pages', () => {
    renderSidebar()

    const retiredLabels = [
      'Lead Qualification & Scoring',
      'Lead Assignment & Ownership',
      'Lead Lifecycle & Status',
      'Company / Account Management',
      'Contact Management',
      'Lead-to-Client Conversion',
      'Client Account Lifecycle',
    ]

    retiredLabels.forEach((label) => {
      expect(screen.queryByRole('link', { name: label })).not.toBeInTheDocument()
    })

    const hrefs = screen.getAllByRole('link').map((link) => link.getAttribute('href'))

    expect(hrefs).not.toContain('/qualification')
    expect(hrefs).not.toContain('/assignment')
    expect(hrefs).not.toContain('/lifecycle')
    expect(hrefs).not.toContain('/conversion')
    expect(hrefs).not.toContain('/clients')
    expect(hrefs).not.toContain('/companies')
  })
})
