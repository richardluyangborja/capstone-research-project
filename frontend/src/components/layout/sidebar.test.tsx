import { render, screen } from '@testing-library/react'
import { MemoryRouter } from 'react-router-dom'
import { describe, expect, it, vi } from 'vitest'
import { dashboardRoutes } from '../../routes/dashboardRoutes'
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

const renderSidebar = (role: 'manager' | 'salesrep' | 'admin' | 'superadmin' = 'manager') =>
  render(
    <MemoryRouter initialEntries={['/dashboard']}>
      <Sidebar role={role} />
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
    expect(screen.getByRole('link', { name: 'Import Leads' })).toHaveAttribute(
      'href',
      '/leads/import',
    )
    expect(screen.getByRole('link', { name: 'Account Book' })).toHaveAttribute('href', '/accounts')
    expect(screen.getByRole('link', { name: 'Contact Book' })).toHaveAttribute('href', '/contacts')
  })

  it('keeps the module groups and uses the spec labels without dead links', () => {
    renderSidebar()

    const expectedLinks: [string, string][] = [
      ['Dashboard', '/dashboard'],
      ['Pipeline Board', '/pipeline'],
      ['Deal Book', '/opportunities'],
      ['Contracts', '/contracts'],
      ['Deployments', '/deployments'],
      ['Billing', '/billing'],
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

    // Modules 02–05 use the same group + section pattern as module 01.
    const sections = ['Pipeline', 'History', 'Templates', 'Worklist', 'Surveys', 'Feedback']
    sections.forEach((section) => expect(screen.getByText(section)).toBeInTheDocument())
  })

  it('points every navigation entry at a registered dashboard route', () => {
    renderSidebar()

    const registered = dashboardRoutes.map((route) => route.path)
    const hrefs = screen
      .getAllByRole('link')
      .map((link) => link.getAttribute('href'))
      .filter((href): href is string => Boolean(href))

    expect(new Set(registered).size).toBe(registered.length)
    hrefs.forEach((href) => expect(registered).toContain(href))
  })

  it('hides the manager-only AR book from salesreps', () => {
    renderSidebar('salesrep')

    expect(screen.queryByRole('link', { name: 'Billing' })).not.toBeInTheDocument()
    expect(screen.getByRole('link', { name: 'Contracts' })).toBeInTheDocument()
    expect(screen.getByRole('link', { name: 'Deployments' })).toBeInTheDocument()
  })

  it('shows personal settings to every role and no administration plane to a salesrep', () => {
    renderSidebar('salesrep')

    expect(screen.getByRole('link', { name: 'Personal Settings' })).toHaveAttribute(
      'href',
      '/settings',
    )
    expect(screen.queryByRole('button', { name: 'Administration' })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Platform Operations' })).not.toBeInTheDocument()
  })

  it('shows the application administration group to an admin but not platform operations', () => {
    renderSidebar('admin')

    expect(screen.getByRole('button', { name: 'Administration' })).toBeInTheDocument()
    expect(screen.getByRole('link', { name: 'Admin Overview' })).toHaveAttribute('href', '/admin')
    expect(screen.getByRole('link', { name: 'Users' })).toHaveAttribute('href', '/admin/users')
    expect(screen.getByRole('link', { name: 'Teams' })).toHaveAttribute('href', '/admin/teams')
    expect(screen.getByRole('link', { name: 'Admin Settings' })).toHaveAttribute(
      'href',
      '/admin/settings',
    )
    expect(screen.getByRole('link', { name: 'Audit Log' })).toHaveAttribute('href', '/admin/audit')
    expect(screen.getByRole('link', { name: 'Operations Health' })).toHaveAttribute(
      'href',
      '/admin/operations',
    )

    expect(screen.queryByRole('button', { name: 'Platform Operations' })).not.toBeInTheDocument()
    expect(screen.queryByRole('link', { name: 'Diagnostics' })).not.toBeInTheDocument()
  })

  it('shows application and platform groups to a superadmin', () => {
    renderSidebar('superadmin')

    expect(screen.getByRole('button', { name: 'Administration' })).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Platform Operations' })).toBeInTheDocument()
    expect(screen.getByRole('link', { name: 'Platform Overview' })).toHaveAttribute(
      'href',
      '/platform',
    )
    expect(screen.getByRole('link', { name: 'Diagnostics' })).toHaveAttribute(
      'href',
      '/platform/diagnostics',
    )
    expect(screen.getByRole('link', { name: 'Personal Settings' })).toHaveAttribute(
      'href',
      '/settings',
    )
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
