import { api } from './api'
import type { NotificationsResponse } from '../types'

export const listNotifications = async (): Promise<NotificationsResponse> => {
  const { data } = await api.get<NotificationsResponse>('/notifications')
  return data
}

export const markNotificationRead = async (id: string): Promise<{ unread: number }> => {
  const { data } = await api.post<{ meta: { unread: number } }>(`/notifications/${id}/read`)
  return data.meta
}

export const markAllNotificationsRead = async (): Promise<void> => {
  await api.post('/notifications/read-all')
}
