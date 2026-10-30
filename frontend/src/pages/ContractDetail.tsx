import { useCallback, useEffect, useState, type FormEvent } from 'react'
import { Link, useParams } from 'react-router-dom'
import {
  ArrowLeft,
  CheckCircle,
  Clock,
  Download,
  Edit2,
  FileText,
  Handshake,
  Layers,
  Plus,
  RefreshCw,
  ShieldAlert,
  Trash2,
  UserCheck,
} from 'lucide-react'
import StatusBadge from '../components/common/StatusBadge'
import StatCard from '../components/common/StatCard'
import DataTable, { type DataTableColumn } from '../components/common/DataTable'
import {
  downloadContractFile,
  getContract,
  markContractSigned,
  replaceContractRateCards,
  updateContract,
} from '../lib/contracts'
import { billingUnitLabels, contractStatusMeta, formatExpiry } from '../lib/contractMeta'
import { formatCurrency, formatDate } from '../lib/format'
import type { BillingUnit, Contract, ContractRateCard } from '../types'

interface RateCardFormRow {
  key: string
  site_id: string
  role_title: string
  headcount: string
  billing_unit: BillingUnit
  bill_rate: string
  pay_rate: string
  units_per_period: string
  notes: string
}

let keyCounter = 0
const newRateCardRow = (): RateCardFormRow => ({
  key: `rc-form-${(keyCounter += 1)}`,
  site_id: '',
  role_title: '',
  headcount: '1',
  billing_unit: 'hourly',
  bill_rate: '',
  pay_rate: '',
  units_per_period: '176',
  notes: '',
})

