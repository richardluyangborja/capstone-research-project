import { fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import CommunicationDetail from './CommunicationDetail'
import Communications from './Communications'
import NewCommunication from './NewCommunication'
import CommunicationList from '../components/common/CommunicationList'
import * as communicationApi from '../lib/communications'
import type { Communication, CommunicationsPage } from '../types'

// `vi.mock` factories are hoisted above the imports, so fixtures must come from `vi.hoisted`.
const { sentMessage, loggedCall, book } = vi.hoisted(() => {
  const base = {
    id: 'com-1',
    reference: 'COM-2026-0001',
    subject_type: 'company',
    subject_id: 'company-1',
    record: { type: 'company', id: 'company-1', label: 'Sunrise Foods Inc' },
    contact_id: null,
    contact: null,
    channel: 'email',
    direction: 'outbound',
    status: 'sent',
    subject: 'Revised rate sheet',
    body: 'Rates hold for 30 days.',
    from_name: 'Sam Salesrep',
    from_address: 'sales@primepower.test',
    to_name: 'Rosa Dela Cruz',
    to_address: 'rosa@sunrise.test',
    occurred_at: '2026-10-16T02:30:00.000000Z',
    duration_minutes: null,
    outcome: null,
    external_id: null,
    failed_reason: null,
    thread_id: null,
    owner_id: 1,
    owner: { id: 1, name: 'Sam Salesrep' },
    is_editable: false,
    can_update: true,
    can_delete: true,
    can_restore: false,
    deleted_at: null,
    created_at: '2026-10-16T02:30:00.000000Z',
    updated_at: '2026-10-16T02:30:00.000000Z',
  } satisfies Communication

  const sentMessage: Communication = base

  // A call is logged after the fact, so its author can still correct it (ADR-0017 §6).
  const loggedCall: Communication = {
    ...base,
    id: 'com-2',
    reference: 'COM-2026-0002',
    channel: 'call',
    status: 'logged',
    subject: 'Call — commissary site walk arranged',
    body: null,
    duration_minutes: 12,
    outcome: 'connected',
    is_editable: true,
    can_update: true,
  }

  const book: CommunicationsPage = {
    data: [sentMessage, loggedCall],
    meta: { current_page: 1, per_page: 25, total: 2, last_page: 1 },
    links: {},
    summary: {
      all: 2,
      by_direction: { outbound: 2 },
      by_channel: { email: 1, call: 1 },
      last_contacted_at: '2026-10-16T02:30:00.000000Z',
    },
  }

  return { sentMessage, loggedCall, book }
})

vi.mock('../lib/communications', () => ({
  listCommunications: vi.fn(),
  getCommunication: vi.fn(),
  createCommunication: vi.fn(),
  updateCommunication: vi.fn(),
  deleteCommunication: vi.fn(),
  restoreCommunication: vi.fn(),
  listCommunicationsFor: vi.fn(),
}))

vi.mock('../lib/leads', () => ({
  getLead: vi.fn(),
  listLeads: vi.fn().mockResolvedValue({ data: [], meta: {}, links: {} }),
  parseValidationError: vi.fn().mockReturnValue(null),
}))

vi.mock('../lib/companies', () => ({
  getCompany: vi.fn().mockResolvedValue({
    id: 'company-1',
    reference: 'CO-2026-0001',
    name: 'Sunrise Foods Inc',
  }),
  listCompanies: vi.fn().mockResolvedValue({ data: [], meta: {}, links: {} }),
}))

vi.mock('../lib/contacts', () => ({
  getContact: vi.fn(),
  listContacts: vi.fn().mockResolvedValue({ data: [], meta: {}, links: {} }),
  listCompanyContacts: vi.fn().mockResolvedValue([]),
}))

vi.mock('../lib/opportunities', () => ({
  getOpportunity: vi.fn(),
  listOpportunities: vi.fn().mockResolvedValue({ data: [], meta: {}, links: {} }),
}))

beforeEach(() => {
  vi.mocked(communicationApi.listCommunications).mockResolvedValue(book)
  vi.mocked(communicationApi.listCommunicationsFor).mockResolvedValue({
    data: [sentMessage],
    meta: { total: 1 },
  })
  vi.mocked(communicationApi.getCommunication).mockResolvedValue(sentMessage)
  vi.mocked(communicationApi.createCommunication).mockResolvedValue(sentMessage)
  vi.mocked(communicationApi.updateCommunication).mockResolvedValue(loggedCall)
})

describe('Communication history book', () => {
  it('lists entries with a link to each record and the header summary', async () => {
    render(
      <MemoryRouter>
        <Communications />
      </MemoryRouter>,
    )

    const entry = await screen.findByRole('link', { name: 'Revised rate sheet' })
    expect(entry).toHaveAttribute('href', '/communications/com-1')

    const row = entry.closest('tr') as HTMLElement
    expect(within(row).getByText('COM-2026-0001')).toBeInTheDocument()
    expect(within(row).getByText('Email')).toBeInTheDocument()
    expect(within(row).getByText('Outbound')).toBeInTheDocument()
    expect(within(row).getByRole('link', { name: 'Sunrise Foods Inc' })).toHaveAttribute(
      'href',
      '/companies/company-1',
    )

    expect(screen.getByText('All entries')).toBeInTheDocument()
    expect(screen.getByText('Last contacted')).toBeInTheDocument()
  })

  it('sends the channel filter to the API', async () => {
    render(
      <MemoryRouter>
        <Communications />
      </MemoryRouter>,
    )

    await screen.findByRole('link', { name: 'Revised rate sheet' })

    fireEvent.change(screen.getByLabelText('Channel filter'), { target: { value: 'email' } })
    fireEvent.click(screen.getByRole('button', { name: 'Filter' }))

    await waitFor(() =>
      expect(communicationApi.listCommunications).toHaveBeenLastCalledWith(
        expect.objectContaining({ channel: 'email' }),
      ),
    )
  })
})

describe('Log communication', () => {
  it('prefills the linked record and posts the mapped payload', async () => {
    render(
      <MemoryRouter
        initialEntries={['/communications/new?subject_type=company&subject_id=company-1']}
      >
        <Routes>
          <Route path="/communications/new" element={<NewCommunication />} />
        </Routes>
      </MemoryRouter>,
    )

    // The label comes from the record itself, not the URL.
    expect(await screen.findByText('Sunrise Foods Inc')).toBeInTheDocument()

    // `scheduled` belongs to the follow-up module and is never offered (spec 11 §Business rules).
    const status = screen.getByLabelText('Status')
    expect(within(status).queryByRole('option', { name: 'Scheduled' })).not.toBeInTheDocument()
    // An outbound email defaults to Sent.
    expect(status).toHaveValue('sent')

    fireEvent.change(screen.getByLabelText('Subject'), {
      target: { value: 'Revised rate sheet and crew schedule' },
    })
    fireEvent.change(screen.getByLabelText('Body'), {
      target: { value: 'Attached is the revised rate sheet.' },
    })
    fireEvent.click(screen.getByRole('button', { name: 'Log communication' }))

    await waitFor(() =>
      expect(communicationApi.createCommunication).toHaveBeenCalledWith(
        expect.objectContaining({
          subject_type: 'company',
          subject_id: 'company-1',
          channel: 'email',
          direction: 'outbound',
          status: 'sent',
          subject: 'Revised rate sheet and crew schedule',
          body: 'Attached is the revised rate sheet.',
        }),
      ),
    )
  })

  it('asks for the record when none is chosen', async () => {
    render(
      <MemoryRouter initialEntries={['/communications/new?subject_type=company']}>
        <Routes>
          <Route path="/communications/new" element={<NewCommunication />} />
        </Routes>
      </MemoryRouter>,
    )

    fireEvent.click(await screen.findByRole('button', { name: 'Log communication' }))

    expect(
      await screen.findByText('Pick the record this communication belongs to.'),
    ).toBeInTheDocument()
    expect(communicationApi.createCommunication).not.toHaveBeenCalled()
  })
})

describe('Communication detail', () => {
  const renderDetail = (id = 'com-1') =>
    render(
      <MemoryRouter initialEntries={[`/communications/${id}`]}>
        <Routes>
          <Route path="/communications/:id" element={<CommunicationDetail />} />
        </Routes>
      </MemoryRouter>,
    )

  it('shows the body and the record it belongs to, and offers no editor for a sent message', async () => {
    renderDetail()

    expect(await screen.findByText('Rates hold for 30 days.')).toBeInTheDocument()
    expect(screen.getByRole('link', { name: 'Sunrise Foods Inc' })).toHaveAttribute(
      'href',
      '/companies/company-1',
    )
    // A sent message is a record of what was said — it cannot be edited (ADR-0017 §6).
    expect(screen.queryByRole('button', { name: 'Edit' })).not.toBeInTheDocument()
  })

  it('corrects a logged entry through the editor', async () => {
    vi.mocked(communicationApi.getCommunication).mockResolvedValue(loggedCall)
    renderDetail('com-2')

    fireEvent.click(await screen.findByRole('button', { name: 'Edit' }))

    const title = screen.getByLabelText('Subject')
    expect(title).toHaveValue('Call — commissary site walk arranged')

    fireEvent.change(title, { target: { value: 'Call — site walk moved to Friday' } })
    fireEvent.click(screen.getByRole('button', { name: 'Save changes' }))

    await waitFor(() =>
      expect(communicationApi.updateCommunication).toHaveBeenCalledWith(
        'com-2',
        expect.objectContaining({ subject: 'Call — site walk moved to Friday' }),
      ),
    )
  })
})

describe('Record history panel', () => {
  it('renders a record’s history and links to logging a new one', async () => {
    render(
      <MemoryRouter>
        <CommunicationList subjectType="company" subjectId="company-1" canLog />
      </MemoryRouter>,
    )

    expect(await screen.findByRole('link', { name: 'Revised rate sheet' })).toBeInTheDocument()
    expect(screen.getByRole('link', { name: /log communication/i })).toHaveAttribute(
      'href',
      '/communications/new?subject_type=company&subject_id=company-1',
    )
    expect(communicationApi.listCommunicationsFor).toHaveBeenCalledWith('company', 'company-1')
  })
})
