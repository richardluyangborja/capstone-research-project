import StatCard from '../components/common/StatCard'

// Standard dashboard home layout: 4 primary StatCards, 4 secondary, chart section, activity+alerts.
export default function DashboardHome() {
  return (
    <div className="flex flex-col gap-6">
      <div className="grid grid-cols-2 gap-4 lg:grid-cols-4">
        <StatCard label="New leads" value={0} domain="counts" />
        <StatCard label="Open opportunities" value={0} domain="counts" />
        <StatCard label="Active clients" value={0} domain="people" />
        <StatCard label="Pipeline value" value="₱0.00" domain="cost" />
      </div>
      <div className="grid grid-cols-2 gap-4 lg:grid-cols-4">
        <StatCard label="Qualified this month" value={0} domain="counts" />
        <StatCard label="Follow-ups due" value={0} domain="activity" />
        <StatCard label="Contracts expiring" value={0} domain="cost" />
        <StatCard label="At-risk clients" value={0} domain="people" />
      </div>
      <div className="grid grid-cols-1 gap-6 lg:grid-cols-3">
        <div className="rounded-lg border border-border bg-card p-4 lg:col-span-2">
          <p className="text-sm font-medium text-muted-foreground">Pipeline trend</p>
          <div className="mt-4 h-56 rounded-md bg-muted" />
        </div>
        <div className="rounded-lg border border-border bg-card p-4">
          <p className="text-sm font-medium text-muted-foreground">Lead sources</p>
          <div className="mt-4 h-56 rounded-md bg-muted" />
        </div>
      </div>
      <div className="grid grid-cols-1 gap-6 lg:grid-cols-2">
        <div className="rounded-lg border border-border bg-card p-4">
          <p className="text-sm font-medium text-muted-foreground">Recent activity</p>
          <div className="mt-4 h-40 rounded-md bg-muted" />
        </div>
        <div className="rounded-lg border border-border bg-card p-4">
          <p className="text-sm font-medium text-muted-foreground">Alerts</p>
          <div className="mt-4 h-40 rounded-md bg-muted" />
        </div>
      </div>
    </div>
  )
}
