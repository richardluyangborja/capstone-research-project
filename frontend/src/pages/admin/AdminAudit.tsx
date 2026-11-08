import AdminPlaceholder from './AdminPlaceholder'

export default function AdminAudit() {
  return (
    <AdminPlaceholder
      title="Audit log"
      description="The immutable administrative history, filtered and redacted."
      slice="S5 — audit and security activity"
      detail="Authentication, user, team, role and settings events will be searchable here. Rows are append-only and sensitive values are redacted."
    />
  )
}
