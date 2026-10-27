import { fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import CommunicationDetail from './CommunicationDetail'
import Communications from './Communications'
import NewCommunication from './NewCommunication'
import CommunicationList from '../components/common/CommunicationList'
import * as communicationApi from '../lib/communications'
import * as attachmentApi from '../lib/attachments'
import type { Communication, CommunicationsPage } from '../types'

// `vi.mock` factories are hoisted above the imports, so fixtures must come from `vi.hoisted`.
const { sentMessage, loggedCall, draftMessage, failedMessage, book } = vi.hoisted(() => {
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
    // Slice S2: only a scheduled message carries a due moment; everything else has happened.
    scheduled_for: null,
    due: null,
    duration_minutes: null,
    outcome: null,
    external_id: null,
    failed_reason: null,
    thread_id: null,
    in_reply_to_id: null,
    in_reply_to: null,
    can_reply: true,
    owner_id: 1,
    owner: { id: 1, name: 'Sam Salesrep' },
    is_editable: false,
    can_send: false,
    can_attach: false,
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
    can_attach: true,
    can_update: true,
    // Slice S3: a logged touch still takes files, and this one already carries a scan.
    attachments: [
      {
        id: 'att-1',
        name: 'site-walk-notes.png',
        mime_type: 'image/png',
        size: 70,
        uploaded_by: 1,
        uploader: { id: 1, name: 'Sam Salesrep' },
        can_delete: true,
        created_at: '2026-10-17T02:30:00.000000Z',
      },
    ],
    attachments_count: 1,
  }

  const book: CommunicationsPage = {
    data: [sentMessage, loggedCall],
    meta: { current_page: 1, per_page: 25, total: 2, last_page: 1 },
    links: {},
    summary: {
      all: 2,
      scheduled: 0,
      by_direction: { outbound: 2 },
      by_channel: { email: 1, call: 1 },
      last_contacted_at: '2026-10-16T02:30:00.000000Z',
    },
  }

  // Slice S2: an outbound email the rep has written but not sent.
  const draftMessage: Communication = {
    ...base,
    id: 'com-3',
    reference: 'COM-2026-0003',
    status: 'draft',
    subject: 'Rate card for the Ortigas launch',
    is_editable: true,
    can_send: true,
    // A draft has not happened, so nothing can answer it (slice S4).
    can_reply: false,
  }

  // Slice S2: a send the gateway rejected, so it can be retried.
  const failedMessage: Communication = {
    ...base,
    id: 'com-4',
    reference: 'COM-2026-0004',
    status: 'failed',
    subject: 'Site visit schedule for the Ortigas floor',
    failed_reason: 'The mail provider rejected the message (mocked failure).',
    can_send: true,
    can_reply: false,
  }

  return { sentMessage, loggedCall, draftMessage, failedMessage, book }
})

// Module 04 slice S2: an outbound email written now, due tomorrow morning.
const scheduledMessage: Communication = {
  ...sentMessage,
  id: 'com-5',
  reference: 'COM-2026-0005',
  status: 'scheduled',
  subject: 'Rate sheet follow-up for the night shift',
  occurred_at: null,
  scheduled_for: '2026-10-20T01:00:00.000000Z',
  due: { state: 'upcoming', label: 'Upcoming', days: 1, overdue: false },
  is_editable: true,
  can_send: true,
  can_reply: false,
}

vi.mock('../lib/communications', () => ({
  listCommunications: vi.fn(),
  getCommunication: vi.fn(),
  createCommunication: vi.fn(),
  updateCommunication: vi.fn(),
  deleteCommunication: vi.fn(),
  restoreCommunication: vi.fn(),
  listCommunicationsFor: vi.fn(),
  sendCommunication: vi.fn(),
  retryCommunication: vi.fn(),
  syncInboundCommunications: vi.fn(),
  replyToCommunication: vi.fn(),
  listCommunicationThreads: vi.fn(),
  getCommunicationThread: vi.fn(),
}))

// Slice S3 — the detail page uploads, downloads and removes files through these.
vi.mock('../lib/attachments', () => ({
  uploadAttachment: vi.fn(),
  deleteAttachment: vi.fn(),
  downloadAttachment: vi.fn(),
}))

