import { fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import { MemoryRouter } from 'react-router-dom'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import AdminOperations from './admin/AdminOperations'
import type {
  OperationsHealthResponse,
  OperationsPlatformSummary,
  OperationsSummary,
} from '../types'

// `vi.mock` factories hoist above the imports, so the fixtures they close over use `vi.hoisted`.
const { health, platform, summary, operationsApi } = vi.hoisted(() => {
  const healthResponse = () => ({
    health: {
      generated_at: '2026-11-10T02:00:00.000000Z',
      checks: [
        {
          key: 'database',
          label: 'Database',
          status: 'healthy' as const,
          checked_at: '2026-11-10T02:00:00.000000Z',
          summary: 'The application database answered a bounded read (pgsql driver).',
          remediation: null,
          source: 'application' as const,
          detail: {},
        },
        {
          key: 'hr',
          label: 'HR feed (core2)',
          status: 'degraded' as const,
          checked_at: '2026-11-10T02:00:00.000000Z',
          summary: 'Attendance and leave are mocked (core2 is not connected).',
          remediation: 'Check the HR service binding and its credentials.',
          source: 'mocked' as const,
          detail: {},
        },
        {
          key: 'ai',
          label: 'AI service',
          status: 'unavailable' as const,
          checked_at: '2026-11-10T02:00:00.000000Z',
          summary: 'The AI service did not answer its health endpoint within two seconds.',
          remediation: 'Check the AI service is running and reachable from the application.',
          source: 'external' as const,
          detail: {},
        },
      ],
    },
    meta: { timezone: 'Asia/Manila', read_only: true, platform: false },
  })

  const platformBlock: OperationsPlatformSummary = {
    application: { build: 'unknown', runtime: 'PHP 8.5.0 · Laravel 13', environment: 'local' },
    schema: { state: 'current', pending: 0 },
    queue: { driver: 'database', failed_jobs: 0 },
    entity_totals: {
      leads: 5,
      accounts: 5,
      contacts: 12,
      opportunities: 9,
      contracts: 6,
      communications: 20,
      users: 5,
    },
    security_events: { warning: 1, critical: 0 },
  }

  const summaryResponse = (overrides: Partial<OperationsSummary> = {}): OperationsSummary => ({
    generated_at: '2026-11-10T02:00:00.000000Z',
    window: { activity_hours: 24, configuration_days: 7, timezone: 'Asia/Manila' },
    accounts: { active: 5, suspended: 1 },
    administrative_activity: {
      total: 3,
      severities: { info: 2, notice: 1, warning: 0, critical: 0 },
      recent: [
        {
          id: '01JAUDIT000000000000000001',
          action: 'user.role_changed',
          severity: 'warning',
          actor: 'Ada Admin',
          created_at: '2026-11-10T01:00:00.000000Z',
        },
      ],
    },
    integrations: {
      counts: { healthy: 1, degraded: 1, unavailable: 1, not_configured: 0 },
      total: 3,
      attention: [
        { key: 'hr', label: 'HR feed (core2)', status: 'degraded', source: 'mocked' },
        { key: 'ai', label: 'AI service', status: 'unavailable', source: 'external' },
      ],
      mocked: 1,
    },
    failed_operations: {
      failed_emails: 2,
      available: true,
      last_failed_at: '2026-11-09T12:00:00.000000Z',
    },
    maintenance: {
      retryable_failed_emails: 2,
      configuration_refresh_available: true,
      actions_available: false,
    },
    configuration_changes: { last_7_days: 4, window_days: 7 },
    platform: null,
    ...overrides,
  })

  return {
    health: { response: healthResponse, make: healthResponse },
    platform: platformBlock,
    summary: { make: summaryResponse },
    operationsApi: {
      getOperationsHealth: vi.fn(),
      getOperationsSummary: vi.fn(),
    },
  }
})

vi.mock('../lib/admin', () => ({
  getOperationsHealth: operationsApi.getOperationsHealth,
  getOperationsSummary: operationsApi.getOperationsSummary,
}))

const renderPage = () =>
  render(
    <MemoryRouter>
      <AdminOperations />
    </MemoryRouter>,
  )

describe('admin operations health', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    operationsApi.getOperationsHealth.mockResolvedValue(
      health.response as unknown as OperationsHealthResponse,
    )
    operationsApi.getOperationsSummary.mockResolvedValue(summary.make())
  })

  afterEach(() => {
    vi.restoreAllMocks()
  })

  it('renders the overall health roll-up with the worst status winning', async () => {
    renderPage()

    const overall = await screen.findByRole('status')
    expect(overall).toHaveTextContent('1 dependency unavailable')
    expect(
      screen.getByText(/3 checks · 1 healthy · 1 degraded · 1 unavailable/),
    ).toBeInTheDocument()
  })

  it('renders a card per check with its status, source and remediation', async () => {
    renderPage()

    const databaseCard = (await screen.findByText('Database')).closest(
      '[data-check]',
    ) as HTMLElement
    expect(within(databaseCard).getByText('Healthy')).toBeInTheDocument()
    expect(within(databaseCard).getByText('Source: Application')).toBeInTheDocument()

    const hrCard = document.querySelector('[data-check="hr"]') as HTMLElement
    expect(within(hrCard).getByText('Degraded')).toBeInTheDocument()
    expect(within(hrCard).getByText('Source: Mocked')).toBeInTheDocument()
    expect(within(hrCard).getByText(/Check the HR service binding/)).toBeInTheDocument()

    const aiCard = document.querySelector('[data-check="ai"]') as HTMLElement
    expect(within(aiCard).getByText('Unavailable')).toBeInTheDocument()
    expect(within(aiCard).getByText('Source: External service')).toBeInTheDocument()
  })

  it('shows the summary counters and the integrations needing attention', async () => {
    renderPage()

    expect(await screen.findByText('Active accounts')).toBeInTheDocument()
    expect(screen.getByText('Failed emails')).toBeInTheDocument()
    expect(screen.getByText('2 retryable')).toBeInTheDocument()

    const integrations = document.querySelector('[data-panel="integrations"]') as HTMLElement
    expect(within(integrations).getByText(/served by a mocked source/)).toBeInTheDocument()

    const activity = document.querySelector('[data-panel="activity"]') as HTMLElement
    expect(within(activity).getByText('Role changed')).toBeInTheDocument()
  })

  it('does not render the platform panel for an application admin', async () => {
    renderPage()

    await screen.findByText('Active accounts')

    expect(document.querySelector('[data-panel="platform"]')).toBeNull()
    expect(screen.queryByText('Platform diagnostics')).not.toBeInTheDocument()
  })

  it('renders the platform panel when the server includes it for a superadmin', async () => {
    operationsApi.getOperationsSummary.mockResolvedValue(summary.make({ platform }))

    renderPage()

    const panel = (await screen.findByText('Platform diagnostics')).closest(
      'section',
    ) as HTMLElement
    expect(within(panel).getByText('Current')).toBeInTheDocument()
    expect(within(panel).getByText('database')).toBeInTheDocument()
    expect(within(panel).getByText('12')).toBeInTheDocument()
  })

  it('shows a retryable error when the health checks fail, keeping the summary', async () => {
    operationsApi.getOperationsHealth.mockRejectedValue(new Error('network down'))

    renderPage()

    expect(await screen.findByText('Health checks could not be loaded')).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Retry' })).toBeInTheDocument()
    // The summary half still rendered.
    expect(screen.getByText('Active accounts')).toBeInTheDocument()
  })

  it('shows an empty state when the health endpoint reports no checks', async () => {
    operationsApi.getOperationsHealth.mockResolvedValue({
      health: { generated_at: '2026-11-10T02:00:00.000000Z', checks: [] },
      meta: { timezone: 'Asia/Manila', read_only: true, platform: false },
    })

    renderPage()

    expect(await screen.findByText('No checks reported')).toBeInTheDocument()
  })

  it('shows a summary error without blanking the health checks', async () => {
    operationsApi.getOperationsSummary.mockRejectedValue(new Error('boom'))

    renderPage()

    expect(
      await screen.findByText('The operations summary could not be loaded'),
    ).toBeInTheDocument()
    expect(await screen.findByText('Database')).toBeInTheDocument()
  })

  it('re-checks the dependencies from the header action', async () => {
    renderPage()
    await screen.findByText('Database')

    operationsApi.getOperationsHealth.mockClear()
    operationsApi.getOperationsSummary.mockClear()

    fireEvent.click(screen.getByRole('button', { name: 'Re-check' }))

    await waitFor(() => expect(operationsApi.getOperationsHealth).toHaveBeenCalledTimes(1))
    expect(operationsApi.getOperationsSummary).toHaveBeenCalledTimes(1)
  })

  it('offers no maintenance control anywhere on the page', async () => {
    renderPage()
    await screen.findByText('Active accounts')

    expect(screen.getByText(/This page does not run either/)).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: /retry email/i })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: /refresh configuration/i })).not.toBeInTheDocument()
  })
})
