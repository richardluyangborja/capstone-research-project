import { Link, useParams } from 'react-router-dom'
import PageHeader from '../components/common/PageHeader'

export default function EmployeeProfilePlaceholder() {
  const { id } = useParams()

  return (
    <div className="space-y-4">
      <PageHeader title="Employee profile" description="The employee roster is available; the full profile arrives in the next delivery slice." />
      <div className="rounded-lg border border-border bg-card p-4 text-sm text-muted-foreground">
        Employee reference: <span className="font-mono text-foreground">{id}</span>
      </div>
      <Link to="/employees" className="text-sm font-medium text-primary hover:underline">Back to Employees</Link>
    </div>
  )
}
