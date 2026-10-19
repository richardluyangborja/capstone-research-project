import { useCallback, useEffect, useState, type FormEvent, type ReactNode } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'
import { ArrowLeft, CalendarClock, Trash2 } from 'lucide-react'
import PageHeader from '../components/common/PageHeader'
import FormCard from '../components/common/FormCard'
import CommunicationList from '../components/common/CommunicationList'
import AlertItem from '../components/common/AlertItem'
import StatusBadge from '../components/common/StatusBadge'
import StatCard from '../components/common/StatCard'
import StageMoveControl from '../components/common/StageMoveControl'
import LostReasonDialog from '../components/common/LostReasonDialog'
import {
  deleteOpportunity,
  getOpportunity,
  getOpportunityTimeline,
  logOpportunityActivity,
  moveOpportunityStage,
  updateOpportunity,
} from '../lib/opportunities'
import { listCompanyContacts } from '../lib/contacts'
import { getAssignableUsers, parseValidationError } from '../lib/leads'
import { opportunityStageMeta, lostReasonLabels } from '../lib/opportunityMeta'
import { activityTypeMeta } from '../lib/leadMeta'
import { followUpDueMeta } from '../lib/followUpMeta'
import { formatCurrency, formatDate, formatDateTime } from '../lib/format'
import type {
  Activity,
  ActivityType,
  AssignmentTarget,
  Contact,
  LostReason,
  Opportunity,
  OpportunityStage,
} from '../types'

const activityTypes: ActivityType[] = ['call', 'email', 'meeting', 'walk_in', 'task', 'note']

interface DealForm {
  name: string
  amount: string
  probability: string
  expected_close_date: string
  next_step: string
  primary_contact_id: string
  owner_id: string
}

const formFromDeal = (deal: Opportunity): DealForm => ({
  name: deal.name,
  amount: deal.amount === null ? '' : String(Number(deal.amount)),
  probability: deal.probability === null ? '' : String(deal.probability),
  expected_close_date: deal.expected_close_date ?? '',
  next_step: deal.next_step ?? '',
  primary_contact_id: deal.primary_contact_id ?? '',
  owner_id: String(deal.owner_id),
})

