import { Link } from 'react-router-dom'

export default function PublicHome() {
  return (
    <div className="text-center">
      <h1 className="text-3xl font-semibold text-foreground">Primepower Manpower Service</h1>
      <p className="mx-auto mt-3 max-w-xl text-sm text-muted-foreground">
        Service Management and Enterprise Resource System. This is the public site shell — the CRM
        dashboard is where the team works.
      </p>
      <Link
        to="/dashboard"
        className="mt-6 inline-block rounded-md bg-primary px-4 py-2 text-sm font-medium text-primary-foreground"
      >
        Open dashboard
      </Link>
    </div>
  )
}
