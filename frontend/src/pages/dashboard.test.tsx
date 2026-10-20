import { fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import { MemoryRouter } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import DashboardHome from './DashboardHome'

// Module 06 / spec 14 — the dashboard home reads simulated fixtures through `getDashboard`, so
// the tests exercise the real fixtures (no lib mock) and only stub the session role.

const { useAuth } = vi.hoisted(() => ({ useAuth: vi.fn() }))

vi.mock('../lib/auth-context', () => ({ useAuth }))

const session = (role: 'salesrep' | 'manager' | 'admin', name: string) => ({
  user: { id: 1, name, email: `${role}@primepower.test`, role },
  token: 'test-token',
  login: vi.fn(),
  logout: vi.fn(),
})

const renderDashboard = () =>
  render(
    <MemoryRouter>
      <DashboardHome />
    </MemoryRouter>,
  )

describe('dashboard home (simulated)', () => {
  beforeEach(() => {
    useAuth.mockReturnValue(session('manager', 'Maria Manager'))
  })

  it('renders the manager scope, the KPI rows and both charts', async () => {
    renderDashboard()

    expect(await screen.findByText(/Good (morning|afternoon|evening), Maria/)).toBeInTheDocument()
    expect(screen.getByText(/Business Development team/)).toBeInTheDocument()

    // The D1 mock is documented in the repo, not surfaced in the UI.
    expect(screen.queryByText(/simulated/i)).not.toBeInTheDocument()

    // Manager, last 30 days: 54 new leads and ₱4.12M open pipeline.
    expect(screen.getByText('54')).toBeInTheDocument()
    const pipelineCard = screen
      .getAllByText('Open pipeline')
      .map((element) => element.closest('a'))
      .find((element) => element !== null)
    expect(pipelineCard).not.toBeNull()
    expect(within(pipelineCard as HTMLElement).getByText('₱4,120,000.00')).toBeInTheDocument()

    expect(
      screen.getByRole('img', { name: /Line chart: New leads, Qualified, Won/ }),
    ).toBeInTheDocument()
    expect(screen.getByRole('img', { name: /Open pipeline by stage/ })).toBeInTheDocument()
  })

  it('makes every KPI card a link to its module page', async () => {
    renderDashboard()

    const leadsCard = (await screen.findAllByText('New leads'))[0].closest('a')
    expect(leadsCard).toHaveAttribute('href', '/leads')

    const attentionCard = screen
      .getAllByText('Needs attention')
      .find((element) => element.closest('a'))
    expect(attentionCard?.closest('a')).toHaveAttribute(
      'href',
      '/satisfaction/responses?attention=true',
    )
  })

  it('shows the worklist with clickable rows and the team alerts', async () => {
    renderDashboard()

    const sunrise = await screen.findByRole('link', { name: 'Sunrise Foods Inc' })
    expect(sunrise).toHaveAttribute('href', '/satisfaction/responses?attention=true')

    // A team-only row is visible to a manager…
    expect(screen.getByRole('link', { name: 'Metro Builders' })).toHaveAttribute(
      'href',
      '/assignment',
    )

    // …and the right column links out too.
    expect(screen.getByRole('link', { name: /Claim the lead/ })).toHaveAttribute(
      'href',
      '/assignment',
    )
  })

  it('renders the AI report and links its insights and actions', async () => {
    renderDashboard()

    expect(await screen.findByText('AI management report')).toBeInTheDocument()
    expect(screen.getByText('Sunrise is the loudest signal')).toBeInTheDocument()

    const readLinks = screen.getAllByRole('link', { name: /Read the answer/ })
    expect(readLinks.length).toBeGreaterThan(0)
    expect(
      readLinks.every(
        (link) => link.getAttribute('href') === '/satisfaction/responses?attention=true',
      ),
    ).toBe(true)
    expect(screen.getByRole('link', { name: /Unstick negotiation/ })).toHaveAttribute(
      'href',
      '/pipeline',
    )
  })

  it('switches the reporting period and re-reads the fixtures', async () => {
    renderDashboard()

    fireEvent.click(await screen.findByRole('button', { name: 'Year to date' }))

    await waitFor(() => {
      expect(screen.getByRole('button', { name: 'Year to date' })).toHaveAttribute(
        'aria-pressed',
        'true',
      )
    })

    expect(await screen.findByText('344')).toBeInTheDocument()
    expect(screen.queryByText('54')).not.toBeInTheDocument()
  })

  it('shows all three staff rows to a manager, with core2 HR figures', async () => {
    renderDashboard()

    const table = await screen.findByRole('table', {
      name: /Staff performance: CRM output and core2 HR figures/,
    })

    expect(within(table).getByText('Maria Manager')).toBeInTheDocument()
    expect(within(table).getByText('Sam Salesrep')).toBeInTheDocument()
    expect(within(table).getByText('Liza Lopez')).toBeInTheDocument()
    expect(within(table).getByText('Attendance')).toBeInTheDocument()
    expect(within(table).getByText('Leave (days)')).toBeInTheDocument()
  })

  it('narrows a salesrep to their own book, scorecard and personal rows', async () => {
    useAuth.mockReturnValue(session('salesrep', 'Sam Salesrep'))
    renderDashboard()

    expect(await screen.findByText(/Your book/)).toBeInTheDocument()
    expect(screen.getByText(/Your scorecard/)).toBeInTheDocument()

    const table = screen.getByRole('table', {
      name: /Staff performance: CRM output and core2 HR figures/,
    })
    expect(within(table).getByText('Sam Salesrep')).toBeInTheDocument()
    expect(within(table).queryByText('Liza Lopez')).not.toBeInTheDocument()

    // Team-only worklist rows are not in a salesrep's view.
    expect(screen.queryByRole('link', { name: 'Metro Builders' })).not.toBeInTheDocument()
  })

  it('falls back to org scope for an admin', async () => {
    useAuth.mockReturnValue(session('admin', 'Ada Admin'))
    renderDashboard()

    expect(await screen.findByText(/Primepower · CRM org-wide/)).toBeInTheDocument()
    expect(screen.getByText('128')).toBeInTheDocument()
    expect(screen.getByRole('link', { name: 'Metro Builders' })).toBeInTheDocument()
  })
})
