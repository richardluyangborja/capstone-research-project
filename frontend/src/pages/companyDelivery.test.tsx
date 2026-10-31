import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import CompanyDeliveryPanel from '../components/common/CompanyDeliveryPanel'
import * as deliveryApi from '../lib/deliveries'
import type { CompanyDelivery } from '../types'

const { activeDelivery, emptyDelivery } = vi.hoisted(() => {
  const activeDelivery: CompanyDelivery = {
    data: {
      handoff: {
        status: 'sent',
        reference: 'SVC-CON-2026-0001',
        error: null,
        requested_at: '2026-10-01T08:00:00+00:00',
        can_retry: false,
      },
      job_orders: [
        {
          reference: 'JO-2026-0014',
          role: 'Warehouse associate',
          requested: 6,
          filled: 6,
          status: 'filled',
          priority: 'medium',
          target_start_date: '2026-11-03',
          recruiter: 'Recruitment (mocked)',
          last_updated_at: '2026-10-17T08:00:00+00:00',
        },
      ],
      deployments: [
        {
          reference: 'DEP-2026-0021',
          contract_reference: 'CON-2026-0001',
          role: 'Warehouse associate',
          site: 'Manila South Harbor office',
          status: 'active',
          active_headcount: 6,
          requested_headcount: 6,
          start_date: '2026-10-01',
          end_date: '2027-10-01',
          fill_rate: 100,
          timesheet: {
            period_start: '2026-10-01',
            period_end: '2026-10-31',
            approved_hours: 1056,
            pending_hours: 0,
            last_submitted_at: '2026-10-29T08:00:00+00:00',
            status: 'current',
          },
          operations_owner: 'Deployment operations (mocked)',
          last_updated_at: '2026-10-17T08:00:00+00:00',
        },
      ],
      timesheets: {
        approved_hours: 1056,
        pending_hours: 0,
        deployments_pending: 0,
      },
    },
    meta: {
      source: 'Recruitment / Deployment / Timekeeping (mocked, read-only)',
      read_only: true,
      as_of: '2026-10-31T08:00:00+00:00',
    },
  }

  const emptyDelivery: CompanyDelivery = {
    data: {
      handoff: {
        status: null,
        reference: null,
        error: null,
        requested_at: null,
        can_retry: false,
      },
      job_orders: [],
      deployments: [],
      timesheets: { approved_hours: 0, pending_hours: 0, deployments_pending: 0 },
    },
    meta: {
      source: 'Recruitment / Deployment / Timekeeping (mocked, read-only)',
      read_only: true,
      as_of: '2026-10-31T08:00:00+00:00',
    },
  }

  return { activeDelivery, emptyDelivery }
})

vi.mock('../lib/deliveries', () => ({
  getCompanyDelivery: vi.fn(),
}))

describe('CompanyDeliveryPanel', () => {
  beforeEach(() => {
    vi.mocked(deliveryApi.getCompanyDelivery).mockReset()
    vi.mocked(deliveryApi.getCompanyDelivery).mockResolvedValue(activeDelivery)
  })

  it('renders the read-only delivery summary, source and the feeds', async () => {
    render(<CompanyDeliveryPanel companyId="company-1" />)

    expect(await screen.findByText('Read-only feed')).toBeInTheDocument()
    expect(
      screen.getByText(/Recruitment \/ Deployment \/ Timekeeping \(mocked, read-only\)/),
    ).toBeInTheDocument()
    expect(screen.getByText('As of', { exact: false })).toBeInTheDocument()
    expect(screen.getByText('Accepted')).toBeInTheDocument()
    expect(screen.getByText('Service handoff')).toBeInTheDocument()
    expect(screen.getAllByText('Job orders').length).toBeGreaterThan(0)
    expect(screen.getByText('Active deployments')).toBeInTheDocument()
    expect(screen.getByText('Timesheets pending')).toBeInTheDocument()
    expect(screen.getAllByText('Warehouse associate').length).toBeGreaterThan(0)
    expect(screen.getByText('Manila South Harbor office')).toBeInTheDocument()
    expect(screen.getAllByText('Filled').length).toBeGreaterThan(0)
    expect(screen.getByText('Active')).toBeInTheDocument()
    expect(screen.getByText('Current')).toBeInTheDocument()
    expect(screen.getAllByText('6 / 6').length).toBeGreaterThan(0)
    expect(screen.getByText('100%')).toBeInTheDocument()
    expect(screen.getByText('1,056 hrs approved')).toBeInTheDocument()
    expect(
      screen.queryByRole('button', { name: /create|edit|delete|approve/i }),
    ).not.toBeInTheDocument()
  })

  it('explains an account with no signed delivery yet', async () => {
    vi.mocked(deliveryApi.getCompanyDelivery).mockResolvedValue(emptyDelivery)

    render(<CompanyDeliveryPanel companyId="company-1" />)

    expect(await screen.findByText('No job orders yet')).toBeInTheDocument()
    expect(screen.getByText('No deployments yet')).toBeInTheDocument()
    expect(screen.getByText('Not requested')).toBeInTheDocument()
    expect(screen.getByText('0% overall fill · 0 total')).toBeInTheDocument()
  })

  it('offers a retry when the feed is unavailable', async () => {
    vi.mocked(deliveryApi.getCompanyDelivery)
      .mockRejectedValueOnce(new Error('down'))
      .mockResolvedValueOnce(activeDelivery)

    render(<CompanyDeliveryPanel companyId="company-1" />)

    expect(await screen.findByText('Could not load the delivery feed.')).toBeInTheDocument()
    fireEvent.click(screen.getByRole('button', { name: 'Retry feed' }))

    await waitFor(() => expect(screen.getByText('Read-only feed')).toBeInTheDocument())
    expect(screen.getByText('Accepted')).toBeInTheDocument()
  })
})
