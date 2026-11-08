import PageHeader from '../components/common/PageHeader'
import FormCard from '../components/common/FormCard'
import EmptyState from '../components/common/EmptyState'
import { useAuth } from '../lib/auth-context'

const roleLabels = {
  salesrep: 'Sales representative',
  manager: 'Manager',
  admin: 'Administrator',
  superadmin: 'Superadmin',
} as const

/**
 * The personal-settings surface every signed-in role shares (spec 19 S1).
 *
 * S1 only shows the session's own identity; profile editing, password change, preferences and
 * session management arrive with S9. No form control is rendered before its API exists.
 */
export default function PersonalSettings() {
  const { user } = useAuth()

  return (
    <div className="flex flex-col gap-6">
      <PageHeader
        title="Personal settings"
        description="Your profile, preferences and active sessions."
      />

      <FormCard title="Profile" description="The identity carried by your current session.">
        <dl className="grid grid-cols-1 gap-4 text-sm sm:grid-cols-2">
          <div>
            <dt className="text-xs font-medium text-muted-foreground">Name</dt>
            <dd className="mt-1 text-foreground">{user?.name ?? '—'}</dd>
          </div>
          <div>
            <dt className="text-xs font-medium text-muted-foreground">Email</dt>
            <dd className="mt-1 text-foreground">{user?.email ?? '—'}</dd>
          </div>
          <div>
            <dt className="text-xs font-medium text-muted-foreground">Role</dt>
            <dd className="mt-1 text-foreground">{user ? roleLabels[user.role] : '—'}</dd>
          </div>
        </dl>
      </FormCard>

      <EmptyState
        title="Profile controls arrive with S9"
        description="Editing your details, changing your password, choosing preferences and signing out other sessions will be added with their API, without fake controls in the meantime."
      />
    </div>
  )
}
