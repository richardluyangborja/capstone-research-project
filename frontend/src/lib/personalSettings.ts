import { api } from './api'

export interface PersonalSettings {
  profile: { name: string; email: string; role: string }
  preferences: {
    date_format: string
    table_density: 'comfortable' | 'compact'
    default_landing_page: string
    notification_database: boolean
  }
  sessions: number
}

export interface PersonalSession {
  id: string
  ip_address: string | null
  user_agent: string | null
  last_seen_at: string
  current: boolean
}

export const getPersonalSettings = async (): Promise<PersonalSettings> => {
  const { data } = await api.get<{ data: PersonalSettings }>('/me/settings')
  return data.data
}

export const getPersonalSessions = async (): Promise<PersonalSession[]> => {
  const { data } = await api.get<{ data: PersonalSession[] }>('/me/sessions')
  return data.data
}

export const updatePersonalProfile = async (input: { name: string; email: string }) => {
  const { data } = await api.patch('/me/profile', input)
  return data.data as PersonalSettings['profile']
}

export const updatePersonalPreferences = async (preferences: PersonalSettings['preferences']) => {
  const { data } = await api.patch<{ data: PersonalSettings['preferences'] }>('/me/preferences', preferences)
  return data.data
}

export const changePersonalPassword = async (input: {
  current_password: string
  password: string
  password_confirmation: string
}) => api.put('/me/password', input)

export const revokePersonalSession = async (id: string) => api.delete(`/me/sessions/${id}`)

export const revokeOtherPersonalSessions = async () => api.post('/me/sessions/revoke-others')
