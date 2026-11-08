import { ShieldX } from 'lucide-react'
import { Link } from 'react-router-dom'
import PageHeader from '../components/common/PageHeader'

/**
 * The authenticated 403 surface (spec 19 S1). The API keeps Laravel's existing
 * `{"message": "This action is unauthorized."}` shape; the shell mirrors that decision with a
 * plain, non-leaking page instead of redirecting a signed-in user back to the login modal.
 */
export default function Forbidden() {
  return (
    <div className="flex flex-col gap-6">
      <PageHeader
        title="Access denied"
        description="Your role does not include this administrative area."
      />

      <div className="flex flex-col items-center justify-center rounded-lg border border-dashed border-border bg-card px-6 py-12 text-center">
        <ShieldX className="h-8 w-8 text-destructive" aria-hidden />
        <p className="mt-3 text-4xl font-semibold text-foreground">403</p>
        <p className="mt-1 max-w-sm text-sm text-muted-foreground">
          This action is unauthorized. Ask a superadmin if you need access.
        </p>
        <Link to="/dashboard" className="mt-4 text-sm font-medium text-primary hover:underline">
          Back to dashboard
        </Link>
      </div>
    </div>
  )
}
