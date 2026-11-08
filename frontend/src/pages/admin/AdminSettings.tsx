import AdminPlaceholder from './AdminPlaceholder'

export default function AdminSettings() {
  return (
    <AdminPlaceholder
      title="Settings"
      description="Typed organization and CRM settings with their effective source."
      slice="S4 — organization and CRM settings"
      detail="Safe thresholds and presentation keys will be editable here. Security-sensitive keys remain superadmin-only, and secrets never enter this surface."
    />
  )
}
