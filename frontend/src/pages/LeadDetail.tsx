import { useCallback, useEffect, useState, type FormEvent, type ReactNode } from 'react'
import { Link, useParams } from 'react-router-dom'
import { ArrowLeft } from 'lucide-react'
import PageHeader from '../components/common/PageHeader'
import FormCard from '../components/common/FormCard'
import StatusBadge from '../components/common/StatusBadge'
import AlertItem from '../components/common/AlertItem'
import LeadOwnerControl from '../components/common/LeadOwnerControl'
import {
  assignLead,
  claimLead,
  disqualifyLead,
  getAssignableUsers,
  getLead,
  getLeadActivities,
  nurtureLead,
  parseValidationError,
  qualifyLead,
  rateLead,
  updateQualification,
  type QualificationInput,
} from '../lib/leads'
import {
  activityTypeMeta,
  disqualificationReasonLabels,
  leadRatingMeta,
  leadSourceLabels,
  leadStatusMeta,
  urgencyLabels,
} from '../lib/leadMeta'
import { formatDate, formatDateTime } from '../lib/format'
import type {
  Activity,
  AssignmentTarget,
  DisqualificationReason,
  Lead,
  LeadRating,
  Urgency,
} from '../types'

interface ChecklistForm {
  estimated_headcount: string
  needed_roles: string
  target_start_date: string
  budget_rate: string
  authority_confirmed: boolean
  work_site: string
  urgency: string
  qualification_notes: string
}

const emptyChecklist: ChecklistForm = {
  estimated_headcount: '',
  needed_roles: '',
  target_start_date: '',
  budget_rate: '',
  authority_confirmed: false,
  work_site: '',
  urgency: '',
  qualification_notes: '',
}

function formFromLead(lead: Lead): ChecklistForm {
  return {
    estimated_headcount: lead.estimated_headcount ? String(lead.estimated_headcount) : '',
    needed_roles: lead.needed_roles ?? '',
    target_start_date: lead.target_start_date ?? '',
    budget_rate: lead.budget_rate ?? '',
    authority_confirmed: lead.authority_confirmed,
    work_site: lead.work_site ?? '',
    urgency: lead.urgency ?? '',
    qualification_notes: lead.qualification_notes ?? '',
  }
}

