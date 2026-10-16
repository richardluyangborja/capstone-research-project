import { Link } from 'react-router-dom'

export default function NotFound() {
  return (
    <div className="flex min-h-full flex-col items-center justify-center gap-3 bg-background">
      <p className="text-4xl font-semibold text-foreground">404</p>
      <p className="text-sm text-muted-foreground">This page does not exist.</p>
      <Link to="/dashboard" className="text-sm font-medium text-primary hover:underline">
        Back to dashboard
      </Link>
    </div>
  )
}
