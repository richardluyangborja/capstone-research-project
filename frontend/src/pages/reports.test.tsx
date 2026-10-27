import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import { MemoryRouter } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import Reports from './Reports'
import type { ManagementReportData } from '../lib/reports'

// Module 07 / spec 15, S1 — the page consumes one scoped report endpoint. These tests mock the
// accessor (the adapter has its own tests) and exercise the tabs, the period and the states.

const { getManagementReport, generateManagementReport, ReportCooldownError } = vi.hoisted(() => {
  class ReportCooldownError extends Error {
    readonly retryAfterSeconds: number

    constructor(retryAfterSeconds: number) {
      super('Try again in a few minutes.')
      this.name = 'ReportCooldownError'
      this.retryAfterSeconds = retryAfterSeconds
    }
  }

  return {
    getManagementReport: vi.fn(),
    generateManagementReport: vi.fn(),
    ReportCooldownError,
  }
})

vi.mock('../lib/dashboard', () => ({
  DASHBOARD_RANGES: [
    { value: '30d', label: 'Last 30 days' },
    { value: 'quarter', label: 'This quarter' },
    { value: 'ytd', label: 'Year to date' },
  ],
}))

vi.mock('../lib/reports', () => ({
  REPORT_VARIANTS: [
    { value: 'executive', label: 'Executive', description: 'The state of the book this period.' },
    {
      value: 'pipeline',
      label: 'Pipeline',
      description: 'Where the value sits, and what is stuck.',
    },
    {
      value: 'client_health',
      label: 'Client Health',
      description: 'Which clients need care right now.',
    },
  ],
  getManagementReport,
  generateManagementReport,
  ReportCooldownError,
}))

const data = (overrides: Partial<ManagementReportData> = {}): ManagementReportData => ({
  report: {
    variant: 'executive',
    title: 'Executive management report',
    headline: '2 accounts need a human this week.',
    narrative: 'The team is at a 50% win rate with ₱3.00M open and 4 follow-ups owed.',
    insights: [
      {
        id: 'attention',
        title: 'The unhappy queue',
        body: '2 accounts need a callback.',
        domain: 'people',
        to: '/satisfaction/responses?attention=true',
        link_label: 'Read the answers',
      },
      {
        id: 'pipeline',
        title: 'Negotiation is where the value sits',
        body: 'Negotiation holds ₱2.00M of the ₱3.00M open pipeline.',
        domain: 'cost',
        to: '/pipeline',
        link_label: 'Open the board',
      },
      {
        id: 'follow_ups',
        title: 'The work still owed',
        body: '4 follow-ups are on the worklist; 2 already past due.',
        domain: 'activity',
        to: '/follow-ups',
        link_label: 'Review follow-ups',
      },
    ],
    actions: [
      {
        id: 'read_answers',
        label: 'Call back unhappy clients',
        description: 'Acknowledge the low scores and close the loop.',
        to: '/satisfaction/responses?attention=true',
      },
      {
        id: 'open_board',
        label: 'Unstick the pipeline',
        description: 'Review the open deals that have not moved.',
        to: '/pipeline',
      },
      {
        id: 'open_worklist',
        label: 'Clear the overdue queue',
        description: 'Reschedule or complete what is past due.',
        to: '/follow-ups',
      },
      {
        id: 'open_clients',
        label: 'Review the client book',
        description: 'Check lifecycle, renewals and account health.',
        to: '/accounts?view=clients',
      },
    ],
    generated_at: '24 Oct 2026, 12:00',
    source: 'rules',
    model: 'Primepower rules v1',
    cached: false,
    fallback: null,
  },
  meta: {
    range: '30d',
    scope: {
      role: 'manager',
      tier: 'team',
      label: 'Business Development team',
      description: "Business Development · the team's accounts, deals and answers.",
    },
    ai_available: false,
    variants: ['executive', 'pipeline', 'client_health'],
  },
  ...overrides,
})