// Module 02 §2–4 — one deal: edit it, move its stage, log the activity around it, and hand
// a won deal to a contract. Winning never runs the Service handoff (ADR-0015 §4).
export default function OpportunityDetail() {
  const { id = '' } = useParams()
  const navigate = useNavigate()

  const [deal, setDeal] = useState<Opportunity | null>(null)
  const [timeline, setTimeline] = useState<Activity[]>([])
  const [contacts, setContacts] = useState<Contact[]>([])
  const [targets, setTargets] = useState<AssignmentTarget[]>([])
  const [form, setForm] = useState<DealForm | null>(null)
  const [activity, setActivity] = useState({ type: 'call' as ActivityType, subject: '', body: '' })
  const [loading, setLoading] = useState(true)
  const [busy, setBusy] = useState(false)
  const [message, setMessage] = useState('')
  const [alertVariant, setAlertVariant] = useState<'success' | 'danger'>('success')
  const [errors, setErrors] = useState<Record<string, string[]>>({})
  const [pendingLost, setPendingLost] = useState(false)

  const load = useCallback(async () => {
    setLoading(true)
    try {
      const [detail, history] = await Promise.all([getOpportunity(id), getOpportunityTimeline(id)])
      setDeal(detail)
      setForm(formFromDeal(detail))
      setTimeline(history.data)

      const [companyContacts, assignable] = await Promise.all([
        listCompanyContacts(detail.company_id).catch(() => []),
        detail.can_assign ? getAssignableUsers().catch(() => []) : Promise.resolve([]),
      ])
      setContacts(companyContacts)
      setTargets(assignable)
    } catch {
      setMessage('Could not load this deal.')
      setAlertVariant('danger')
    } finally {
      setLoading(false)
    }
  }, [id])

  useEffect(() => {
    void load()
  }, [load])

  const refreshTimeline = async () => {
    try {
      const history = await getOpportunityTimeline(id)
      setTimeline(history.data)
    } catch {
      // Non-critical.
    }
  }

  const showSuccess = (text: string) => {
    setAlertVariant('success')
    setMessage(text)
  }

  const showFailure = (error: unknown, fallback: string) => {
    const validation = parseValidationError(error)
    setAlertVariant('danger')
    if (validation) {
      setErrors(validation.errors)
      setMessage(validation.message)
    } else {
      setMessage(fallback)
    }
  }

  const onSave = async (event: FormEvent) => {
    event.preventDefault()
    if (!deal || !form) return

    setBusy(true)
    setErrors({})
    try {
      const payload: Record<string, unknown> = {
        name: form.name.trim(),
        expected_close_date: form.expected_close_date === '' ? null : form.expected_close_date,
        next_step: form.next_step.trim() === '' ? null : form.next_step.trim(),
        probability: form.probability === '' ? null : Number(form.probability),
        primary_contact_id: form.primary_contact_id === '' ? null : form.primary_contact_id,
      }
      if (deal.can_set_amount) payload.amount = form.amount === '' ? null : Number(form.amount)
      if (deal.can_assign) payload.owner_id = Number(form.owner_id)

      const updated = await updateOpportunity(id, payload)
      setDeal(updated)
      setForm(formFromDeal(updated))
      showSuccess('Deal updated.')
    } catch (error) {
      showFailure(error, 'Could not save the deal.')
    } finally {
      setBusy(false)
    }
  }

  const submitMove = async (stage: OpportunityStage, reason?: LostReason) => {
    if (!deal) return
    setBusy(true)
    setErrors({})
    try {
      const updated = await moveOpportunityStage(deal.id, { stage, lost_reason: reason ?? null })
      setDeal(updated)
      setForm(formFromDeal(updated))
      setPendingLost(false)
      await refreshTimeline()
      showSuccess(
        stage === 'won'
          ? 'Deal won — create its contract to activate the client.'
          : `Deal moved to ${opportunityStageMeta[stage].label.toLowerCase()}.`,
      )
    } catch (error) {
      showFailure(error, 'Could not move the deal.')
    } finally {
      setBusy(false)
    }
  }

  const onLogActivity = async (event: FormEvent) => {
    event.preventDefault()
    setBusy(true)
    try {
      await logOpportunityActivity(id, {
        type: activity.type,
        subject: activity.subject.trim(),
        body: activity.body.trim() === '' ? undefined : activity.body.trim(),
      })
      setActivity({ type: 'call', subject: '', body: '' })
      await refreshTimeline()
      showSuccess('Activity logged.')
    } catch (error) {
      showFailure(error, 'Could not log the activity.')
    } finally {
      setBusy(false)
    }
  }

  const onDelete = async () => {
    setBusy(true)
    try {
      await deleteOpportunity(id)
      navigate('/opportunities')
    } catch (error) {
      showFailure(error, 'Could not delete the deal.')
      setBusy(false)
    }
  }

  if (loading && !deal) {
    return <p className="text-sm text-muted-foreground">Loading…</p>
  }

  if (!deal || !form) {
    return (
      <div>
        <AlertItem variant="danger">{message || 'This deal could not be found.'}</AlertItem>
        <Link to="/opportunities" className="mt-4 inline-block text-sm text-primary">
          Back to deals
        </Link>
      </div>
    )
  }

  const linkedContracts = deal.contracts ?? []

  return (
    <div>
      <Link
        to="/opportunities"
        className="mb-4 inline-flex items-center gap-1 text-sm text-muted-foreground hover:text-foreground"
      >
        <ArrowLeft className="h-4 w-4" aria-hidden="true" />
        Deals
      </Link>

      <PageHeader
        title={deal.name}
        description={`${deal.reference} · ${deal.company?.name ?? 'Account'}`}
        actions={
          <>
            <StatusBadge tone={opportunityStageMeta[deal.stage].tone}>
              {opportunityStageMeta[deal.stage].label}
            </StatusBadge>
            {deal.can_update && (
              <Link
                to={`/follow-ups/new?subject_type=opportunity&subject_id=${deal.id}`}
                className="inline-flex items-center gap-2 rounded-md border border-border bg-card px-3 py-2 text-sm font-medium text-foreground hover:bg-muted"
              >
                <CalendarClock className="h-4 w-4" aria-hidden="true" />
                Follow-up
              </Link>
            )}
            {deal.can_delete && (
              <button
                type="button"
                onClick={() => void onDelete()}
                disabled={busy}
                className="inline-flex items-center gap-2 rounded-md border border-border bg-card px-3 py-2 text-sm font-medium text-destructive hover:bg-muted disabled:opacity-60"
              >
                <Trash2 className="h-4 w-4" aria-hidden="true" />
                Delete
              </button>
            )}
          </>
        }
      />

      {message && (
        <div className="mb-4">
          <AlertItem variant={alertVariant}>
            <p>{message}</p>
            {Object.entries(errors).map(([field, messages]) => (
              <p key={field} className="text-xs">
                {messages.join(' ')}
              </p>
            ))}
          </AlertItem>
        </div>
      )}

      <div className="mb-6 grid grid-cols-2 gap-4 lg:grid-cols-4">
        <StatCard label="Value" value={formatCurrency(deal.amount)} domain="cost" />
        <StatCard label="Weighted" value={formatCurrency(deal.weighted_value)} domain="cost" />
        <StatCard label="Probability" value={`${deal.probability ?? 0}%`} domain="people" />
        <StatCard
          label={deal.is_open ? 'Days in stage' : 'Closed'}
          value={deal.is_open ? deal.days_in_stage : formatDate(deal.actual_close_date)}
          domain="activity"
        />
      </div>

      <div className="grid grid-cols-1 gap-6 lg:grid-cols-3">
        <div className="flex flex-col gap-6 lg:col-span-2">
          <FormCard title="Deal details">
            <form onSubmit={onSave} className="grid grid-cols-1 gap-4 md:grid-cols-2">
              <Field label="Deal name" error={errors.name} className="md:col-span-2">
                <input
                  value={form.name}
                  onChange={(event) => setForm({ ...form, name: event.target.value })}
                  className={inputClass}
                />
              </Field>

              <Field label="Expected close" error={errors.expected_close_date}>
                <input
                  type="date"
                  value={form.expected_close_date}
                  onChange={(event) =>
                    setForm({ ...form, expected_close_date: event.target.value })
                  }
                  className={inputClass}
                />
              </Field>

              <Field label="Probability (%)" error={errors.probability}>
                <input
                  type="number"
                  min={0}
                  max={100}
                  value={form.probability}
                  onChange={(event) => setForm({ ...form, probability: event.target.value })}
                  className={inputClass}
                />
              </Field>

              <Field
                label={deal.can_set_amount ? 'Value (₱)' : 'Value (₱) — manager only'}
                error={errors.amount}
              >
                <input
                  type="number"
                  min={0}
                  value={form.amount}
                  disabled={!deal.can_set_amount}
                  onChange={(event) => setForm({ ...form, amount: event.target.value })}
                  className={inputClass}
                />
              </Field>

              <Field label="Primary contact" error={errors.primary_contact_id}>
                <select
                  value={form.primary_contact_id}
                  onChange={(event) => setForm({ ...form, primary_contact_id: event.target.value })}
                  className={inputClass}
                >
                  <option value="">Not set</option>
                  {contacts.map((contact) => (
                    <option key={contact.id} value={contact.id}>
                      {contact.name}
                    </option>
                  ))}
                </select>
              </Field>

              <Field label="Next step" error={errors.next_step} className="md:col-span-2">
                <input
                  value={form.next_step}
                  onChange={(event) => setForm({ ...form, next_step: event.target.value })}
                  className={inputClass}
                />
              </Field>

              {deal.can_assign && (
                <Field label="Owner" error={errors.owner_id}>
                  <select
                    value={form.owner_id}
                    onChange={(event) => setForm({ ...form, owner_id: event.target.value })}
                    className={inputClass}
                  >
                    <option value={String(deal.owner_id)}>
                      {deal.owner?.name ?? 'Current owner'}
                    </option>
                    {targets.map((target) => (
                      <option key={target.id} value={target.id}>
                        {target.name}
                      </option>
                    ))}
                  </select>
                </Field>
              )}

              <div className="md:col-span-2">
                <button
                  type="submit"
                  disabled={busy || !deal.can_update}
                  className="rounded-md bg-primary px-4 py-2 text-sm font-medium text-primary-foreground disabled:opacity-60"
                >
                  {busy ? 'Saving…' : 'Save deal'}
                </button>
              </div>
            </form>
          </FormCard>

          <FormCard title="Timeline" description="Touches, notes and stage changes on this deal.">
            <form onSubmit={onLogActivity} className="mb-4 flex flex-col gap-3">
              <div className="grid grid-cols-1 gap-3 md:grid-cols-3">
                <label className="block text-sm font-medium text-foreground">
                  Type
                  <select
                    value={activity.type}
                    onChange={(event) =>
                      setActivity({ ...activity, type: event.target.value as ActivityType })
                    }
                    className={inputClass}
                  >
                    {activityTypes.map((type) => (
                      <option key={type} value={type}>
                        {activityTypeMeta[type].label}
                      </option>
                    ))}
                  </select>
                </label>
                <label className="block text-sm font-medium text-foreground md:col-span-2">
                  Subject
                  <input
                    value={activity.subject}
                    onChange={(event) => setActivity({ ...activity, subject: event.target.value })}
                    placeholder="Follow-up call"
                    className={inputClass}
                  />
                </label>
              </div>
              <label className="block text-sm font-medium text-foreground">
                Notes
                <textarea
                  rows={2}
                  value={activity.body}
                  onChange={(event) => setActivity({ ...activity, body: event.target.value })}
                  className={inputClass}
                />
              </label>
              <div>
                <button
                  type="submit"
                  disabled={busy || activity.subject.trim() === '' || !deal.can_update}
                  className="rounded-md border border-border bg-card px-3 py-2 text-sm font-medium text-foreground hover:bg-muted disabled:opacity-60"
                >
                  Log activity
                </button>
              </div>
            </form>

            {timeline.length === 0 ? (
              <p className="text-sm text-muted-foreground">No activity yet.</p>
            ) : (
              <ul className="flex flex-col gap-3">
                {timeline.map((entry) => (
                  <li
                    key={entry.id}
                    className="border-b border-border pb-3 last:border-0 last:pb-0"
                  >
                    <div className="flex items-center justify-between gap-2">
                      <span className="text-sm font-medium text-foreground">{entry.subject}</span>
                      <StatusBadge tone={activityTypeMeta[entry.type].tone}>
                        {activityTypeMeta[entry.type].label}
                      </StatusBadge>
                    </div>
                    {entry.body && (
                      <p className="mt-1 text-sm text-muted-foreground">{entry.body}</p>
                    )}
                    {entry.is_follow_up && entry.due && (
                      <p className="mt-1 flex flex-wrap items-center gap-1.5">
                        <StatusBadge tone={followUpDueMeta[entry.due.state].tone}>
                          {followUpDueMeta[entry.due.state].label}
                        </StatusBadge>
                        <span className="text-xs text-muted-foreground">
                          Due {formatDateTime(entry.due_at)}
                        </span>
                      </p>
                    )}
                    <p className="mt-1 text-xs text-muted-foreground">
                      {entry.is_follow_up
                        ? `Added ${formatDateTime(entry.created_at)}`
                        : formatDateTime(entry.occurred_at ?? entry.created_at)}
                      {entry.owner ? ` · ${entry.owner.name}` : ''}
                    </p>
                  </li>
                ))}
              </ul>
            )}
          </FormCard>

          <FormCard
            title="Communications"
            description="The detailed log: calls, emails, meetings and letters on this deal."
          >
            <CommunicationList
              subjectType="opportunity"
              subjectId={deal.id}
              canLog={deal.can_update}
            />
          </FormCard>
        </div>

        <div className="flex flex-col gap-6">
          <FormCard title="Stage" description="Any-to-any, with rules.">
            <div className="flex flex-col gap-3">
              <StageMoveControl
                deal={deal}
                disabled={busy || !deal.can_change_stage}
                onRequestMove={(stage) => {
                  if (stage === 'lost') setPendingLost(true)
                  else void submitMove(stage)
                }}
              />
              <p className="text-xs text-muted-foreground">
                {deal.stage_changed_at
                  ? `In this stage since ${formatDate(deal.stage_changed_at)}.`
                  : 'Not moved yet.'}
              </p>
              {deal.is_lost && deal.lost_reason && (
                <p className="text-sm text-muted-foreground">
                  Lost reason: {lostReasonLabels[deal.lost_reason]}
                </p>
              )}
              {deal.is_won && deal.company && (
                <Link
                  to={`/companies/${deal.company_id}?tab=contracts&opportunity_id=${deal.id}`}
                  className="inline-flex items-center justify-center rounded-md bg-primary px-3 py-2 text-sm font-medium text-primary-foreground"
                >
                  Create contract from deal
                </Link>
              )}
            </div>
          </FormCard>

          <FormCard title="Contracts" description="Agreements opened from this deal.">
            {linkedContracts.length === 0 ? (
              <p className="text-sm text-muted-foreground">
                {deal.is_won
                  ? 'No contract yet — create one to activate the client.'
                  : 'A contract is created once the deal is won.'}
              </p>
            ) : (
              <ul className="flex flex-col gap-2">
                {linkedContracts.map((contract) => (
                  <li key={contract.id} className="flex items-center justify-between gap-2 text-sm">
                    <span className="text-foreground">{contract.title}</span>
                    <span className="font-mono text-xs text-muted-foreground">
                      {contract.reference}
                    </span>
                  </li>
                ))}
              </ul>
            )}
          </FormCard>
        </div>
      </div>

      {pendingLost && (
        <LostReasonDialog
          dealName={deal.name}
          busy={busy}
          onCancel={() => setPendingLost(false)}
          onConfirm={(reason) => void submitMove('lost', reason)}
        />
      )}
    </div>
  )
}

const inputClass =
  'mt-1 w-full rounded-md border border-input bg-card px-3 py-2 text-sm text-foreground outline-none focus:ring-2 focus:ring-ring disabled:opacity-60'

function Field({
  label,
  error,
  className,
  children,
}: {
  label: string
  error?: string[]
  className?: string
  children: ReactNode
}) {
  return (
    <label className={`block text-sm font-medium text-foreground ${className ?? ''}`}>
      {label}
      {children}
      {error && <span className="mt-1 block text-xs text-destructive">{error[0]}</span>}
    </label>
  )
}
