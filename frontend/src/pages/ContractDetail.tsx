import { useCallback, useEffect, useState } from 'react'
import { ArrowLeft, Clock, FileText, Handshake, ShieldAlert } from 'lucide-react'
import { Link, useParams } from 'react-router-dom'
import StatusBadge from '../components/common/StatusBadge'
import StatCard from '../components/common/StatCard'
import RateCardTable from '../components/common/RateCardTable'
import { getContract } from '../lib/contracts'
import { contractStatusMeta, formatExpiry } from '../lib/contractMeta'
import { formatCurrency, formatDate } from '../lib/format'
import type { Contract } from '../types'

export default function ContractDetail() {
  const { id } = useParams<{ id: string }>()
  const [contract, setContract] = useState<Contract | null>(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')

  const load = useCallback(async () => {
    if (!id) return
    try {
      setContract(await getContract(id))
    } catch {
      setError('Could not load contract details.')
    } finally {
      setLoading(false)
    }
  }, [id])

  useEffect(() => {
    void load()
  }, [load])

  if (loading) return <div className="text-sm text-muted-foreground">Loading contract details…</div>
  if (error || !contract) {
    return (
      <div className="space-y-4">
        <Link to="/contracts" className="inline-flex items-center gap-1.5 text-sm text-muted-foreground">
          <ArrowLeft className="h-4 w-4" /> Back to Contracts
        </Link>
        <div className="rounded-md border border-danger/30 bg-danger/10 p-4 text-sm text-danger">
          {error || 'Contract not found.'}
        </div>
      </div>
    )
  }

  const provenance = contract.source ?? 'Contract Management (mocked, read-only)'
  return (
    <div className="space-y-6">
      <Link to="/contracts" className="inline-flex items-center gap-1.5 text-sm text-muted-foreground hover:text-foreground">
        <ArrowLeft className="h-4 w-4" /> Back to Contracts
      </Link>
      <div className="rounded-xl border border-border bg-card p-6">
        <div className="flex flex-wrap items-start justify-between gap-4">
          <div>
            <div className="flex items-center gap-2">
              <span className="font-mono text-sm font-semibold text-primary">{contract.reference}</span>
              <StatusBadge tone={contractStatusMeta[contract.status]?.tone ?? 'neutral'}>
                {contractStatusMeta[contract.status]?.label ?? contract.status}
              </StatusBadge>
            </div>
            <h1 className="mt-1 text-2xl font-bold text-foreground">{contract.title}</h1>
            <p className="text-sm text-muted-foreground">
              Account:{' '}
              {contract.company ? (
                <Link to={`/accounts/${contract.company.id}?tab=commercial`} className="font-medium text-foreground hover:underline">
                  {contract.company.name}
                </Link>
              ) : '—'}{' '}
              · Owner: {contract.owner?.name ?? '—'}
            </p>
          </div>
          <div className="text-right text-xs text-muted-foreground">
            <p className="font-medium text-foreground">{provenance}</p>
            <p>Read-only projection · As of {contract.as_of ? formatDate(contract.as_of) : '—'}</p>
          </div>
        </div>
      </div>

      <div className="grid grid-cols-2 gap-4 lg:grid-cols-4">
        <StatCard label="Headline Value" value={formatCurrency(contract.value)} domain="cost" icon={Handshake} />
        <StatCard label="Client Monthly" value={formatCurrency(contract.commercial?.client_monthly_value)} domain="counts" icon={Clock} />
        <StatCard label="Estimated Total Value" value={formatCurrency(contract.commercial?.estimated_total_value)} domain="activity" icon={FileText} />
        <StatCard
          label="Expiry"
          value={formatExpiry(contract.days_until_expiry)}
          domain={contract.is_expiring_soon ? 'cost' : 'counts'}
          icon={ShieldAlert}
        />
      </div>

      <div className="grid grid-cols-1 gap-6 lg:grid-cols-3">
        <section className="rounded-xl border border-border bg-card p-6 lg:col-span-2">
          <h2 className="text-lg font-semibold text-foreground">Rate cards</h2>
          <p className="mb-4 text-xs text-muted-foreground">Read-only commercial lines from Contract Management.</p>
          <RateCardTable rateCards={contract.rate_cards ?? []} canViewMargin={Boolean(contract.commercial?.can_view_margin)} />
        </section>
        <section className="rounded-xl border border-border bg-card p-6">
          <h2 className="text-lg font-semibold text-foreground">Terms</h2>
          <dl className="mt-4 space-y-3 text-sm">
            <Term label="Start" value={contract.start_date ? formatDate(contract.start_date) : '—'} />
            <Term label="End" value={contract.end_date ? formatDate(contract.end_date) : '—'} />
            <Term label="Payment terms" value={contract.payment_terms ?? '—'} />
            <Term label="PO reference" value={contract.po_reference ?? '—'} />
            <Term label="Auto-renew" value={contract.auto_renew ? 'Yes' : 'No'} />
          </dl>
        </section>
      </div>

      {contract.renewal_of || contract.renewals?.length ? (
        <section className="rounded-xl border border-border bg-card p-6">
          <h2 className="text-lg font-semibold text-foreground">Renewal chain</h2>
          <div className="mt-3 space-y-2 text-sm">
            {contract.renewal_of && <Link className="text-primary hover:underline" to={`/contracts/${contract.renewal_of.id}`}>Renewal of {contract.renewal_of.reference}</Link>}
            {contract.renewals?.map((renewal) => <Link className="block text-primary hover:underline" key={renewal.id} to={`/contracts/${renewal.id}`}>{renewal.reference} — {renewal.title}</Link>)}
          </div>
        </section>
      ) : null}
    </div>
  )
}

function Term({ label, value }: { label: string; value: string }) {
  return <div className="flex justify-between gap-3"><dt className="text-muted-foreground">{label}</dt><dd className="text-right text-foreground">{value}</dd></div>
}