const renderPage = () =>
  render(
    <MemoryRouter>
      <Reports />
    </MemoryRouter>,
  )

describe('management reports page', () => {
  beforeEach(() => {
    getManagementReport.mockReset()
    generateManagementReport.mockReset()
    getManagementReport.mockResolvedValue(data())
  })

  it('renders the standard executive report with its scope, provenance and links', async () => {
    renderPage()

    expect(await screen.findByText('Executive management report')).toBeInTheDocument()
    expect(screen.getByText(/Business Development team/)).toBeInTheDocument()
    expect(screen.getByText('Standard report')).toBeInTheDocument()
    expect(
      screen.getByText(/Generated 24 Oct 2026, 12:00 · Primepower rules v1/),
    ).toBeInTheDocument()
    expect(screen.getByText(/AI generation is not configured in this build/)).toBeInTheDocument()

    expect(screen.getByText('2 accounts need a human this week.')).toBeInTheDocument()
    expect(screen.getAllByText('The unhappy queue').length).toBeGreaterThan(0)
    expect(screen.getByRole('link', { name: /Read the answers/ })).toHaveAttribute(
      'href',
      '/satisfaction/responses?attention=true',
    )
    expect(screen.getByRole('link', { name: /Unstick the pipeline/ })).toHaveAttribute(
      'href',
      '/pipeline',
    )
    expect(getManagementReport).toHaveBeenCalledWith('30d', 'executive')
  })

  it('switches variant and period and re-reads the report', async () => {
    getManagementReport.mockImplementation(async (range: string, variant: string) => ({
      ...data(),
      report: {
        ...data().report,
        variant,
        title:
          variant === 'pipeline' ? 'Pipeline management report' : 'Executive management report',
      },
      meta: { ...data().meta, range },
    }))

    renderPage()

    await screen.findByText('Executive management report')
    fireEvent.click(screen.getByRole('tab', { name: /Pipeline/ }))

    await waitFor(() => {
      expect(getManagementReport).toHaveBeenCalledWith('30d', 'pipeline')
    })
    expect(await screen.findByText('Pipeline management report')).toBeInTheDocument()
    expect(screen.getByRole('tab', { name: /Pipeline/ })).toHaveAttribute('aria-selected', 'true')

    fireEvent.click(screen.getByRole('button', { name: 'This quarter' }))

    await waitFor(() => {
      expect(getManagementReport).toHaveBeenCalledWith('quarter', 'pipeline')
    })
    expect(screen.getByRole('button', { name: 'This quarter' })).toHaveAttribute(
      'aria-pressed',
      'true',
    )
  })

  it('shows a loading state while the report is being built', () => {
    getManagementReport.mockReturnValue(new Promise(() => {}) as never)

    renderPage()

    expect(screen.getByRole('status')).toHaveTextContent('Building the management report')
  })

  it('shows an empty state when no report can be assembled', async () => {
    getManagementReport.mockResolvedValue(undefined as never)

    renderPage()

    expect(await screen.findByText('No report yet')).toBeInTheDocument()
  })

  it('shows an error and retries on request', async () => {
    getManagementReport
      .mockRejectedValueOnce(new Error('reports unavailable'))
      .mockResolvedValueOnce(data())

    renderPage()

    expect(await screen.findByText('Report unavailable')).toBeInTheDocument()
    expect(screen.getByText(/Could not build the management report/)).toBeInTheDocument()

    fireEvent.click(screen.getByRole('button', { name: /Try again/ }))

    expect(await screen.findByText('Executive management report')).toBeInTheDocument()
    expect(getManagementReport).toHaveBeenCalledTimes(2)
  })

  it('shows the friendly fallback alert when the AI report is unavailable', async () => {
    getManagementReport.mockResolvedValue(
      data({
        report: {
          ...data().report,
          fallback: {
            reason: 'rate_limited',
            message: 'AI report is unavailable right now — showing the standard management report.',
          },
        },
      }),
    )

    renderPage()

    expect(await screen.findByText('AI report unavailable')).toBeInTheDocument()
    expect(
      screen.getByText(
        'AI report is unavailable right now — showing the standard management report.',
      ),
    ).toBeInTheDocument()
  })

  it('generates an AI report on demand and shows its provenance', async () => {
    getManagementReport.mockResolvedValue(
      data({ meta: { ...data().meta, ai_available: true } }),
    )
    generateManagementReport.mockResolvedValue(
      data({
        meta: { ...data().meta, ai_available: true },
        report: {
          ...data().report,
          headline: 'AI headline from the aggregated book.',
          source: 'model',
          model: 'openai/gpt-oss-20b',
          cached: false,
          fallback: null,
        },
      }),
    )

    renderPage()

    fireEvent.click(await screen.findByRole('button', { name: /Generate with AI/ }))

    await waitFor(() => {
      expect(generateManagementReport).toHaveBeenCalledWith('30d', 'executive', false)
    })
    expect(await screen.findByText('AI headline from the aggregated book.')).toBeInTheDocument()
    expect(screen.getByText('AI-generated')).toBeInTheDocument()
    expect(screen.getByText(/openai\/gpt-oss-20b/)).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: /Refresh AI report/ })).not.toBeInTheDocument()
  })

  it('refreshes a cached model report', async () => {
    getManagementReport.mockResolvedValue(
      data({
        meta: { ...data().meta, ai_available: true },
        report: {
          ...data().report,
          source: 'model',
          model: 'openai/gpt-oss-20b',
          cached: true,
          fallback: null,
        },
      }),
    )
    generateManagementReport.mockResolvedValue(
      data({
        meta: { ...data().meta, ai_available: true },
        report: {
          ...data().report,
          source: 'model',
          model: 'openai/gpt-oss-20b',
          cached: false,
          fallback: null,
        },
      }),
    )

    renderPage()

    expect(await screen.findByText('Cached')).toBeInTheDocument()
    fireEvent.click(screen.getByRole('button', { name: /Refresh AI report/ }))

    await waitFor(() => {
      expect(generateManagementReport).toHaveBeenCalledWith('30d', 'executive', true)
    })
    await waitFor(() => {
      expect(screen.queryByText('Cached')).not.toBeInTheDocument()
    })
  })

  it('keeps the current report and explains a cooldown rejection', async () => {
    getManagementReport.mockResolvedValue(
      data({ meta: { ...data().meta, ai_available: true } }),
    )
    generateManagementReport.mockRejectedValue(new ReportCooldownError(240))

    renderPage()

    fireEvent.click(await screen.findByRole('button', { name: /Generate with AI/ }))

    expect(await screen.findByText('AI generation paused')).toBeInTheDocument()
    expect(screen.getByText('Try again in a few minutes.')).toBeInTheDocument()
    expect(screen.getByText('2 accounts need a human this week.')).toBeInTheDocument()
  })

  it('shows the fallback when generation returns rules prose', async () => {
    getManagementReport.mockResolvedValue(
      data({ meta: { ...data().meta, ai_available: true } }),
    )
    generateManagementReport.mockResolvedValue(
      data({
        meta: { ...data().meta, ai_available: true },
        report: {
          ...data().report,
          fallback: {
            reason: 'timeout',
            message:
              'AI report is unavailable right now — showing the standard management report.',
          },
        },
      }),
    )

    renderPage()

    fireEvent.click(await screen.findByRole('button', { name: /Generate with AI/ }))

    expect(await screen.findByText('AI report unavailable')).toBeInTheDocument()
    expect(screen.getByText(/showing the standard management report/)).toBeInTheDocument()
    expect(screen.getByRole('button', { name: /Generate with AI/ })).toBeInTheDocument()
  })
})