export default function LeadDetail() {
  const { id = '' } = useParams()
  const [lead, setLead] = useState<Lead | null>(null)
  const [loading, setLoading] = useState(true)
  const [saving, setSaving] = useState(false)
  const [message, setMessage] = useState('')
  const [alertVariant, setAlertVariant] = useState<'success' | 'danger'>('success')
  const [errors, setErrors] = useState<Record<string, string[]>>({})

  const [form, setForm] = useState<ChecklistForm>(emptyChecklist)
  const [disqualifyOpen, setDisqualifyOpen] = useState(false)
  const [disqualifyReason, setDisqualifyReason] = useState<DisqualificationReason>('no_budget')
  const [disqualifyNotes, setDisqualifyNotes] = useState('')

  const [targets, setTargets] = useState<AssignmentTarget[]>([])
  const [activities, setActivities] = useState<Activity[]>([])
  const [assignBusy, setAssignBusy] = useState(false)

  const load = useCallback(async () => {
    setLoading(true)
    try {
      const [data, assignable, history] = await Promise.all([
        getLead(id),
        getAssignableUsers(),
        getLeadActivities(id),
      ])
      setLead(data)
      setForm(formFromLead(data))
      setTargets(assignable)
      setActivities(history)
    } catch {
      setErrors({})
      setAlertVariant('danger')
      setMessage('Could not load this lead.')
    } finally {
      setLoading(false)
    }
  }, [id])

  useEffect(() => {
    void load()
  }, [load])

  const update = <K extends keyof ChecklistForm>(field: K, value: ChecklistForm[K]) =>
    setForm((previous) => ({ ...previous, [field]: value }))

  const applyResult = (updated: Lead, success: string) => {
    setLead(updated)
    setForm(formFromLead(updated))
    setErrors({})
    setAlertVariant('success')
    setMessage(success)
  }

  const handleFailure = (error: unknown, fallback: string) => {
    const validation = parseValidationError(error)
    setAlertVariant('danger')
    if (validation) {
      setErrors(validation.errors)
      setMessage(validation.message)
    } else {
      setMessage(fallback)
    }
  }

  const run = async (
    action: () => Promise<Lead>,
    success: string,
    fallback: string,
  ): Promise<boolean> => {
    setSaving(true)
    try {
      applyResult(await action(), success)
      return true
    } catch (error) {
      handleFailure(error, fallback)
      return false
    } finally {
      setSaving(false)
    }
  }

  const onConfirmDisqualify = async () => {
    const ok = await run(
      () => disqualifyLead(id, { reason: disqualifyReason, notes: disqualifyNotes || undefined }),
      'Lead disqualified.',
      'Could not disqualify.',
    )
    if (ok) setDisqualifyOpen(false)
  }

  const onClaimLead = async () => {
    setAssignBusy(true)
    try {
      const updated = await claimLead(id)
      applyResult(updated, 'Lead claimed.')
      setActivities(await getLeadActivities(id))
    } catch (error) {
      handleFailure(error, 'Could not claim this lead.')
    } finally {
      setAssignBusy(false)
    }
  }

  const onAssignLead = async (ownerId: number) => {
    setAssignBusy(true)
    try {
      const updated = await assignLead(id, ownerId)
      applyResult(updated, 'Owner updated.')
      setActivities(await getLeadActivities(id))
    } catch (error) {
      handleFailure(error, 'Could not reassign this lead.')
    } finally {
      setAssignBusy(false)
    }
  }

  const showBudget = lead?.budget_rate !== undefined

  const onSaveChecklist = async (event: FormEvent) => {
    event.preventDefault()
    await run(
      () => updateQualification(id, toInput(form, showBudget)),
      'Qualification saved.',
      'Could not save.',
    )
  }

  if (loading && !lead) {
    return <p className="text-sm text-muted-foreground">Loading…</p>
  }

  if (!lead) {
    return (
      <div>
        <AlertItem variant="danger">{message || 'Lead not found.'}</AlertItem>
        <Link to="/leads" className="mt-4 inline-block text-sm font-medium text-primary">
          Back to leads
        </Link>
      </div>
    )
  }

  const statusMeta = leadStatusMeta[lead.status]

  return (
    <div>
      <Link
        to="/leads"
        className="mb-3 inline-flex items-center gap-2 text-sm text-muted-foreground hover:text-foreground"
      >
        <ArrowLeft className="h-4 w-4" aria-hidden="true" />
        Back to leads
      </Link>

      <PageHeader
        title={lead.company_name}
        description={`${lead.reference} · ${lead.contact_name}`}
        actions={<StatusBadge tone={statusMeta.tone}>{statusMeta.label}</StatusBadge>}
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

      <div className="grid grid-cols-1 gap-6 lg:grid-cols-3">
        <div className="flex flex-col gap-6 lg:col-span-2">
          <FormCard
            title="Qualification checklist"
            description="Minimum to qualify: headcount + needed roles + a contact channel."
          >
            <form onSubmit={onSaveChecklist} className="grid grid-cols-1 gap-4 md:grid-cols-2">
              <Field label="Estimated headcount" error={errors.estimated_headcount}>
                <input
                  type="number"
                  min={1}
                  value={form.estimated_headcount}
                  disabled={!lead.can_qualify}
                  onChange={(event) => update('estimated_headcount', event.target.value)}
                  className={inputClass}
                />
              </Field>

              <Field label="Urgency" error={errors.urgency}>
                <select
                  value={form.urgency}
                  disabled={!lead.can_qualify}
                  onChange={(event) => update('urgency', event.target.value)}
                  className={inputClass}
                >
                  <option value="">Not set</option>
                  {(Object.keys(urgencyLabels) as Urgency[]).map((value) => (
                    <option key={value} value={value}>
                      {urgencyLabels[value]}
                    </option>
                  ))}
                </select>
              </Field>

              <Field label="Target start date" error={errors.target_start_date}>
                <input
                  type="date"
                  value={form.target_start_date}
                  disabled={!lead.can_qualify}
                  onChange={(event) => update('target_start_date', event.target.value)}
                  className={inputClass}
                />
              </Field>

              {showBudget && (
                <Field label="Budget rate (₱, sensitive)" error={errors.budget_rate}>
                  <input
                    type="number"
                    min={0}
                    step="0.01"
                    value={form.budget_rate}
                    disabled={!lead.can_qualify}
                    onChange={(event) => update('budget_rate', event.target.value)}
                    className={inputClass}
                  />
                </Field>
              )}

              <Field label="Work site" error={errors.work_site}>
                <input
                  value={form.work_site}
                  disabled={!lead.can_qualify}
                  onChange={(event) => update('work_site', event.target.value)}
                  className={inputClass}
                />
              </Field>

              <div className="flex items-end">
                <label className="flex items-center gap-2 text-sm text-foreground">
                  <input
                    type="checkbox"
                    checked={form.authority_confirmed}
                    disabled={!lead.can_qualify}
                    onChange={(event) => update('authority_confirmed', event.target.checked)}
                    className="h-4 w-4 rounded border-input"
                  />
                  Decision-maker authority confirmed
                </label>
              </div>

              <Field label="Needed roles" error={errors.needed_roles} className="md:col-span-2">
                <textarea
                  rows={2}
                  value={form.needed_roles}
                  disabled={!lead.can_qualify}
                  onChange={(event) => update('needed_roles', event.target.value)}
                  className={inputClass}
                />
              </Field>

              <Field
                label="Qualification notes"
                error={errors.qualification_notes}
                className="md:col-span-2"
              >
                <textarea
                  rows={2}
                  value={form.qualification_notes}
                  disabled={!lead.can_qualify}
                  onChange={(event) => update('qualification_notes', event.target.value)}
                  className={inputClass}
                />
              </Field>

              {lead.can_qualify && (
                <div className="md:col-span-2">
                  <button
                    type="submit"
                    disabled={saving}
                    className="rounded-md bg-primary px-4 py-2 text-sm font-medium text-primary-foreground disabled:opacity-60"
                  >
                    {saving ? 'Saving…' : 'Save checklist'}
                  </button>
                </div>
              )}
            </form>
          </FormCard>

          <FormCard title="Capture details">
            <dl className="grid grid-cols-1 gap-3 text-sm sm:grid-cols-2">
              <Detail label="Contact" value={lead.contact_name} />
              <Detail label="Email" value={lead.contact_email ?? '—'} />
              <Detail label="Phone" value={lead.contact_phone ?? '—'} />
              <Detail label="Source" value={leadSourceLabels[lead.source]} />
              <Detail label="Owner" value={lead.owner?.name ?? 'Unassigned'} />
              <Detail label="Created" value={formatDate(lead.created_at)} />
            </dl>
          </FormCard>

          <FormCard title="Activity history" description="Ownership changes and system events.">
            {activities.length === 0 ? (
              <p className="text-sm text-muted-foreground">No activity recorded yet.</p>
            ) : (
              <ul className="flex flex-col gap-4">
                {activities.map((activity) => {
                  const meta = activityTypeMeta[activity.type]
                  return (
                    <li key={activity.id} className="flex gap-3">
                      <StatusBadge tone={meta.tone}>{meta.label}</StatusBadge>
                      <div className="min-w-0 flex-1">
                        <p className="text-sm text-foreground">{activity.subject}</p>
                        {activity.body && (
                          <p className="mt-0.5 text-xs text-muted-foreground">{activity.body}</p>
                        )}
                        <p className="mt-0.5 text-xs text-muted-foreground">
                          {activity.owner?.name ? `${activity.owner.name} · ` : ''}
                          {formatDateTime(activity.occurred_at ?? activity.created_at)}
                        </p>
                      </div>
                    </li>
                  )
                })}
              </ul>
            )}
          </FormCard>
        </div>

        <div className="flex flex-col gap-6">
          <FormCard title="Ownership">
            <div className="flex items-center justify-between">
              <span className="text-sm text-muted-foreground">Responsible salesrep</span>
              <span className="text-sm font-medium text-foreground">
                {lead.owner?.name ?? 'Unassigned'}
              </span>
            </div>

            {(lead.can_claim || lead.can_reassign) && (
              <div className="mt-4">
                <LeadOwnerControl
                  lead={lead}
                  targets={targets}
                  busy={assignBusy}
                  onClaim={() => void onClaimLead()}
                  onAssign={(ownerId) => void onAssignLead(ownerId)}
                />
              </div>
            )}

            {lead.can_claim && (
              <p className="mt-2 text-xs text-muted-foreground">
                Claim this lead to work it — you then own its qualification.
              </p>
            )}
          </FormCard>

          <FormCard title="Assessment">
            <div className="flex items-center justify-between">
              <span className="text-sm text-muted-foreground">Rating</span>
              {lead.rating ? (
                <StatusBadge tone={leadRatingMeta[lead.rating].tone}>
                  {leadRatingMeta[lead.rating].label}
                </StatusBadge>
              ) : (
                <span className="text-sm text-muted-foreground">Not rated</span>
              )}
            </div>

            <div className="mt-3 flex items-center justify-between">
              <span className="text-sm text-muted-foreground">Checklist score</span>
              <span className="text-sm font-semibold text-foreground">{lead.score ?? 0}/100</span>
            </div>

            {lead.missing_criteria.length > 0 && (
              <p className="mt-3 text-xs text-muted-foreground">
                Missing: {lead.missing_criteria.join(', ')}
              </p>
            )}

            {lead.can_qualify && (
              <div className="mt-4 flex flex-wrap gap-2">
                {(Object.keys(leadRatingMeta) as LeadRating[]).map((rating) => (
                  <button
                    key={rating}
                    type="button"
                    disabled={saving}
                    onClick={() =>
                      void run(
                        () => rateLead(id, { rating }),
                        `Rated ${rating}.`,
                        'Could not rate.',
                      )
                    }
                    className="rounded-md border border-border px-3 py-1.5 text-xs font-medium text-foreground hover:bg-muted disabled:opacity-60"
                  >
                    {leadRatingMeta[rating].label}
                  </button>
                ))}
              </div>
            )}
          </FormCard>

          {lead.can_qualify && (
            <FormCard title="Outcome">
              <div className="flex flex-col gap-2">
                <button
                  type="button"
                  disabled={saving}
                  onClick={() =>
                    void run(() => qualifyLead(id), 'Lead qualified.', 'Could not qualify.')
                  }
                  className="rounded-md bg-people px-4 py-2 text-sm font-medium text-primary-foreground disabled:opacity-60"
                >
                  Mark qualified
                </button>
                <button
                  type="button"
                  disabled={saving}
                  onClick={() =>
                    void run(() => nurtureLead(id), 'Moved to nurture.', 'Could not update.')
                  }
                  className="rounded-md border border-border px-4 py-2 text-sm font-medium text-foreground hover:bg-muted disabled:opacity-60"
                >
                  Move to nurture
                </button>
                <button
                  type="button"
                  onClick={() => setDisqualifyOpen((value) => !value)}
                  className="rounded-md border border-destructive/40 px-4 py-2 text-sm font-medium text-destructive hover:bg-destructive/10"
                >
                  Disqualify
                </button>
              </div>

              {disqualifyOpen && (
                <div className="mt-4 border-t border-border pt-4">
                  <Field label="Reason" error={errors.reason}>
                    <select
                      value={disqualifyReason}
                      onChange={(event) =>
                        setDisqualifyReason(event.target.value as DisqualificationReason)
                      }
                      className={inputClass}
                    >
                      {(Object.keys(disqualificationReasonLabels) as DisqualificationReason[]).map(
                        (value) => (
                          <option key={value} value={value}>
                            {disqualificationReasonLabels[value]}
                          </option>
                        ),
                      )}
                    </select>
                  </Field>
                  <Field label="Notes" error={errors.notes}>
                    <textarea
                      rows={2}
                      value={disqualifyNotes}
                      onChange={(event) => setDisqualifyNotes(event.target.value)}
                      className={inputClass}
                    />
                  </Field>
                  <button
                    type="button"
                    disabled={saving}
                    onClick={() => void onConfirmDisqualify()}
                    className="mt-3 rounded-md bg-destructive px-4 py-2 text-sm font-medium text-destructive-foreground disabled:opacity-60"
                  >
                    Confirm disqualify
                  </button>
                </div>
              )}
            </FormCard>
          )}
        </div>
      </div>
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

function Detail({ label, value }: { label: string; value: string }) {
  return (
    <div>
      <dt className="text-xs uppercase text-muted-foreground">{label}</dt>
      <dd className="text-foreground">{value}</dd>
    </div>
  )
}

function toInput(form: ChecklistForm, showBudget: boolean): QualificationInput {
  const trimmed = (value: string) => (value.trim() === '' ? null : value.trim())

  const input: QualificationInput = {
    estimated_headcount: form.estimated_headcount ? Number(form.estimated_headcount) : null,
    needed_roles: trimmed(form.needed_roles),
    target_start_date: trimmed(form.target_start_date),
    authority_confirmed: form.authority_confirmed,
    work_site: trimmed(form.work_site),
    urgency: trimmed(form.urgency),
    qualification_notes: trimmed(form.qualification_notes),
  }

  if (showBudget) {
    input.budget_rate = form.budget_rate ? Number(form.budget_rate) : null
  }

  return input
}
