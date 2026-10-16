type Domain = 'counts' | 'people' | 'activity' | 'cost'

interface StatCardProps {
  label: string
  value: string | number
  domain?: Domain
}

// Fixed data-domain color mapping (design system rule).
const domainText: Record<Domain, string> = {
  counts: 'text-counts',
  people: 'text-people',
  activity: 'text-activity',
  cost: 'text-cost',
}

export default function StatCard({ label, value, domain = 'counts' }: StatCardProps) {
  return (
    <div className="rounded-lg border border-border bg-card p-4">
      <p className="text-xs font-medium text-muted-foreground">{label}</p>
      <p className={`mt-1 text-2xl font-semibold ${domainText[domain]}`}>{value}</p>
    </div>
  )
}
