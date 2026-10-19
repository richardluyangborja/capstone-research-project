import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import NotificationBell from './NotificationBell'
import * as notificationApi from '../../lib/notifications'
import type { NotificationItem } from '../../types'

// `vi.mock` factories are hoisted above the imports, so fixtures must come from `vi.hoisted`.
const { leadItem, dueItem } = vi.hoisted(() => {
  const base = {
    url: null as string | null,
    lead_id: null as string | null,
    reference: null,
    company_name: null,
    read_at: null,
    created_at: '2026-10-19T02:30:00.000000Z',
  }

  // A lead assignment, as module 03 wrote them: it names the lead rather than a path.
  const leadItem: NotificationItem = {
    ...base,
    id: 'n-1',
    type: 'lead_assigned',
    message: 'Maria Manager assigned you LD-2026-0004 (Harbor Logistics).',
    lead_id: 'lead-1',
  }

  // A due follow-up (module 04 §S3): it names its own item.
  const dueItem: NotificationItem = {
    ...base,
    id: 'n-2',
    type: 'follow_up_due',
    message: 'Overdue: Send the revised rate sheet (Sunrise Foods Inc).',
    url: '/follow-ups/task-1',
  }

  return { leadItem, dueItem }
})

vi.mock('../../lib/notifications', () => ({
  listNotifications: vi.fn(),
  markNotificationRead: vi.fn(),
  markAllNotificationsRead: vi.fn(),
}))

const renderBell = () =>
  render(
    <MemoryRouter initialEntries={['/']}>
      <NotificationBell />
      <Routes>
        <Route path="/" element={<p>Home</p>} />
        <Route path="/leads/:id" element={<p>Lead detail</p>} />
        <Route path="/follow-ups/:id" element={<p>Follow-up detail</p>} />
      </Routes>
    </MemoryRouter>,
  )

const openBell = async () => {
  renderBell()
  fireEvent.click(await screen.findByRole('button', { name: 'Notifications' }))
  await screen.findByText(dueItem.message)
}

describe('the notification bell', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    vi.mocked(notificationApi.listNotifications).mockResolvedValue({
      data: [dueItem, leadItem],
      meta: { unread: 2 },
    })
    vi.mocked(notificationApi.markNotificationRead).mockResolvedValue({ unread: 1 })
  })

  it('opens the item a notification is about', async () => {
    await openBell()

    fireEvent.click(screen.getByText(dueItem.message))

    expect(await screen.findByText('Follow-up detail')).toBeInTheDocument()
    // Opening it is reading it.
    await waitFor(() => expect(notificationApi.markNotificationRead).toHaveBeenCalledWith('n-2'))
  })

  it('still opens the lead behind an older assignment', async () => {
    await openBell()

    fireEvent.click(screen.getByText(leadItem.message))

    expect(await screen.findByText('Lead detail')).toBeInTheDocument()
  })
})