export default function ContractDetail() {
  const { id } = useParams<{ id: string }>()

  const [contract, setContract] = useState<Contract | null>(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')
  const [actionMessage, setActionMessage] = useState('')

  // Edit Commercial Header modal
  const [isEditingHeader, setIsEditingHeader] = useState(false)
  const [headerForm, setHeaderForm] = useState({
    title: '',
    start_date: '',
    end_date: '',
    value: '',
    payment_terms: '',
    po_reference: '',
    auto_renew: false,
    notice_days: '',
    signatory_name: '',
    signatory_position: '',
    terms: '',
    notes: '',
  })
  const [headerSaving, setHeaderSaving] = useState(false)
  const [headerError, setHeaderError] = useState('')

  // Edit Rate Cards modal
  const [isEditingRateCards, setIsEditingRateCards] = useState(false)
  const [rateCardRows, setRateCardRows] = useState<RateCardFormRow[]>([])
  const [rateCardsSaving, setRateCardsSaving] = useState(false)
  const [rateCardsError, setRateCardsError] = useState('')

  const loadContract = useCallback(async () => {
    if (!id) return
    setLoading(true)
    setError('')
    try {
      const data = await getContract(id)
      setContract(data)
    } catch (err) {
      console.error(err)
      setError('Could not load contract details.')
    } finally {
      setLoading(false)
    }
  }, [id])

  useEffect(() => {
    loadContract()
  }, [loadContract])

  const handleDownload = async () => {
    if (!contract) return
    try {
      await downloadContractFile(contract)
    } catch (err) {
      console.error(err)
      setError('Could not download contract file.')
    }
  }

  const handleMarkSigned = async () => {
    if (!contract) return
    if (
      !window.confirm(
        `Mark ${contract.reference} as Signed? This activates the client and triggers the Service handoff.`,
      )
    ) {
      return
    }

    try {
      const updated = await markContractSigned(contract.id)
      setContract(updated)
      setActionMessage('Contract marked as signed successfully.')
    } catch (err) {
      console.error(err)
      setError('Could not mark contract as signed.')
    }
  }

  // --- Header modal controls ---
  const openEditHeader = () => {
    if (!contract) return
    setHeaderForm({
      title: contract.title,
      start_date: contract.start_date ?? '',
      end_date: contract.end_date ?? '',
      value: contract.value ? String(contract.value) : '',
      payment_terms: contract.payment_terms ?? '',
      po_reference: contract.po_reference ?? '',
      auto_renew: contract.auto_renew,
      notice_days: contract.notice_days !== null ? String(contract.notice_days) : '',
      signatory_name: contract.signatory_name ?? '',
      signatory_position: contract.signatory_position ?? '',
      terms: contract.terms ?? '',
      notes: contract.notes ?? '',
    })
    setHeaderError('')
    setIsEditingHeader(true)
  }

  const saveHeader = async (e: FormEvent) => {
    e.preventDefault()
    if (!contract) return
    setHeaderSaving(true)
    setHeaderError('')

    try {
      const updated = await updateContract(contract.id, {
        title: headerForm.title,
        start_date: headerForm.start_date || null,
        end_date: headerForm.end_date || null,
        value: headerForm.value ? Number(headerForm.value) : null,
        payment_terms: headerForm.payment_terms || null,
        po_reference: headerForm.po_reference || null,
        auto_renew: headerForm.auto_renew,
        notice_days: headerForm.notice_days ? Number(headerForm.notice_days) : null,
        signatory_name: headerForm.signatory_name || null,
        signatory_position: headerForm.signatory_position || null,
        terms: headerForm.terms || null,
        notes: headerForm.notes || null,
      })
      setContract(updated)
      setIsEditingHeader(false)
      setActionMessage('Commercial terms updated successfully.')
    } catch (err) {
      console.error(err)
      setHeaderError('Could not update commercial terms. Please review inputs.')
    } finally {
      setHeaderSaving(false)
    }
  }

  // --- Rate Card modal controls ---
  const openEditRateCards = () => {
    if (!contract) return
    setRateCardRows(
      contract.rate_cards.map((card) => ({
        key: `rc-edit-${card.id}`,
        site_id: card.site_id ?? '',
        role_title: card.role_title,
        headcount: String(card.headcount),
        billing_unit: card.billing_unit,
        bill_rate: card.bill_rate,
        pay_rate: card.pay_rate ?? '',
        units_per_period: card.units_per_period ?? (card.billing_unit === 'hourly' ? '176' : '1'),
        notes: card.notes ?? '',
      })),
    )
    setRateCardsError('')
    setIsEditingRateCards(true)
  }

  const saveRateCards = async (e: FormEvent) => {
    e.preventDefault()
    if (!contract) return
    setRateCardsSaving(true)
    setRateCardsError('')

    const payload = rateCardRows.map((row) => ({
      site_id: row.site_id || null,
      role_title: row.role_title.trim(),
      headcount: Math.max(1, Number(row.headcount) || 1),
      billing_unit: row.billing_unit,
      bill_rate: Number(row.bill_rate) || 0,
      pay_rate: row.pay_rate ? Number(row.pay_rate) : null,
      units_per_period: row.billing_unit === 'hourly' ? Number(row.units_per_period) || 176 : 1,
      notes: row.notes.trim() || null,
    }))

    try {
      const updated = await replaceContractRateCards(contract.id, payload)
      setContract(updated)
      setIsEditingRateCards(false)
      setActionMessage('Rate cards updated successfully.')
    } catch (err) {
      console.error(err)
      setRateCardsError('Could not save rate cards. Ensure all roles and bill rates are set.')
    } finally {
      setRateCardsSaving(false)
    }
  }

  if (loading) {
    return (
      <div className="space-y-6">
        <div className="text-sm text-muted-foreground">Loading contract details…</div>
      </div>
    )
  }

  if (error && !contract) {
    return (
      <div className="space-y-4">
        <Link
          to="/contracts"
          className="inline-flex items-center gap-1.5 text-sm text-muted-foreground hover:text-foreground"
        >
          <ArrowLeft className="h-4 w-4" />
          Back to Contracts
        </Link>
        <div className="rounded-md border border-danger/30 bg-danger/10 p-4 text-sm text-danger">
          {error}
        </div>
      </div>
    )
  }

  if (!contract) return null

  const canViewMargin = Boolean(contract.commercial?.can_view_margin)

  const rateCardColumns: DataTableColumn<ContractRateCard>[] = [
    {
      key: 'role',
      header: 'Role Title',
      render: (card) => (
        <div className="flex flex-col">
          <span className="font-medium text-foreground">{card.role_title}</span>
          {card.notes && <span className="text-xs text-muted-foreground">{card.notes}</span>}
        </div>
      ),
    },
    {
      key: 'site',
      header: 'Site',
      className: 'text-muted-foreground',
      render: (card) => card.site?.label ?? '—',
    },
    {
      key: 'unit',
      header: 'Billing Unit',
      render: (card) => billingUnitLabels[card.billing_unit],
    },
    {
      key: 'headcount',
      header: 'Headcount',
      className: 'text-center',
      render: (card) => card.headcount,
    },
    {
      key: 'bill_rate',
      header: 'Bill Rate',
      className: 'text-right',
      render: (card) => formatCurrency(card.bill_rate),
    },
    {
      key: 'units',
      header: 'Units / Period',
      className: 'text-center',
      render: (card) =>
        card.billing_unit === 'hourly' ? `${card.units_per_period ?? 176} hrs` : '1',
    },
    {
      key: 'client_monthly',
      header: 'Client Monthly',
      className: 'text-right font-medium text-cost',
      render: (card) =>
        card.billing_unit === 'placement'
          ? `${formatCurrency(card.line_placement_fee)} (fee)`
          : formatCurrency(card.line_client_monthly),
    },
    ...(canViewMargin
      ? [
          {
            key: 'pay_rate',
            header: 'Pay Rate',
            className: 'text-right text-muted-foreground',
            render: (card: ContractRateCard) =>
              card.pay_rate ? formatCurrency(card.pay_rate) : '—',
          },
          {
            key: 'internal_monthly',
            header: 'Internal Cost',
            className: 'text-right text-muted-foreground',
            render: (card: ContractRateCard) =>
              card.line_internal_monthly ? formatCurrency(card.line_internal_monthly) : '—',
          },
        ]
      : []),
  ]

  return (
    <div className="space-y-6">
      {/* Navigation breadcrumb */}
      <div className="flex items-center justify-between">
        <Link
          to="/contracts"
          className="inline-flex items-center gap-1.5 text-sm text-muted-foreground hover:text-foreground"
        >
          <ArrowLeft className="h-4 w-4" />
          Back to Contracts
        </Link>
        {contract.company && (
          <Link
            to={`/accounts/${contract.company.id}?tab=commercial`}
            className="text-sm font-medium text-primary hover:underline"
          >
            Open {contract.company.name} Account 360 →
          </Link>
        )}
      </div>

      {actionMessage && (
        <div className="rounded-md border border-people/30 bg-people/10 p-3 text-sm text-people">
          {actionMessage}
        </div>
      )}

      {/* Main Header */}
      <div className="flex flex-wrap items-start justify-between gap-4 rounded-xl border border-border bg-card p-6">
        <div className="space-y-1">
          <div className="flex items-center gap-2">
            <span className="font-mono text-sm font-semibold text-primary">
              {contract.reference}
            </span>
            <StatusBadge tone={contractStatusMeta[contract.status]?.tone ?? 'neutral'}>
              {contractStatusMeta[contract.status]?.label ?? contract.status}
            </StatusBadge>
            {contract.is_expiring_soon && (
              <span className="inline-flex items-center gap-1 rounded-full border border-cost/40 bg-cost/10 px-2 py-0.5 text-xs font-semibold text-cost">
                <ShieldAlert className="h-3 w-3" />
                Expiring soon
              </span>
            )}
          </div>
          <h1 className="text-2xl font-bold text-foreground">{contract.title}</h1>
          <p className="text-sm text-muted-foreground">
            Account:{' '}
            {contract.company ? (
              <Link
                to={`/accounts/${contract.company.id}`}
                className="font-medium text-foreground hover:underline"
              >
                {contract.company.name}
              </Link>
            ) : (
              '—'
            )}{' '}
            • Owner: <span className="font-medium">{contract.owner?.name ?? '—'}</span>
          </p>
        </div>

        {/* Action Buttons */}
        <div className="flex flex-wrap items-center gap-2">
          {contract.file?.present && (
            <button
              type="button"
              onClick={handleDownload}
              className="inline-flex items-center gap-1.5 rounded-md border border-input bg-background px-3 py-2 text-sm font-medium hover:bg-accent"
            >
              <Download className="h-4 w-4" />
              Download Document
            </button>
          )}

          {contract.can_mark_signed && contract.status !== 'signed' && (
            <button
              type="button"
              onClick={handleMarkSigned}
              className="inline-flex items-center gap-1.5 rounded-md bg-people px-3 py-2 text-sm font-medium text-white hover:bg-people/90"
            >
              <UserCheck className="h-4 w-4" />
              Mark Signed
            </button>
          )}

          {contract.can_update && (
            <button
              type="button"
              onClick={openEditHeader}
              className="inline-flex items-center gap-1.5 rounded-md border border-input bg-background px-3 py-2 text-sm font-medium hover:bg-accent"
            >
              <Edit2 className="h-4 w-4" />
              Edit Terms
            </button>
          )}

          {contract.can_manage_rate_cards && (
            <button
              type="button"
              onClick={openEditRateCards}
              className="inline-flex items-center gap-1.5 rounded-md border border-input bg-background px-3 py-2 text-sm font-medium hover:bg-accent"
            >
              <Layers className="h-4 w-4" />
              Manage Rate Cards
            </button>
          )}

          {contract.company && (
            <Link
              to={`/accounts/${contract.company.id}?tab=contracts&renew_contract_id=${contract.id}`}
              className="inline-flex items-center gap-1.5 rounded-md border border-primary/20 bg-primary/10 px-3 py-2 text-sm font-medium text-primary hover:bg-primary/20"
            >
              <RefreshCw className="h-4 w-4" />
              Renew Contract
            </Link>
          )}
        </div>
      </div>

      {/* Commercial Summary Cards */}
      <div className="grid grid-cols-2 gap-4 lg:grid-cols-4">
        <StatCard
          label="Headline Value"
          value={formatCurrency(contract.value)}
          domain="cost"
          icon={Handshake}
        />
        <StatCard
          label="Client Monthly"
          value={
            contract.commercial?.client_monthly_value
              ? formatCurrency(contract.commercial.client_monthly_value)
              : '—'
          }
          domain="counts"
          icon={Clock}
        />
        <StatCard
          label="Estimated Total Value"
          value={
            contract.commercial?.estimated_total_value
              ? formatCurrency(contract.commercial.estimated_total_value)
              : '—'
          }
          domain="activity"
          icon={FileText}
        />
        {canViewMargin ? (
          <StatCard
            label="Gross Margin"
            value={
              contract.commercial?.gross_margin
                ? `${formatCurrency(contract.commercial.gross_margin)} (${contract.commercial.gross_margin_percent ?? 0}%)`
                : '—'
            }
            domain="people"
            icon={CheckCircle}
          />
        ) : (
          <StatCard
            label="Expiry Window"
            value={formatExpiry(contract.days_until_expiry)}
            domain={contract.is_expiring_soon ? 'cost' : 'counts'}
            icon={ShieldAlert}
          />
        )}
      </div>

      {/* Commercial Header & Terms */}
      <div className="grid grid-cols-1 gap-6 lg:grid-cols-3">
        <div className="space-y-6 lg:col-span-2">
          {/* Rate Cards Section */}
          <div className="rounded-xl border border-border bg-card p-6 shadow-sm">
            <div className="mb-4 flex items-center justify-between">
              <div>
                <h2 className="text-lg font-semibold text-foreground">Contract Rate Cards</h2>
                <p className="text-xs text-muted-foreground">
                  Structured commercial staffing rates and role headcount breakdown.
                </p>
              </div>
              {contract.can_manage_rate_cards && (
                <button
                  type="button"
                  onClick={openEditRateCards}
                  className="inline-flex items-center gap-1 text-xs font-semibold text-primary hover:underline"
                >
                  <Edit2 className="h-3.5 w-3.5" />
                  Edit Rate Cards
                </button>
              )}
            </div>

            <DataTable
              columns={rateCardColumns}
              rows={contract.rate_cards}
              rowKey={(card) => card.id}
              empty={
                <div className="py-6 text-center text-sm text-muted-foreground">
                  No rate cards added to this contract yet.
                </div>
              }
            />
          </div>

          {/* Renewal History Section */}
          <div className="rounded-xl border border-border bg-card p-6 shadow-sm">
            <h2 className="mb-3 text-lg font-semibold text-foreground">Renewal Chain</h2>
            <div className="space-y-3">
              {contract.renewal_of ? (
                <div className="flex items-center justify-between rounded-lg border border-border bg-background p-3">
                  <div>
                    <span className="text-xs text-muted-foreground">Renewed From:</span>
                    <div className="flex items-center gap-2">
                      <Link
                        to={`/contracts/${contract.renewal_of.id}`}
                        className="font-mono text-sm font-semibold text-primary hover:underline"
                      >
                        {contract.renewal_of.reference}
                      </Link>
                      <span className="text-sm font-medium text-foreground">
                        {contract.renewal_of.title ?? 'Prior Agreement'}
                      </span>
                    </div>
                  </div>
                  {contract.renewal_of.end_date && (
                    <span className="text-xs text-muted-foreground">
                      Expired {formatDate(contract.renewal_of.end_date)}
                    </span>
                  )}
                </div>
              ) : (
                <div className="text-sm text-muted-foreground">
                  This agreement is an original contract (not a renewal).
                </div>
              )}

              {contract.renewals && contract.renewals.length > 0 && (
                <div className="space-y-2 pt-2">
                  <span className="text-xs font-medium text-muted-foreground">
                    Subsequent Renewals:
                  </span>
                  {contract.renewals.map((r) => (
                    <div
                      key={r.id}
                      className="flex items-center justify-between rounded-lg border border-border bg-background p-3"
                    >
                      <div className="flex items-center gap-2">
                        <Link
                          to={`/contracts/${r.id}`}
                          className="font-mono text-sm font-semibold text-primary hover:underline"
                        >
                          {r.reference}
                        </Link>
                        <span className="text-sm text-foreground">{r.title}</span>
                      </div>
                      <StatusBadge tone={contractStatusMeta[r.status]?.tone ?? 'neutral'}>
                        {contractStatusMeta[r.status]?.label ?? r.status}
                      </StatusBadge>
                    </div>
                  ))}
                </div>
              )}
            </div>
          </div>
        </div>

        {/* Sidebar Panel: Commercial Details & Document */}
        <div className="space-y-6">
          <div className="rounded-xl border border-border bg-card p-6 shadow-sm">
            <h2 className="mb-4 text-base font-semibold text-foreground">Commercial Details</h2>
            <dl className="space-y-3 text-sm">
              <div>
                <dt className="text-xs text-muted-foreground">Payment Terms</dt>
                <dd className="font-medium text-foreground">{contract.payment_terms || '—'}</dd>
              </div>
              <div>
                <dt className="text-xs text-muted-foreground">PO Reference</dt>
                <dd className="font-mono font-medium text-foreground">
                  {contract.po_reference || '—'}
                </dd>
              </div>
              <div>
                <dt className="text-xs text-muted-foreground">Auto-Renew</dt>
                <dd className="font-medium text-foreground">
                  {contract.auto_renew ? 'Yes' : 'No'}
                </dd>
              </div>
              <div>
                <dt className="text-xs text-muted-foreground">Notice Window</dt>
                <dd className="font-medium text-foreground">
                  {contract.notice_days !== null ? `${contract.notice_days} days` : '—'}
                </dd>
              </div>
              <div>
                <dt className="text-xs text-muted-foreground">Client Signatory</dt>
                <dd className="font-medium text-foreground">
                  {contract.signatory_name || '—'}
                  {contract.signatory_position ? ` (${contract.signatory_position})` : ''}
                </dd>
              </div>
              <div>
                <dt className="text-xs text-muted-foreground">Term Period</dt>
                <dd className="font-medium text-foreground">
                  {formatDate(contract.start_date)} to {formatDate(contract.end_date)}
                </dd>
              </div>
              {contract.signed_at && (
                <div>
                  <dt className="text-xs text-muted-foreground">Signed On</dt>
                  <dd className="font-medium text-foreground">{formatDate(contract.signed_at)}</dd>
                </div>
              )}
              {contract.terms && (
                <div className="border-t border-border pt-3">
                  <dt className="text-xs text-muted-foreground">Terms / Stipulations</dt>
                  <dd className="mt-1 whitespace-pre-wrap text-xs text-foreground">
                    {contract.terms}
                  </dd>
                </div>
              )}
              {contract.notes && (
                <div className="border-t border-border pt-3">
                  <dt className="text-xs text-muted-foreground">Notes</dt>
                  <dd className="mt-1 whitespace-pre-wrap text-xs text-foreground">
                    {contract.notes}
                  </dd>
                </div>
              )}
            </dl>
          </div>

          {/* Attached Document Card */}
          <div className="rounded-xl border border-border bg-card p-6 shadow-sm">
            <h2 className="mb-3 text-base font-semibold text-foreground">Signed Document</h2>
            {contract.file?.present ? (
              <div className="space-y-3">
                <div className="flex items-center gap-3 rounded-lg border border-border bg-background p-3">
                  <FileText className="h-8 w-8 text-primary" />
                  <div className="min-w-0 flex-1">
                    <div className="truncate text-sm font-medium text-foreground">
                      {contract.file.name ?? `${contract.reference}.pdf`}
                    </div>
                    {contract.file.size && (
                      <div className="text-xs text-muted-foreground">
                        {(contract.file.size / 1024).toFixed(1)} KB
                      </div>
                    )}
                  </div>
                </div>
                <button
                  type="button"
                  onClick={handleDownload}
                  className="w-full inline-flex items-center justify-center gap-1.5 rounded-md border border-input bg-background py-2 text-sm font-medium hover:bg-accent"
                >
                  <Download className="h-4 w-4" />
                  Download File
                </button>
              </div>
            ) : (
              <p className="text-xs text-muted-foreground">No file attached to this agreement.</p>
            )}
          </div>
        </div>
      </div>

      {/* Edit Commercial Terms Modal */}
      {isEditingHeader && (
        <div
          role="dialog"
          aria-modal="true"
          aria-label="Edit commercial terms"
          className="fixed inset-0 z-50 flex items-center justify-center bg-foreground/40 p-4"
        >
          <div className="w-full max-w-lg rounded-xl border border-border bg-card p-6 shadow-xl max-h-full overflow-y-auto">
            <h2 className="text-lg font-bold text-foreground">Edit Commercial Terms</h2>
            <p className="text-xs text-muted-foreground mb-4">
              Update commercial header details for contract {contract.reference}.
            </p>

            {headerError && (
              <div className="mb-4 rounded-md border border-danger/30 bg-danger/10 p-3 text-sm text-danger">
                {headerError}
              </div>
            )}

            <form onSubmit={saveHeader} className="space-y-4">
              <div>
                <label className="block text-xs font-semibold text-foreground mb-1">
                  Agreement Title
                </label>
                <input
                  type="text"
                  required
                  value={headerForm.title}
                  onChange={(e) => setHeaderForm({ ...headerForm, title: e.target.value })}
                  className="w-full rounded-md border border-input bg-background px-3 py-2 text-sm text-foreground focus:outline-none focus:ring-2 focus:ring-ring"
                />
              </div>

              <div className="grid grid-cols-2 gap-3">
                <div>
                  <label className="block text-xs font-semibold text-foreground mb-1">
                    Start Date
                  </label>
                  <input
                    type="date"
                    value={headerForm.start_date}
                    onChange={(e) => setHeaderForm({ ...headerForm, start_date: e.target.value })}
                    className="w-full rounded-md border border-input bg-background px-3 py-2 text-sm text-foreground focus:outline-none focus:ring-2 focus:ring-ring"
                  />
                </div>
                <div>
                  <label className="block text-xs font-semibold text-foreground mb-1">
                    End Date
                  </label>
                  <input
                    type="date"
                    value={headerForm.end_date}
                    onChange={(e) => setHeaderForm({ ...headerForm, end_date: e.target.value })}
                    className="w-full rounded-md border border-input bg-background px-3 py-2 text-sm text-foreground focus:outline-none focus:ring-2 focus:ring-ring"
                  />
                </div>
              </div>

              <div className="grid grid-cols-2 gap-3">
                <div>
                  <label className="block text-xs font-semibold text-foreground mb-1">
                    Headline Value (₱)
                  </label>
                  <input
                    type="number"
                    step="0.01"
                    value={headerForm.value}
                    onChange={(e) => setHeaderForm({ ...headerForm, value: e.target.value })}
                    className="w-full rounded-md border border-input bg-background px-3 py-2 text-sm text-foreground focus:outline-none focus:ring-2 focus:ring-ring"
                  />
                </div>
                <div>
                  <label className="block text-xs font-semibold text-foreground mb-1">
                    Payment Terms
                  </label>
                  <input
                    type="text"
                    placeholder="e.g. Net 30"
                    value={headerForm.payment_terms}
                    onChange={(e) =>
                      setHeaderForm({ ...headerForm, payment_terms: e.target.value })
                    }
                    className="w-full rounded-md border border-input bg-background px-3 py-2 text-sm text-foreground focus:outline-none focus:ring-2 focus:ring-ring"
                  />
                </div>
              </div>

              <div className="grid grid-cols-2 gap-3">
                <div>
                  <label className="block text-xs font-semibold text-foreground mb-1">
                    PO Reference
                  </label>
                  <input
                    type="text"
                    value={headerForm.po_reference}
                    onChange={(e) => setHeaderForm({ ...headerForm, po_reference: e.target.value })}
                    className="w-full rounded-md border border-input bg-background px-3 py-2 text-sm text-foreground focus:outline-none focus:ring-2 focus:ring-ring"
                  />
                </div>
                <div>
                  <label className="block text-xs font-semibold text-foreground mb-1">
                    Notice Days
                  </label>
                  <input
                    type="number"
                    value={headerForm.notice_days}
                    onChange={(e) => setHeaderForm({ ...headerForm, notice_days: e.target.value })}
                    className="w-full rounded-md border border-input bg-background px-3 py-2 text-sm text-foreground focus:outline-none focus:ring-2 focus:ring-ring"
                  />
                </div>
              </div>

              <div className="grid grid-cols-2 gap-3">
                <div>
                  <label className="block text-xs font-semibold text-foreground mb-1">
                    Signatory Name
                  </label>
                  <input
                    type="text"
                    value={headerForm.signatory_name}
                    onChange={(e) =>
                      setHeaderForm({ ...headerForm, signatory_name: e.target.value })
                    }
                    className="w-full rounded-md border border-input bg-background px-3 py-2 text-sm text-foreground focus:outline-none focus:ring-2 focus:ring-ring"
                  />
                </div>
                <div>
                  <label className="block text-xs font-semibold text-foreground mb-1">
                    Signatory Position
                  </label>
                  <input
                    type="text"
                    value={headerForm.signatory_position}
                    onChange={(e) =>
                      setHeaderForm({ ...headerForm, signatory_position: e.target.value })
                    }
                    className="w-full rounded-md border border-input bg-background px-3 py-2 text-sm text-foreground focus:outline-none focus:ring-2 focus:ring-ring"
                  />
                </div>
              </div>

              <div className="flex items-center gap-2">
                <input
                  type="checkbox"
                  id="auto_renew"
                  checked={headerForm.auto_renew}
                  onChange={(e) => setHeaderForm({ ...headerForm, auto_renew: e.target.checked })}
                  className="rounded border-input text-primary focus:ring-ring"
                />
                <label htmlFor="auto_renew" className="text-xs font-medium text-foreground">
                  Auto-renew contract at term end
                </label>
              </div>

              <div>
                <label className="block text-xs font-semibold text-foreground mb-1">
                  Terms & Conditions
                </label>
                <textarea
                  rows={2}
                  value={headerForm.terms}
                  onChange={(e) => setHeaderForm({ ...headerForm, terms: e.target.value })}
                  className="w-full rounded-md border border-input bg-background px-3 py-2 text-sm text-foreground focus:outline-none focus:ring-2 focus:ring-ring"
                />
              </div>

              <div>
                <label className="block text-xs font-semibold text-foreground mb-1">Notes</label>
                <textarea
                  rows={2}
                  value={headerForm.notes}
                  onChange={(e) => setHeaderForm({ ...headerForm, notes: e.target.value })}
                  className="w-full rounded-md border border-input bg-background px-3 py-2 text-sm text-foreground focus:outline-none focus:ring-2 focus:ring-ring"
                />
              </div>

              <div className="flex justify-end gap-2 pt-2">
                <button
                  type="button"
                  onClick={() => setIsEditingHeader(false)}
                  className="rounded-md border border-input bg-background px-3 py-2 text-sm font-medium hover:bg-accent"
                >
                  Cancel
                </button>
                <button
                  type="submit"
                  disabled={headerSaving}
                  className="rounded-md bg-primary px-4 py-2 text-sm font-medium text-primary-foreground hover:bg-primary/90 disabled:opacity-50"
                >
                  {headerSaving ? 'Saving…' : 'Save Changes'}
                </button>
              </div>
            </form>
          </div>
        </div>
      )}

      {/* Edit Rate Cards Modal */}
      {isEditingRateCards && (
        <div
          role="dialog"
          aria-modal="true"
          aria-label="Manage rate cards"
          className="fixed inset-0 z-50 flex items-center justify-center bg-foreground/40 p-4"
        >
          <div className="w-full max-w-3xl rounded-xl border border-border bg-card p-6 shadow-xl max-h-full overflow-y-auto">
            <h2 className="text-lg font-bold text-foreground">Manage Rate Cards</h2>
            <p className="text-xs text-muted-foreground mb-4">
              Add, edit, or remove commercial staffing rate lines for {contract.reference}.
            </p>

            {rateCardsError && (
              <div className="mb-4 rounded-md border border-danger/30 bg-danger/10 p-3 text-sm text-danger">
                {rateCardsError}
              </div>
            )}

            <form onSubmit={saveRateCards} className="space-y-4">
              {rateCardRows.map((row, idx) => (
                <div
                  key={row.key}
                  className="space-y-3 rounded-lg border border-border bg-background p-4 relative"
                >
                  <div className="flex items-center justify-between">
                    <span className="text-xs font-bold text-foreground">Role #{idx + 1}</span>
                    <button
                      type="button"
                      onClick={() =>
                        setRateCardRows((prev) => prev.filter((r) => r.key !== row.key))
                      }
                      className="text-danger hover:text-danger/80"
                      title="Remove role"
                    >
                      <Trash2 className="h-4 w-4" />
                    </button>
                  </div>

                  <div className="grid grid-cols-1 gap-3 md:grid-cols-3">
                    <div>
                      <label className="block text-xs font-semibold text-foreground mb-1">
                        Role Title
                      </label>
                      <input
                        type="text"
                        required
                        placeholder="e.g. Warehouse Associate"
                        value={row.role_title}
                        onChange={(e) => {
                          const val = e.target.value
                          setRateCardRows((prev) =>
                            prev.map((r) => (r.key === row.key ? { ...r, role_title: val } : r)),
                          )
                        }}
                        className="w-full rounded-md border border-input bg-background px-3 py-1.5 text-sm text-foreground focus:outline-none focus:ring-2 focus:ring-ring"
                      />
                    </div>

                    <div>
                      <label className="block text-xs font-semibold text-foreground mb-1">
                        Billing Unit
                      </label>
                      <select
                        value={row.billing_unit}
                        onChange={(e) => {
                          const val = e.target.value as BillingUnit
                          setRateCardRows((prev) =>
                            prev.map((r) =>
                              r.key === row.key
                                ? {
                                    ...r,
                                    billing_unit: val,
                                    units_per_period: val === 'hourly' ? '176' : '1',
                                  }
                                : r,
                            ),
                          )
                        }}
                        className="w-full rounded-md border border-input bg-background px-3 py-1.5 text-sm text-foreground focus:outline-none focus:ring-2 focus:ring-ring"
                      >
                        <option value="hourly">Hourly</option>
                        <option value="monthly">Monthly</option>
                        <option value="placement">Placement (One-time)</option>
                      </select>
                    </div>

                    <div>
                      <label className="block text-xs font-semibold text-foreground mb-1">
                        Headcount
                      </label>
                      <input
                        type="number"
                        min="1"
                        required
                        value={row.headcount}
                        onChange={(e) => {
                          const val = e.target.value
                          setRateCardRows((prev) =>
                            prev.map((r) => (r.key === row.key ? { ...r, headcount: val } : r)),
                          )
                        }}
                        className="w-full rounded-md border border-input bg-background px-3 py-1.5 text-sm text-foreground focus:outline-none focus:ring-2 focus:ring-ring"
                      />
                    </div>
                  </div>

                  <div className="grid grid-cols-1 gap-3 md:grid-cols-3">
                    <div>
                      <label className="block text-xs font-semibold text-foreground mb-1">
                        Bill Rate (₱)
                      </label>
                      <input
                        type="number"
                        step="0.01"
                        min="0"
                        required
                        value={row.bill_rate}
                        onChange={(e) => {
                          const val = e.target.value
                          setRateCardRows((prev) =>
                            prev.map((r) => (r.key === row.key ? { ...r, bill_rate: val } : r)),
                          )
                        }}
                        className="w-full rounded-md border border-input bg-background px-3 py-1.5 text-sm text-foreground focus:outline-none focus:ring-2 focus:ring-ring"
                      />
                    </div>

                    {canViewMargin && (
                      <div>
                        <label className="block text-xs font-semibold text-foreground mb-1">
                          Pay Rate / Cost (₱)
                        </label>
                        <input
                          type="number"
                          step="0.01"
                          min="0"
                          placeholder="Optional"
                          value={row.pay_rate}
                          onChange={(e) => {
                            const val = e.target.value
                            setRateCardRows((prev) =>
                              prev.map((r) => (r.key === row.key ? { ...r, pay_rate: val } : r)),
                            )
                          }}
                          className="w-full rounded-md border border-input bg-background px-3 py-1.5 text-sm text-foreground focus:outline-none focus:ring-2 focus:ring-ring"
                        />
                      </div>
                    )}

                    {row.billing_unit === 'hourly' && (
                      <div>
                        <label className="block text-xs font-semibold text-foreground mb-1">
                          Hours / Month
                        </label>
                        <input
                          type="number"
                          step="0.5"
                          value={row.units_per_period}
                          onChange={(e) => {
                            const val = e.target.value
                            setRateCardRows((prev) =>
                              prev.map((r) =>
                                r.key === row.key ? { ...r, units_per_period: val } : r,
                              ),
                            )
                          }}
                          className="w-full rounded-md border border-input bg-background px-3 py-1.5 text-sm text-foreground focus:outline-none focus:ring-2 focus:ring-ring"
                        />
                      </div>
                    )}
                  </div>
                </div>
              ))}

              <button
                type="button"
                onClick={() => setRateCardRows((prev) => [...prev, newRateCardRow()])}
                className="inline-flex items-center gap-1.5 rounded-md border border-dashed border-border px-3 py-2 text-sm font-medium text-primary hover:bg-accent w-full justify-center"
              >
                <Plus className="h-4 w-4" />
                Add Rate Card Line
              </button>

              <div className="flex justify-end gap-2 pt-2">
                <button
                  type="button"
                  onClick={() => setIsEditingRateCards(false)}
                  className="rounded-md border border-input bg-background px-3 py-2 text-sm font-medium hover:bg-accent"
                >
                  Cancel
                </button>
                <button
                  type="submit"
                  disabled={rateCardsSaving}
                  className="rounded-md bg-primary px-4 py-2 text-sm font-medium text-primary-foreground hover:bg-primary/90 disabled:opacity-50"
                >
                  {rateCardsSaving ? 'Saving…' : 'Save Rate Cards'}
                </button>
              </div>
            </form>
          </div>
        </div>
      )}
    </div>
  )
}
