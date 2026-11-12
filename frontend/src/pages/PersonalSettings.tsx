import { useEffect, useState, type FormEvent } from 'react'
import AlertItem from '../components/common/AlertItem'
import FormCard from '../components/common/FormCard'
import PageHeader from '../components/common/PageHeader'
import {
  changePersonalPassword,
  getPersonalSessions,
  getPersonalSettings,
  revokeOtherPersonalSessions,
  revokePersonalSession,
  updatePersonalPreferences,
  updatePersonalProfile,
  type PersonalSession,
  type PersonalSettings as Settings,
} from '../lib/personalSettings'
import { useAuth } from '../lib/auth-context'

const fieldClass = 'w-full rounded-md border border-input bg-card px-3 py-2 text-sm text-foreground outline-none focus:ring-2 focus:ring-ring'

export default function PersonalSettings() {
  const { user } = useAuth()
  const [settings, setSettings] = useState<Settings | null>(null)
  const [sessions, setSessions] = useState<PersonalSession[]>([])
  const [name, setName] = useState('')
  const [email, setEmail] = useState('')
  const [passwords, setPasswords] = useState({ current_password: '', password: '', password_confirmation: '' })
  const [notice, setNotice] = useState('')
  const [error, setError] = useState('')

  const load = async () => {
    const [personal, activeSessions] = await Promise.all([getPersonalSettings(), getPersonalSessions()])
    setSettings(personal)
    setName(personal.profile.name)
    setEmail(personal.profile.email)
    setSessions(activeSessions)
  }

  useEffect(() => {
    void load().catch(() => setError('Could not load your personal settings.'))
  }, [])

  const run = async (action: () => Promise<unknown>, success: string) => {
    setError('')
    setNotice('')
    try {
      await action()
      setNotice(success)
      await load()
    } catch {
      setError('Could not save this change. Check the fields and try again.')
    }
  }

  const submitProfile = (event: FormEvent) => {
    event.preventDefault()
    void run(() => updatePersonalProfile({ name, email }), 'Profile updated.')
  }

  const submitPassword = (event: FormEvent) => {
    event.preventDefault()
    void run(() => changePersonalPassword(passwords), 'Password changed; other sessions signed out.')
    setPasswords({ current_password: '', password: '', password_confirmation: '' })
  }

  const updatePreference = (key: keyof Settings['preferences'], value: string | boolean) => {
    if (!settings) return
    void run(() => updatePersonalPreferences({ ...settings.preferences, [key]: value } as Settings['preferences']), 'Preferences updated.')
  }

  return (
    <div className="flex flex-col gap-6">
      <PageHeader title="Personal settings" description="Manage your profile, preferences, password and signed-in sessions." />
      {error && <AlertItem title="Unable to save" variant="warning">{error}</AlertItem>}
      {notice && <AlertItem title="Saved" variant="success">{notice}</AlertItem>}
      <FormCard title="Profile" description="Update only your own name and email address.">
        <form onSubmit={submitProfile} className="grid gap-4 sm:grid-cols-2">
          <label className="text-sm">Name<input className={fieldClass} value={name} onChange={(event) => setName(event.target.value)} required maxLength={255} /></label>
          <label className="text-sm">Email<input className={fieldClass} type="email" value={email} onChange={(event) => setEmail(event.target.value)} required /></label>
          <p className="text-sm text-muted-foreground">Role: {user?.role}</p>
          <button className="rounded-md bg-primary px-4 py-2 text-sm text-primary-foreground sm:col-span-2 sm:justify-self-start">Save profile</button>
        </form>
      </FormCard>
      <FormCard title="Preferences" description="Personal display choices and optional in-app notifications.">
        {settings && <div className="grid gap-4 sm:grid-cols-2">
          <label className="text-sm">Date format<select className={fieldClass} value={settings.preferences.date_format} onChange={(event) => updatePreference('date_format', event.target.value)}><option>DD MMM YYYY</option></select></label>
          <label className="text-sm">Table density<select className={fieldClass} value={settings.preferences.table_density} onChange={(event) => updatePreference('table_density', event.target.value)}><option value="comfortable">Comfortable</option><option value="compact">Compact</option></select></label>
          <label className="text-sm">Default landing page<select className={fieldClass} value={settings.preferences.default_landing_page} onChange={(event) => updatePreference('default_landing_page', event.target.value)}>{['/dashboard', '/leads', '/accounts', '/pipeline', '/follow-ups'].map((path) => <option key={path} value={path}>{path}</option>)}</select></label>
          <label className="flex items-center gap-2 text-sm"><input type="checkbox" checked={settings.preferences.notification_database} onChange={(event) => updatePreference('notification_database', event.target.checked)} /> Optional in-app notifications</label>
          <p className="text-xs text-muted-foreground sm:col-span-2">Mandatory security notifications cannot be disabled by this preference.</p>
        </div>}
      </FormCard>
      <FormCard title="Security" description="Change your password after confirming your current password.">
        <form onSubmit={submitPassword} className="grid gap-4 sm:grid-cols-3">
          <label className="text-sm">Current password<input className={fieldClass} type="password" autoComplete="current-password" value={passwords.current_password} onChange={(event) => setPasswords({ ...passwords, current_password: event.target.value })} required /></label>
          <label className="text-sm">New password<input className={fieldClass} type="password" autoComplete="new-password" value={passwords.password} onChange={(event) => setPasswords({ ...passwords, password: event.target.value })} required minLength={8} /></label>
          <label className="text-sm">Confirm new password<input className={fieldClass} type="password" autoComplete="new-password" value={passwords.password_confirmation} onChange={(event) => setPasswords({ ...passwords, password_confirmation: event.target.value })} required /></label>
          <button className="rounded-md bg-primary px-4 py-2 text-sm text-primary-foreground sm:col-span-3 sm:justify-self-start">Change password</button>
        </form>
      </FormCard>
      <FormCard title="Active sessions" description="Only sessions belonging to your account are listed. Tokens and payloads are never displayed.">
        <div className="flex flex-col gap-3">
          {sessions.map((session) => <div key={session.id} className="flex flex-wrap items-center justify-between gap-3 rounded-md border border-border p-3 text-sm"><div><p className="font-medium">{session.current ? 'Current session' : 'Other session'}</p><p className="text-muted-foreground">{session.user_agent || 'Unknown device'} · {session.ip_address || 'Unknown location'}</p><p className="text-xs text-muted-foreground">Last active {new Date(session.last_seen_at).toLocaleString()}</p></div>{!session.current && <button className="text-destructive underline" onClick={() => void run(() => revokePersonalSession(session.id), 'Session signed out.')}>Sign out</button>}</div>)}
          {sessions.some((session) => !session.current) && <button className="self-start rounded-md border border-border px-4 py-2 text-sm" onClick={() => void run(revokeOtherPersonalSessions, 'Other sessions signed out.')}>Sign out all other sessions</button>}
          {sessions.length === 0 && <p className="text-sm text-muted-foreground">No active sessions recorded.</p>}
        </div>
      </FormCard>
    </div>
  )
}
