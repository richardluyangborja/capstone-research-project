import { useCallback, useEffect, useRef, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { Bell } from 'lucide-react'
import {
  listNotifications,
  markAllNotificationsRead,
  markNotificationRead,
} from '../../lib/notifications'
import { formatDateTime } from '../../lib/format'
import type { NotificationItem } from '../../types'

export default function NotificationBell() {
  const navigate = useNavigate()
  const [items, setItems] = useState<NotificationItem[]>([])
  const [unread, setUnread] = useState(0)
  const [open, setOpen] = useState(false)
  const containerRef = useRef<HTMLDivElement>(null)

  const load = useCallback(async () => {
    try {
      const response = await listNotifications()
      setItems(response.data)
      setUnread(response.meta.unread)
    } catch {
      // The bell is non-critical — fail quietly.
    }
  }, [])

  useEffect(() => {
    void load()
  }, [load])

  useEffect(() => {
    if (!open) return

    const onPointerDown = (event: MouseEvent) => {
      if (containerRef.current && !containerRef.current.contains(event.target as Node)) {
        setOpen(false)
      }
    }

    document.addEventListener('mousedown', onPointerDown)

    return () => document.removeEventListener('mousedown', onPointerDown)
  }, [open])

  const onSelect = async (item: NotificationItem) => {
    setOpen(false)

    if (!item.read_at) {
      try {
        const meta = await markNotificationRead(item.id)
        setUnread(meta.unread)
        setItems((previous) =>
          previous.map((entry) =>
            entry.id === item.id ? { ...entry, read_at: new Date().toISOString() } : entry,
          ),
        )
      } catch {
        // ignore
      }
    }

    // Every notification names the item it is about (module 04 slice S3); older lead
    // assignments still fall back to the lead they were written for.
    const target = item.url ?? (item.lead_id ? `/leads/${item.lead_id}` : null)

    if (target) {
      navigate(target)
    }
  }

  const onMarkAll = async () => {
    try {
      await markAllNotificationsRead()
      setUnread(0)
      setItems((previous) =>
        previous.map((entry) => ({ ...entry, read_at: entry.read_at ?? new Date().toISOString() })),
      )
    } catch {
      // ignore
    }
  }

  return (
    <div className="relative" ref={containerRef}>
      <button
        type="button"
        aria-label="Notifications"
        onClick={() => setOpen((value) => !value)}
        className="relative rounded-md p-2 text-muted-foreground hover:bg-muted hover:text-foreground"
      >
        <Bell className="h-4 w-4" aria-hidden="true" />
        {unread > 0 && (
          <span className="absolute right-0 top-0 rounded-full bg-destructive px-1 text-xs font-semibold text-destructive-foreground">
            {unread > 9 ? '9+' : unread}
          </span>
        )}
      </button>

      {open && (
        <div className="absolute right-0 z-20 mt-2 w-80 rounded-lg border border-border bg-card shadow-lg">
          <div className="flex items-center justify-between border-b border-border px-3 py-2">
            <span className="text-sm font-medium text-foreground">Notifications</span>
            {unread > 0 && (
              <button
                type="button"
                onClick={() => void onMarkAll()}
                className="text-xs font-medium text-primary"
              >
                Mark all read
              </button>
            )}
          </div>

          <ul className="max-h-80 overflow-y-auto">
            {items.length === 0 && (
              <li className="px-3 py-6 text-center text-sm text-muted-foreground">
                No notifications yet.
              </li>
            )}

            {items.map((item) => (
              <li key={item.id} className="border-b border-border last:border-0">
                <button
                  type="button"
                  onClick={() => void onSelect(item)}
                  className={`flex w-full flex-col items-start gap-0.5 px-3 py-2 text-left hover:bg-muted ${
                    item.read_at ? '' : 'bg-primary/5'
                  }`}
                >
                  <span className="text-sm text-foreground">{item.message}</span>
                  <span className="text-xs text-muted-foreground">
                    {formatDateTime(item.created_at)}
                  </span>
                </button>
              </li>
            ))}
          </ul>
        </div>
      )}
    </div>
  )
}