vi.mock('../lib/auth-context', () => ({
  useAuth: () => ({ user: { id: 1, name: 'Ada Admin', role: 'admin' }, logout: vi.fn() }),
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
      '/accounts/company-1',
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

  // Slice S2 — the mailbox is pulled, so the demo needs a way to ask for it.
  it('pulls the simulated mailbox and reports what arrived', async () => {
    vi.mocked(communicationApi.syncInboundCommunications).mockResolvedValue({
      recorded: 2,
      source: 'Email (mocked)',
    })

    render(
      <MemoryRouter>
        <Communications />
      </MemoryRouter>,
    )

    fireEvent.click(await screen.findByRole('button', { name: 'Pull replies' }))

    await waitFor(() => expect(communicationApi.syncInboundCommunications).toHaveBeenCalled())
    expect(
      await screen.findByText('2 replies pulled in from the simulated mailbox.'),
    ).toBeInTheDocument()
  })

  // Slice S3 — the book only needs to say that a message carries files.
  it('marks a message that carries files', async () => {
    render(
      <MemoryRouter>
        <Communications />
      </MemoryRouter>,
    )

    const entry = await screen.findByRole('link', { name: 'Call — commissary site walk arranged' })
    const row = entry.closest('tr') as HTMLElement

    expect(within(row).getByText('1 file')).toBeInTheDocument()
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

    // `scheduled` belongs to the follow-up module, and an outbound email is what may be put off
    // (module 04 §S2, spec 11 §Business rules).
    const status = screen.getByLabelText('Status')
    expect(within(status).getByRole('option', { name: 'Scheduled' })).toBeInTheDocument()
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

  // Slice S2 — only an outbound email is written first and sent later.
  it('offers a draft for an outbound email and saves it as one', async () => {
    vi.mocked(communicationApi.createCommunication).mockResolvedValue(draftMessage)

    render(
      <MemoryRouter
        initialEntries={['/communications/new?subject_type=company&subject_id=company-1']}
      >
        <Routes>
          <Route path="/communications/new" element={<NewCommunication />} />
        </Routes>
      </MemoryRouter>,
    )

    const status = await screen.findByLabelText('Status')
    expect(within(status).getByRole('option', { name: 'Draft' })).toBeInTheDocument()

    fireEvent.change(status, { target: { value: 'draft' } })
    expect(
      screen.getByText('A draft is saved but not sent — you can send it from its own page.'),
    ).toBeInTheDocument()

    fireEvent.change(screen.getByLabelText('Subject'), {
      target: { value: 'Rate card for the Ortigas launch' },
    })
    fireEvent.click(screen.getByRole('button', { name: 'Save draft' }))

    await waitFor(() =>
      expect(communicationApi.createCommunication).toHaveBeenCalledWith(
        expect.objectContaining({ status: 'draft', subject: 'Rate card for the Ortigas launch' }),
      ),
    )
  })

  it('drops the draft option when the channel can no longer have one', async () => {
    render(
      <MemoryRouter
        initialEntries={['/communications/new?subject_type=company&subject_id=company-1']}
      >
        <Routes>
          <Route path="/communications/new" element={<NewCommunication />} />
        </Routes>
      </MemoryRouter>,
    )

    const status = await screen.findByLabelText('Status')
    fireEvent.change(status, { target: { value: 'draft' } })
    fireEvent.change(screen.getByLabelText('Channel'), { target: { value: 'call' } })

    await waitFor(() => expect(screen.getByLabelText('Status')).toHaveValue('logged'))
    expect(
      within(screen.getByLabelText('Status')).queryByRole('option', { name: 'Draft' }),
    ).not.toBeInTheDocument()
    // Nor is a message that has not gone out: a call is not put off to a later moment (S2).
    expect(
      within(screen.getByLabelText('Status')).queryByRole('option', { name: 'Scheduled' }),
    ).not.toBeInTheDocument()
  })

  // Module 04 slice S2 — an outbound email may be written now and sent when it falls due.
  it('schedules an outbound email for later, with the moment it owes', async () => {
    vi.mocked(communicationApi.createCommunication).mockResolvedValue(scheduledMessage)

    render(
      <MemoryRouter
        initialEntries={['/communications/new?subject_type=company&subject_id=company-1']}
      >
        <Routes>
          <Route path="/communications/new" element={<NewCommunication />} />
        </Routes>
      </MemoryRouter>,
    )

    const status = await screen.findByLabelText('Status')
    fireEvent.change(status, { target: { value: 'scheduled' } })

    // The form asks when it goes out rather than when it happened, and names the default.
    const goesOut = screen.getByLabelText('Goes out')
    expect((goesOut as HTMLInputElement).value).toMatch(/^\d{4}-\d{2}-\d{2}T09:00$/)

    fireEvent.change(screen.getByLabelText('Subject'), {
      target: { value: 'Rate sheet follow-up for the night shift' },
    })
    fireEvent.change(goesOut, { target: { value: '2026-10-20T09:00' } })
    fireEvent.click(screen.getByRole('button', { name: 'Schedule message' }))

    await waitFor(() =>
      expect(communicationApi.createCommunication).toHaveBeenCalledWith(
        expect.objectContaining({
          status: 'scheduled',
          // It has not happened, so it carries no `occurred_at`, only when it is owed.
          occurred_at: null,
          scheduled_for: new Date('2026-10-20T09:00').toISOString(),
        }),
      ),
    )
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
      '/accounts/company-1',
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

  it('sends a draft through the mocked gateway', async () => {
    vi.mocked(communicationApi.getCommunication).mockResolvedValue(draftMessage)
    vi.mocked(communicationApi.sendCommunication).mockResolvedValue({
      ...draftMessage,
      status: 'sent',
      external_id: 'EMAIL-COM-2026-0003',
      is_editable: false,
      can_send: false,
    })

    renderDetail('com-3')

    expect(await screen.findByText('Draft — not sent yet')).toBeInTheDocument()
    fireEvent.click(screen.getByRole('button', { name: 'Send now' }))

    await waitFor(() => expect(communicationApi.sendCommunication).toHaveBeenCalledWith('com-3'))
    // The page reports where it went: the simulated mailbox, not a mail provider.
    await waitFor(() =>
      expect(screen.getAllByText(/EMAIL-COM-2026-0003/).length).toBeGreaterThan(0),
    )
  })

  // Module 04 slice S2 — a message written now and sent when it falls due.
  it('shows a scheduled message as not yet gone out, and sends it early', async () => {
    vi.mocked(communicationApi.getCommunication).mockResolvedValue(scheduledMessage)
    vi.mocked(communicationApi.sendCommunication).mockResolvedValue({
      ...scheduledMessage,
      status: 'sent',
      external_id: 'EMAIL-COM-2026-0005',
      occurred_at: '2026-10-19T06:00:00.000000Z',
      is_editable: false,
      can_send: false,
      due: null,
    })

    renderDetail('com-5')

    expect(await screen.findByText('Scheduled — not sent yet')).toBeInTheDocument()
    // The banner names when it is owed and how due it is, so the reader need not work it out.
    expect(screen.getByText(/Written now, going out .* \(upcoming\)/)).toBeInTheDocument()

    fireEvent.click(screen.getByRole('button', { name: 'Send now' }))

    await waitFor(() => expect(communicationApi.sendCommunication).toHaveBeenCalledWith('com-5'))
    await waitFor(() =>
      expect(screen.getAllByText(/EMAIL-COM-2026-0005/).length).toBeGreaterThan(0),
    )
  })

  it('retries a send the gateway rejected', async () => {
    vi.mocked(communicationApi.getCommunication).mockResolvedValue(failedMessage)
    vi.mocked(communicationApi.retryCommunication).mockResolvedValue({
      ...failedMessage,
      status: 'sent',
      external_id: 'EMAIL-COM-2026-0004',
      failed_reason: null,
      can_send: false,
    })

    renderDetail('com-4')

    expect(await screen.findByText('Delivery failed')).toBeInTheDocument()
    fireEvent.click(screen.getByRole('button', { name: 'Retry send' }))

    await waitFor(() => expect(communicationApi.retryCommunication).toHaveBeenCalledWith('com-4'))
  })

  it('marks a gateway email as carried by the simulated mailbox', async () => {
    vi.mocked(communicationApi.getCommunication).mockResolvedValue({
      ...sentMessage,
      status: 'sent',
      external_id: 'EMAIL-COM-2026-0001',
    })

    renderDetail()

    expect(await screen.findByText('Simulated mailbox')).toBeInTheDocument()
    expect(screen.getByText(/Sent through the mocked email gateway/)).toBeInTheDocument()
  })

  // Slice S3 — a logged touch is still the rep's to work, so it takes files and shows them.
  it('attaches a file to a message and lists it', async () => {
    vi.mocked(communicationApi.getCommunication).mockResolvedValue(loggedCall)
    vi.mocked(attachmentApi.uploadAttachment).mockResolvedValue({
      id: 'att-2',
      name: 'rate-card.pdf',
      mime_type: 'application/pdf',
      size: 120 * 1024,
      uploaded_by: 1,
      uploader: { id: 1, name: 'Sam Salesrep' },
      can_delete: true,
      created_at: '2026-10-18T02:30:00.000000Z',
    })

    const { container } = renderDetail('com-2')

    // The file the message already carries comes back from the API.
    expect(await screen.findByText('site-walk-notes.png')).toBeInTheDocument()
    expect(screen.getByText(/^70 B/)).toBeInTheDocument()

    const file = new File(['%PDF-1.4'], 'rate-card.pdf', { type: 'application/pdf' })
    const input = container.querySelector('input[type="file"]') as HTMLInputElement
    fireEvent.change(input, { target: { files: [file] } })
    fireEvent.click(screen.getByRole('button', { name: 'Attach file' }))

    await waitFor(() => expect(attachmentApi.uploadAttachment).toHaveBeenCalledWith('com-2', file))
    expect(await screen.findByText('rate-card.pdf')).toBeInTheDocument()
    expect(screen.getByText(/^120 KB/)).toBeInTheDocument()
  })

  it('downloads a file through the authorized endpoint', async () => {
    vi.mocked(communicationApi.getCommunication).mockResolvedValue(loggedCall)

    renderDetail('com-2')

    fireEvent.click(await screen.findByRole('button', { name: 'Download site-walk-notes.png' }))

    await waitFor(() => expect(attachmentApi.downloadAttachment).toHaveBeenCalled())
  })

  it('removes a file the message still allows', async () => {
    vi.mocked(communicationApi.getCommunication).mockResolvedValue(loggedCall)

    renderDetail('com-2')

    fireEvent.click(await screen.findByRole('button', { name: 'Remove site-walk-notes.png' }))

    await waitFor(() => expect(attachmentApi.deleteAttachment).toHaveBeenCalledWith('att-1'))
    await waitFor(() => expect(screen.queryByText('site-walk-notes.png')).not.toBeInTheDocument())
  })

  // Slice S3 — files are part of the record of what was said, so a sent message keeps them.
  it('keeps the files a sent message carried and offers no upload', async () => {
    vi.mocked(communicationApi.getCommunication).mockResolvedValue({
      ...sentMessage,
      attachments: [
        {
          id: 'att-9',
          name: 'rate-card-scan.png',
          mime_type: 'image/png',
          size: 70,
          uploaded_by: 1,
          uploader: { id: 1, name: 'Sam Salesrep' },
          can_delete: false,
          created_at: '2026-10-16T02:30:00.000000Z',
        },
      ],
      attachments_count: 1,
    })

    const { container } = renderDetail()

    expect(await screen.findByText('rate-card-scan.png')).toBeInTheDocument()
    expect(screen.getByText('A sent message keeps the files it carried.')).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Attach file' })).not.toBeInTheDocument()
    expect(container.querySelector('input[type="file"]')).toBeNull()
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

// Slice S4 — threading: a message knows what it answers and which conversation it sits in.
describe('Threading a message', () => {
  const renderDetail = (id = 'com-1') =>
    render(
      <MemoryRouter initialEntries={[`/communications/${id}`]}>
        <Routes>
          <Route path="/communications/:id" element={<CommunicationDetail />} />
        </Routes>
      </MemoryRouter>,
    )

  it('offers to answer a message that went out and links to the conversation it sits in', async () => {
    vi.mocked(communicationApi.getCommunication).mockResolvedValue({
      ...sentMessage,
      thread_id: 'com-1',
      in_reply_to: { id: 'com-9', reference: 'COM-2026-0009', subject: 'The original ask' },
      in_reply_to_id: 'com-9',
    })

    renderDetail()

    expect(await screen.findByRole('link', { name: 'Reply' })).toHaveAttribute(
      'href',
      '/communications/new?reply_to=com-1',
    )
    expect(screen.getByRole('link', { name: 'Part of a conversation' })).toHaveAttribute(
      'href',
      '/communications/threads/com-1',
    )
    expect(screen.getByRole('link', { name: 'COM-2026-0009' })).toHaveAttribute(
      'href',
      '/communications/com-9',
    )
  })

  it('offers no reply control for a message that never went out', async () => {
    vi.mocked(communicationApi.getCommunication).mockResolvedValue(draftMessage)

    renderDetail('com-3')

    expect(await screen.findByText('Draft — not sent yet')).toBeInTheDocument()
    expect(screen.queryByRole('link', { name: 'Reply' })).not.toBeInTheDocument()
    expect(screen.queryByRole('link', { name: 'Part of a conversation' })).not.toBeInTheDocument()
  })

  it('marks a message that belongs to a conversation in the book', async () => {
    vi.mocked(communicationApi.listCommunications).mockResolvedValue({
      ...book,
      data: [{ ...sentMessage, thread_id: 'com-1' }],
    })

    render(
      <MemoryRouter>
        <Communications />
      </MemoryRouter>,
    )

    expect(await screen.findByRole('link', { name: 'In a conversation' })).toHaveAttribute(
      'href',
      '/communications/threads/com-1',
    )
  })
})

// Slice S4 — the reply form is the log form started from a message.
describe('Replying to a message', () => {
  const renderReply = (id = 'com-1') =>
    render(
      <MemoryRouter initialEntries={[`/communications/new?reply_to=${id}`]}>
        <Routes>
          <Route path="/communications/new" element={<NewCommunication />} />
        </Routes>
      </MemoryRouter>,
    )

  it('starts from the message it answers and posts through the reply endpoint', async () => {
    renderReply()

    // The record comes from the parent and cannot be picked: a reply never changes hands.
    expect(await screen.findByRole('link', { name: /COM-2026-0001/ })).toHaveAttribute(
      'href',
      '/communications/com-1',
    )
    expect(screen.queryByLabelText('Record type')).not.toBeInTheDocument()

    // An email threads under Re:, and the answer goes to whoever the parent was with.
    expect(screen.getByLabelText('Subject')).toHaveValue('Re: Revised rate sheet')
    expect(screen.getByLabelText('Channel')).toHaveValue('email')
    expect(screen.getByLabelText('To name')).toHaveValue('Rosa Dela Cruz')

    fireEvent.change(screen.getByLabelText('Body'), { target: { value: 'The rates hold.' } })
    fireEvent.click(screen.getByRole('button', { name: 'Log reply' }))

    await waitFor(() =>
      expect(communicationApi.replyToCommunication).toHaveBeenCalledWith(
        'com-1',
        expect.objectContaining({ subject: 'Re: Revised rate sheet', body: 'The rates hold.' }),
      ),
    )
    // The record is the parent's, so it is never sent (the API rejects one that is).
    const payload = vi.mocked(communicationApi.replyToCommunication).mock.calls[0][1]
    expect(payload).not.toHaveProperty('subject_type')
    expect(payload).not.toHaveProperty('subject_id')
    expect(communicationApi.createCommunication).not.toHaveBeenCalled()
  })

  it('saves an outbound email reply as a draft first', async () => {
    vi.mocked(communicationApi.replyToCommunication).mockResolvedValue(draftMessage)

    renderReply()

    await screen.findByLabelText('Status')
    fireEvent.change(screen.getByLabelText('Status'), { target: { value: 'draft' } })
    fireEvent.click(screen.getByRole('button', { name: 'Save draft reply' }))

    await waitFor(() =>
      expect(communicationApi.replyToCommunication).toHaveBeenCalledWith(
        'com-1',
        expect.objectContaining({ status: 'draft' }),
      ),
    )
  })
})
