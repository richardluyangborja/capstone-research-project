import { fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import CommunicationThreadDetail from './CommunicationThreadDetail'
import CommunicationThreads from './CommunicationThreads'
import * as communicationApi from '../lib/communications'
import type { Communication, CommunicationThread } from '../types'

// `vi.mock` factories are hoisted above the imports, so fixtures must come from `vi.hoisted`.
const { root, conversation, thread } = vi.hoisted(() => {
  const base = {
    subject_type: 'opportunity' as const,
    subject_id: 'deal-1',
    record: {
      type: 'opportunity' as const,
      id: 'deal-1',
      label: 'BPO Solutions PH — 40-seat ramp',
    },
    contact_id: null,
    contact: null,
    from_name: null,
    from_address: null,
    to_name: null,
    to_address: null,
    duration_minutes: null,
    outcome: null,
    external_id: null,
    failed_reason: null,
    thread_id: 'com-1',
    owner_id: 1,
    owner: { id: 1, name: 'Sam Salesrep' },
    created_at: '2026-10-14T02:30:00.000000Z',
    updated_at: '2026-10-14T02:30:00.000000Z',
    deleted_at: null,
    is_editable: false,
    can_send: false,
    can_attach: false,
    can_reply: true,
    can_update: true,
    can_delete: true,
    can_restore: false,
    attachments_count: 0,
  }

  // The message that started the exchange, and the answer that made it a conversation.
  const root: Communication = {
    ...base,
    id: 'com-1',
    reference: 'COM-2026-0001',
    channel: 'email',
    direction: 'outbound',
    status: 'sent',
    subject: 'Commercial terms for the 40-seat ramp',
    body: 'Draft terms attached.',
    in_reply_to_id: null,
    in_reply_to: null,
    occurred_at: '2026-10-14T02:30:00.000000Z',
  }

  const answer: Communication = {
    ...base,
    id: 'com-2',
    reference: 'COM-2026-0002',
    channel: 'email',
    direction: 'inbound',
    status: 'received',
    subject: 'Re: Commercial terms for the 40-seat ramp',
    body: 'Legal is comfortable with the ramp-down clause.',
    from_name: 'Bea Buyer',
    from_address: 'bea@bpo.test',
    in_reply_to_id: 'com-1',
    in_reply_to: {
      id: 'com-1',
      reference: 'COM-2026-0001',
      subject: 'Commercial terms for the 40-seat ramp',
    },
    occurred_at: '2026-10-15T02:30:00.000000Z',
  }

  // Our answer, still to be written — the one the page offers to continue.
  const ourReply: Communication = {
    ...base,
    id: 'com-3',
    reference: 'COM-2026-0003',
    channel: 'email',
    direction: 'outbound',
    status: 'sent',
    subject: 'Re: Commercial terms for the 40-seat ramp',
    body: 'Understood — we can move the start date.',
    to_name: 'Bea Buyer',
    to_address: 'bea@bpo.test',
    in_reply_to_id: 'com-2',
    in_reply_to: {
      id: 'com-2',
      reference: 'COM-2026-0002',
      subject: 'Re: Commercial terms for the 40-seat ramp',
    },
    occurred_at: '2026-10-16T02:30:00.000000Z',
  }

  const conversation: CommunicationThread = {
    thread_id: 'com-1',
    title: 'Commercial terms for the 40-seat ramp',
    record: { type: 'opportunity', id: 'deal-1', label: 'BPO Solutions PH — 40-seat ramp' },
    channel: 'email',
    message_count: 3,
    first_at: '2026-10-14T02:30:00.000000Z',
    last_at: '2026-10-16T02:30:00.000000Z',
    last_message: {
      id: 'com-3',
      reference: 'COM-2026-0003',
      direction: 'outbound',
      status: 'sent',
      from_name: 'Sam Salesrep',
      occurred_at: '2026-10-16T02:30:00.000000Z',
    },
  }

  const thread = {
    thread_id: 'com-1',
    title: 'Commercial terms for the 40-seat ramp',
    record: {
      type: 'opportunity' as const,
      id: 'deal-1',
      label: 'BPO Solutions PH — 40-seat ramp',
    },
    channel: 'email' as const,
    message_count: 3,
    first_at: '2026-10-14T02:30:00.000000Z',
    last_at: '2026-10-16T02:30:00.000000Z',
    messages: [root, answer, ourReply],
  }

  return { root, answer, ourReply, conversation, thread }
})

vi.mock('../lib/communications', () => ({
  listCommunicationThreads: vi.fn(),
  getCommunicationThread: vi.fn(),
  getCommunication: vi.fn(),
  replyToCommunication: vi.fn(),
}))

beforeEach(() => {
  vi.mocked(communicationApi.listCommunicationThreads).mockResolvedValue({
    data: [conversation],
    meta: { current_page: 1, per_page: 25, total: 1, last_page: 1 },
  })
  vi.mocked(communicationApi.getCommunicationThread).mockResolvedValue(thread)
})

describe('Conversations', () => {
  it('lists each exchange with its record, channel and size', async () => {
    render(
      <MemoryRouter>
        <CommunicationThreads />
      </MemoryRouter>,
    )

    const title = await screen.findByRole('link', {
      name: 'Commercial terms for the 40-seat ramp',
    })
    expect(title).toHaveAttribute('href', '/communications/threads/com-1')

    const row = title.closest('tr') as HTMLElement
    expect(within(row).getByText('3 messages')).toBeInTheDocument()
    expect(
      within(row).getByRole('link', { name: 'BPO Solutions PH — 40-seat ramp' }),
    ).toHaveAttribute('href', '/opportunities/deal-1')
    expect(within(row).getByText('Email')).toBeInTheDocument()
    // The last word is named, so the list says who is waiting on whom.
    expect(within(row).getByText('COM-2026-0003')).toBeInTheDocument()
  })

  it('narrows the list by channel and search', async () => {
    render(
      <MemoryRouter>
        <CommunicationThreads />
      </MemoryRouter>,
    )

    await screen.findByRole('link', { name: 'Commercial terms for the 40-seat ramp' })

    fireEvent.change(screen.getByLabelText('Channel filter'), { target: { value: 'email' } })
    fireEvent.change(screen.getByPlaceholderText("Search a conversation's messages"), {
      target: { value: 'ramp' },
    })
    fireEvent.click(screen.getByRole('button', { name: 'Filter' }))

    await waitFor(() =>
      expect(communicationApi.listCommunicationThreads).toHaveBeenLastCalledWith(
        expect.objectContaining({ channel: 'email', search: 'ramp' }),
      ),
    )
  })

  it('explains that a conversation starts with an answer', async () => {
    vi.mocked(communicationApi.listCommunicationThreads).mockResolvedValue({
      data: [],
      meta: { current_page: 1, per_page: 25, total: 0, last_page: 1 },
    })

    render(
      <MemoryRouter>
        <CommunicationThreads />
      </MemoryRouter>,
    )

    expect(await screen.findByText('No conversations yet')).toBeInTheDocument()
    expect(screen.getByRole('link', { name: 'Open the history book' })).toHaveAttribute(
      'href',
      '/communications',
    )
  })
})

describe('One conversation', () => {
  const renderThread = () =>
    render(
      <MemoryRouter initialEntries={['/communications/threads/com-1']}>
        <Routes>
          <Route path="/communications/threads/:threadId" element={<CommunicationThreadDetail />} />
        </Routes>
      </MemoryRouter>,
    )

  it('reads the exchange back in order, naming what each message answers', async () => {
    renderThread()

    expect(
      await screen.findByRole('link', { name: 'Commercial terms for the 40-seat ramp' }),
    ).toBeInTheDocument()
    expect(screen.getByText(/^3 messages · .+ → .+/)).toBeInTheDocument()

    // The original first, then the two answers — each heading the message it belongs to.
    expect(
      screen.getAllByRole('link', { name: 'Re: Commercial terms for the 40-seat ramp' }),
    ).toHaveLength(2)
    expect(screen.getByText('Started the conversation')).toBeInTheDocument()
    expect(screen.getByText('Legal is comfortable with the ramp-down clause.')).toBeInTheDocument()
    expect(screen.getByText('Understood — we can move the start date.')).toBeInTheDocument()

    const messageLinks = screen.getAllByRole('link', { name: 'Open message' })
    expect(messageLinks).toHaveLength(3)
    expect(messageLinks[0]).toHaveAttribute('href', '/communications/com-1')
  })

  it('offers to continue the conversation from any message that can be answered', async () => {
    renderThread()

    // The header answers the last message; each message carries its own reply link.
    const replies = await screen.findAllByRole('link', { name: 'Reply' })
    expect(replies).toHaveLength(4)
    expect(replies[0]).toHaveAttribute('href', '/communications/new?reply_to=com-3')
    expect(screen.getByRole('link', { name: 'Conversations' })).toHaveAttribute(
      'href',
      '/communications/threads',
    )
  })

  it('links back to the record the conversation hangs off', async () => {
    renderThread()

    expect(
      await screen.findByRole('link', { name: 'BPO Solutions PH — 40-seat ramp' }),
    ).toHaveAttribute('href', '/opportunities/deal-1')
    expect(screen.getByText('Started')).toBeInTheDocument()
    expect(screen.getByText('Messages')).toBeInTheDocument()
  })

  it('says so when the conversation is not the caller’s to read', async () => {
    vi.mocked(communicationApi.getCommunicationThread).mockRejectedValue(new Error('404'))

    renderThread()

    expect(await screen.findByText('Could not load this conversation.')).toBeInTheDocument()
    expect(screen.getByRole('link', { name: 'Back to the conversations' })).toHaveAttribute(
      'href',
      '/communications/threads',
    )
  })

  it('keeps the files a message carried visible in the exchange', async () => {
    vi.mocked(communicationApi.getCommunicationThread).mockResolvedValue({
      ...thread,
      messages: [{ ...root, attachments_count: 1 }],
      message_count: 1,
    })

    renderThread()

    expect(await screen.findByText('1 file')).toBeInTheDocument()
  })
})
