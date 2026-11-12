import { fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import { MemoryRouter } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import PlatformDiagnostics from './platform/PlatformDiagnostics'
import type { PlatformDiagnosticsResponse } from '../types'

const { diagnosticsFixture, getPlatformDiagnostics } = vi.hoisted(() => {
  const make = (
    overrides: Partial<PlatformDiagnosticsResponse['diagnostics']> = {},
  ): PlatformDiagnosticsResponse => ({
    diagnostics: {
      generated_at: '2026-11-10T02:00:00.000000Z',
      read_only: true,
      application: {
        state: 'available',
        build: 'unknown',
        runtime: 'PHP 8.5.0 · Laravel 13',
        environment: 'testing',
        timezone: 'Asia/Manila',
        debug: false,
        maintenance_mode: false,
      },
      schema: {
        state: 'available',
        applied: 42,
        pending: 0,
        latest: '2026_11_09_100000_create_settings_table',
        batch: 1,
      },
      storage: {
        state: 'available',
        disk: 'local',
        writable: true,
        attachments: { state: 'available', count: 3, bytes: 2048 },
        free_bytes: 1048576,
        total_bytes: 2097152,
      },
      failed_work: {
        state: 'available',
        queue: {
          driver: 'sync',
          state: 'available',
          count: 0,
          last_failed_at: null,
          recent: [],
        },
        requests: {
          state: 'not_configured',
          summary: 'No failed-request store is configured; only queued work is inspectable.',
        },
      },
      entity_totals: {
        state: 'available',
        counts: {
          leads: { label: 'Leads', count: 5, state: 'available' },
          accounts: { label: 'Accounts', count: 4, state: 'available' },
          contacts: { label: 'Contacts', count: 12, state: 'available' },
          opportunities: { label: 'Opportunities', count: 8, state: 'available' },
          contracts: { label: 'Contracts', count: 3, state: 'available' },
          communications: { label: 'Communications', count: 20, state: 'available' },
          activities: { label: 'Activities', count: 30, state: 'available' },
          follow_up_tasks: { label: 'Follow-up tasks', count: 6, state: 'available' },
          attachments: { label: 'Attachments', count: 3, state: 'available' },
          surveys: { label: 'Surveys', count: 5, state: 'available' },
          survey_invitations: { label: 'Survey invitations', count: 5, state: 'available' },
          survey_responses: { label: 'Survey responses', count: 2, state: 'available' },
          teams: { label: 'Teams', count: 2, state: 'available' },
          users: { label: 'Users', count: 6, state: 'available' },
          audit_logs: { label: 'Audit rows', count: 11, state: 'available' },
        },
      },
      capabilities: [
        {
          key: 'database',
          label: 'Database',
          state: 'available',
          value: 'sqlite',
          summary: 'The application database driver is configured.',
        },
        {
          key: 'email',
          label: 'Email',
          state: 'degraded',
          value: 'Email (mocked)',
          summary: 'Email is served by the mocked gateway; nothing leaves the machine.',
        },
        {
          key: 'ai_reports',
          label: 'AI reports',
          state: 'not_configured',
          value: 'disabled',
          summary: 'AI report generation is switched off; the rules-based fallback is used.',
        },
      ],
      ...overrides,
    },
    meta: { timezone: 'Asia/Manila', read_only: true, platform: true },
  })

  return {
    diagnosticsFixture: { make },
    getPlatformDiagnostics: vi.fn(),
  }
})

vi.mock('../lib/admin', () => ({
  getPlatformDiagnostics: getPlatformDiagnostics,
}))

const renderPage = () =>
  render(
    <MemoryRouter>
      <PlatformDiagnostics />
    </MemoryRouter>,
  )

describe('platform diagnostics', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    getPlatformDiagnostics.mockResolvedValue(diagnosticsFixture.make())
  })

  it('renders runtime, schema, storage, failed work and allow-listed totals', async () => {
    renderPage()

    expect(
      await screen.findByRole('heading', { name: 'Platform diagnostics', level: 2 }),
    ).toBeInTheDocument()

    const application = document.querySelector('[data-panel="application"]') as HTMLElement
    expect(within(application).getByText('PHP 8.5.0 · Laravel 13')).toBeInTheDocument()
    expect(within(application).getByText('testing')).toBeInTheDocument()

    const schema = document.querySelector('[data-panel="schema"]') as HTMLElement
    expect(within(schema).getByText('42')).toBeInTheDocument()
    expect(
      within(schema).getByText('2026_11_09_100000_create_settings_table'),
    ).toBeInTheDocument()

    const storage = document.querySelector('[data-panel="storage"]') as HTMLElement
    expect(within(storage).getByText('local')).toBeInTheDocument()
    expect(within(storage).getByText('3')).toBeInTheDocument()
    expect(within(storage).getByText('2 KB')).toBeInTheDocument()

    const failedWork = document.querySelector('[data-panel="failed-work"]') as HTMLElement
    expect(within(failedWork).getByText('sync')).toBeInTheDocument()
    expect(within(failedWork).getByText('Not configured')).toBeInTheDocument()

    const entities = document.querySelector('[data-panel="entities"]') as HTMLElement
    expect(within(entities).getByText('Follow-up tasks')).toBeInTheDocument()
    expect(within(entities).getByText('Audit rows')).toBeInTheDocument()
    expect(within(entities).getByText('11')).toBeInTheDocument()
  })

  it('shows explicit unavailable and timeout states instead of zero', async () => {
    getPlatformDiagnostics.mockResolvedValue(
      diagnosticsFixture.make({
        schema: {
          state: 'timeout',
          applied: null,
          pending: null,
          latest: null,
          batch: null,
        },
        storage: {
          state: 'unavailable',
          disk: 'local',
          writable: false,
          attachments: { state: 'unavailable', count: null, bytes: null },
          free_bytes: null,
          total_bytes: null,
        },
        entity_totals: {
          state: 'partial',
          counts: {
            leads: { label: 'Leads', count: 5, state: 'available' },
            attachments: { label: 'Attachments', count: null, state: 'unavailable' },
          },
        },
      }),
    )

    renderPage()

    const schema = (await screen.findByText('Latest migration')).closest(
      '[data-panel="schema"]',
    ) as HTMLElement
    expect(within(schema).getByText('Timed out')).toBeInTheDocument()

    const storage = document.querySelector('[data-panel="storage"]') as HTMLElement
    expect(within(storage).getAllByText('Unavailable').length).toBeGreaterThan(0)
    expect(within(storage).getAllByText('Not available').length).toBeGreaterThan(0)

    const entities = document.querySelector('[data-panel="entities"]') as HTMLElement
    expect(within(entities).getByText('Partial')).toBeInTheDocument()
    expect(within(entities).getAllByText('—').length).toBeGreaterThan(0)
  })

  it('refreshes the snapshot from the header action', async () => {
    renderPage()
    await screen.findByText('Read-only snapshot')

    getPlatformDiagnostics.mockClear()
    fireEvent.click(screen.getByRole('button', { name: 'Refresh' }))

    await waitFor(() => expect(getPlatformDiagnostics).toHaveBeenCalledTimes(1))
  })

  it('shows a retryable error when the diagnostics cannot be loaded', async () => {
    getPlatformDiagnostics.mockRejectedValueOnce(new Error('network down'))

    renderPage()

    expect(
      await screen.findByText('Platform diagnostics could not be loaded'),
    ).toBeInTheDocument()

    getPlatformDiagnostics.mockResolvedValueOnce(diagnosticsFixture.make())
    fireEvent.click(screen.getByRole('button', { name: 'Retry' }))

    expect(await screen.findByText('Read-only snapshot')).toBeInTheDocument()
  })
})
