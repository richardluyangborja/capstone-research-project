import { useCallback, useEffect, useState, type FormEvent, type ReactNode } from 'react'
import { Link, useNavigate, useParams, useSearchParams } from 'react-router-dom'
import { ArrowLeft, CalendarPlus } from 'lucide-react'
import PageHeader from '../components/common/PageHeader'
import FormCard from '../components/common/FormCard'
import AlertItem from '../components/common/AlertItem'
import StatusBadge from '../components/common/StatusBadge'
import { createTask, getTask, updateTask, updateTaskStatus } from '../lib/followUps'
import { communicationSubjectLabels } from '../lib/communicationMeta'
import { formatDateTime, toDateTimeLocal } from '../lib/format'
import { defaultDueAt, followUpDueMeta, taskStatusMeta } from '../lib/followUpMeta'
import { getAssignableUsers, getLead, listLeads, parseValidationError } from '../lib/leads'
import { getCompany, listCompanies } from '../lib/companies'
import { getContact, listContacts } from '../lib/contacts'
import { getOpportunity, listOpportunities } from '../lib/opportunities'
import { useAuth } from '../lib/auth-context'
import type { ActivityStatus, AssignmentTarget, CommunicationSubjectType, Task } from '../types'

interface RecordOption {
  id: string
  label: string
}

const inputClass =
  'mt-1 w-full rounded-md border border-input bg-card px-3 py-2 text-sm text-foreground outline-none focus:ring-2 focus:ring-ring disabled:opacity-60'

/** The record types the picker can search without an account context. */
const pickableTypes: CommunicationSubjectType[] = ['lead', 'company', 'contact', 'opportunity']

// Module 04 §UI — putting something owed on a record, and editing it afterwards. The same form
// serves `/follow-ups/new` and `/follow-ups/:id`; a task's record is fixed once it exists
// (spec 12 §Payloads), so editing shows it rather than letting it be re-pointed.
export default function FollowUpForm() {
  const navigate = useNavigate()
  const { id } = useParams()
  const [params] = useSearchParams()
  const editing = Boolean(id)

  const linkedType = (params.get('subject_type') as CommunicationSubjectType | null) ?? null
  const linkedId = params.get('subject_id') ?? ''
  // A contract has no detail page to link through, so the deep link carries its title.
  const linkedLabel = params.get('subject_label') ?? ''

  const [task, setTask] = useState<Task | null>(null)
  const [loading, setLoading] = useState(editing)
  const [subjectType, setSubjectType] = useState<CommunicationSubjectType>(linkedType ?? 'company')
  const [selected, setSelected] = useState<RecordOption | null>(null)
  const [recordQuery, setRecordQuery] = useState('')
  const [options, setOptions] = useState<RecordOption[]>([])
  const [subject, setSubject] = useState('')
  const [body, setBody] = useState('')
  const [dueAt, setDueAt] = useState(defaultDueAt())
  const [ownerId, setOwnerId] = useState('')
  const [targets, setTargets] = useState<AssignmentTarget[]>([])
  const [saving, setSaving] = useState(false)
  const [busy, setBusy] = useState(false)
  const [note, setNote] = useState('')
  const [error, setError] = useState('')
  const [errors, setErrors] = useState<Record<string, string[]>>({})

  // A salesrep may only owe themselves (ADR-0004), so they get no picker at all — the API
  // defaults the owner to the caller. A manager+ hands work to the team.
  const { user } = useAuth()
  const canAssign = user?.role !== 'salesrep'

  // Who the caller may hand work to (ADR-0004). Only a manager+ gets the list at all; a salesrep
  // owes themselves and the API defaults the owner to them.
  useEffect(() => {
    if (!canAssign) return
    let active = true
    void (async () => {
      try {
        const assignable = await getAssignableUsers()
        if (active) setTargets(assignable)
      } catch {
        // non-critical: the form still defaults to the caller
      }
    })()
    return () => {
      active = false
    }
  }, [canAssign])

  // Editing: load the task and prefill from it.
  useEffect(() => {
    if (!id) return
    let active = true
    setLoading(true)
    void (async () => {
      try {
        const loaded = await getTask(id)
        if (!active) return
        setTask(loaded)
        setSubject(loaded.subject)
        setBody(loaded.body ?? '')
        setDueAt(toDateTimeLocal(loaded.due_at))
        setOwnerId('')
        setSelected(
          loaded.record ? { id: loaded.record.id, label: loaded.record.label ?? '' } : null,
        )
      } catch {
        if (active) setError('Could not load this follow-up.')
      } finally {
        if (active) setLoading(false)
      }
    })()
    return () => {
      active = false
    }
  }, [id])

  // A deep link names the record, so it opens with the picker already answered.
  useEffect(() => {
    if (editing || !linkedType || !linkedId) return
    let active = true
    void (async () => {
      const loaded = await loadRecord(linkedType, linkedId)
      if (active) setSelected(loaded ?? { id: linkedId, label: linkedLabel || linkedId })
    })()
    return () => {
      active = false
    }
  }, [editing, linkedType, linkedId, linkedLabel])

  const searchOptions = useCallback(async (type: CommunicationSubjectType, query: string) => {
    try {
      setOptions(await searchRecords(type, query))
    } catch {
      setOptions([])
    }
  }, [])

  useEffect(() => {
    if (editing || selected) return
    void searchOptions(subjectType, recordQuery)
  }, [editing, selected, subjectType, recordQuery, searchOptions])

  const buildInput = () => ({
    subject,
    body: body === '' ? null : body,
    due_at: new Date(dueAt).toISOString(),
    ...(ownerId !== '' ? { owner_id: Number(ownerId) } : {}),
  })

  const onSubmit = async (event: FormEvent) => {
    event.preventDefault()
    setSaving(true)
    setError('')
    setErrors({})
    setNote('')
    try {
      if (editing && id) {
        const updated = await updateTask(id, buildInput())
        setTask(updated)
        setDueAt(toDateTimeLocal(updated.due_at))
        setNote('Saved.')
      } else {
        if (!selected) {
          setErrors({ subject_id: ['Choose the record this follow-up belongs to.'] })
          return
        }
        const created = await createTask({
          subject_type: subjectType,
          subject_id: selected.id,
          ...buildInput(),
        })
        navigate(`/follow-ups/${created.id}`)
      }
    } catch (caught) {
      const validation = parseValidationError(caught)
      setErrors(validation?.errors ?? {})
      setError(validation?.message ?? 'Could not save the follow-up.')
    } finally {
      setSaving(false)
    }
  }

  const move = async (status: ActivityStatus) => {
    if (!id) return
    setBusy(true)
    setError('')
    setNote('')
    try {
      setTask(await updateTaskStatus(id, status))
      setNote(`Moved to ${taskStatusMeta[status].label.toLowerCase()}.`)
    } catch (caught) {
      const validation = parseValidationError(caught)
      setError(validation?.message ?? 'Could not move the follow-up.')
    } finally {
      setBusy(false)
    }
  }

  if (loading) {
    return <p className="text-sm text-muted-foreground">Loading the follow-up…</p>
  }

  return (
    <div>
      <PageHeader
        title={editing ? 'Follow-up' : 'New follow-up'}
        description={
          editing
            ? 'What is owed, to whom, and by when.'
            : 'Put something owed on a lead, account, contact or deal and give it a due time.'
        }
        actions={
          <Link
            to="/follow-ups"
            className="inline-flex items-center gap-2 rounded-md border border-border bg-card px-3 py-2 text-sm font-medium text-foreground hover:bg-muted"
          >
            <ArrowLeft className="h-4 w-4" aria-hidden="true" />
            Worklist
          </Link>
        }
      />

      {note && (
        <div className="mb-4">
          <AlertItem variant="success">{note}</AlertItem>
        </div>
      )}
      {error && (
        <div className="mb-4">
          <AlertItem variant="danger">{error}</AlertItem>
        </div>
      )}

      <form onSubmit={(event) => void onSubmit(event)} className="flex flex-col gap-6">
        <FormCard title="The record" description="A follow-up always hangs off exactly one record.">
          {editing && task ? (
            <div className="flex flex-wrap items-center gap-2 text-sm">
              <span className="text-muted-foreground">Record</span>
              <span className="font-medium text-foreground">{task.record?.label ?? '—'}</span>
              <span className="text-xs text-muted-foreground">
                A task stays on the record it was created for.
              </span>
            </div>
          ) : (
            <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
              <Field label="Record type" error={errors.subject_type}>
                <select
                  value={subjectType}
                  onChange={(event) => {
                    setSubjectType(event.target.value as CommunicationSubjectType)
                    setSelected(null)
                    setOwnerId('')
                  }}
                  disabled={Boolean(linkedId)}
                  className={inputClass}
                >
                  {(Object.keys(communicationSubjectLabels) as CommunicationSubjectType[]).map(
                    (type) => (
                      <option key={type} value={type}>
                        {communicationSubjectLabels[type]}
                      </option>
                    ),
                  )}
                </select>
              </Field>

              <Field label="Record" error={errors.subject_id}>
                {selected ? (
                  <div className="mt-1 flex items-center justify-between gap-2 rounded-md border border-border bg-muted px-3 py-2 text-sm">
                    <span className="text-foreground">{selected.label}</span>
                    {!linkedId && (
                      <button
                        type="button"
                        onClick={() => setSelected(null)}
                        className="text-xs font-medium text-counts"
                      >
                        Change
                      </button>
                    )}
                  </div>
                ) : !pickableTypes.includes(subjectType) ? (
                  <p className="mt-1 text-xs text-muted-foreground">
                    Open the record from its own page to put a follow-up on it.
                  </p>
                ) : (
                  <>
                    <input
                      value={recordQuery}
                      onChange={(event) => setRecordQuery(event.target.value)}
                      placeholder={`Search ${communicationSubjectLabels[subjectType].toLowerCase()}s…`}
                      className={inputClass}
                    />
                    <select
                      aria-label="Record"
                      value=""
                      onChange={(event) => {
                        const option = options.find((entry) => entry.id === event.target.value)
                        setSelected(option ?? null)
                      }}
                      className={inputClass}
                    >
                      <option value="">Select a record…</option>
                      {options.map((option) => (
                        <option key={option.id} value={option.id}>
                          {option.label}
                        </option>
                      ))}
                    </select>
                  </>
                )}
              </Field>
            </div>
          )}
        </FormCard>

        <FormCard title="What is owed" description="A reminder without a time is not a reminder.">
          <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
            <Field label="Follow-up" error={errors.subject} className="md:col-span-2">
              <input
                value={subject}
                onChange={(event) => setSubject(event.target.value)}
                placeholder="Send the revised rate sheet"
                className={inputClass}
              />
            </Field>

            <Field label="Notes" error={errors.body} className="md:col-span-2">
              <textarea
                value={body}
                onChange={(event) => setBody(event.target.value)}
                rows={3}
                placeholder="Anything the person picking this up needs to know."
                className={inputClass}
              />
            </Field>

            <Field label="Due" error={errors.due_at}>
              <input
                type="datetime-local"
                value={dueAt}
                onChange={(event) => setDueAt(event.target.value)}
                className={inputClass}
              />
            </Field>

            <Field label="Owes it" error={errors.owner_id}>
              <select
                value={ownerId}
                onChange={(event) => setOwnerId(event.target.value)}
                disabled={!canAssign}
                className={inputClass}
              >
                <option value="">
                  {editing && task?.owner ? `${task.owner.name} (current)` : 'Me'}
                </option>
                {canAssign &&
                  targets.map((target) => (
                    <option key={target.id} value={target.id}>
                      {target.name}
                    </option>
                  ))}
              </select>
            </Field>
          </div>
        </FormCard>

        <div className="flex flex-wrap items-center gap-3">
          <button
            type="submit"
            disabled={saving}
            className="inline-flex items-center gap-2 rounded-md bg-primary px-3 py-2 text-sm font-medium text-primary-foreground disabled:opacity-60"
          >
            <CalendarPlus className="h-4 w-4" aria-hidden="true" />
            {saving ? 'Saving…' : editing ? 'Save changes' : 'Create follow-up'}
          </button>
          <Link to="/follow-ups" className="text-sm font-medium text-muted-foreground">
            Cancel
          </Link>
        </div>
      </form>

      {editing && task && (
        <div className="mt-6">
          <FormCard
            title="Status"
            description="A task is completed or cancelled — never deleted — and either can be reopened."
          >
            <div className="flex flex-wrap items-center gap-3">
              <StatusBadge tone={taskStatusMeta[task.status.value].tone}>
                {taskStatusMeta[task.status.value].label}
              </StatusBadge>
              {task.due && (
                <StatusBadge tone={followUpDueMeta[task.due.state].tone}>
                  {followUpDueMeta[task.due.state].label}
                </StatusBadge>
              )}
              <span className="text-xs text-muted-foreground">
                Due {formatDateTime(task.due_at)}
              </span>
            </div>

            <div className="mt-4 flex flex-wrap items-center gap-2 text-sm font-medium">
              {task.can_transition && task.status.value === 'open' && (
                <button
                  type="button"
                  disabled={busy}
                  onClick={() => void move('done')}
                  className="rounded-md border border-border px-3 py-2 text-foreground hover:bg-muted disabled:opacity-60"
                >
                  Complete
                </button>
              )}
              {task.can_transition && task.status.value === 'open' && (
                <button
                  type="button"
                  disabled={busy}
                  onClick={() => void move('cancelled')}
                  className="rounded-md border border-border px-3 py-2 text-muted-foreground hover:bg-muted disabled:opacity-60"
                >
                  Cancel
                </button>
              )}
              {task.can_transition && task.status.value !== 'open' && (
                <button
                  type="button"
                  disabled={busy}
                  onClick={() => void move('open')}
                  className="rounded-md border border-border px-3 py-2 text-foreground hover:bg-muted disabled:opacity-60"
                >
                  Reopen
                </button>
              )}
              {!task.can_transition && (
                <span className="text-xs text-muted-foreground">
                  Someone else owes this one — ask them to move it.
                </span>
              )}
            </div>
          </FormCard>
        </div>
      )}
    </div>
  )
}

/** Where each record type's values come from when the picker has to search them. */
const searchRecords = async (type: CommunicationSubjectType, query: string) => {
  const search = query.trim() || undefined

  switch (type) {
    case 'lead': {
      const response = await listLeads({ search })
      return response.data.map((lead) => ({
        id: lead.id,
        label: `${lead.company_name} (${lead.reference})`,
      }))
    }
    case 'company': {
      const response = await listCompanies({ search })
      return response.data.map((company) => ({ id: company.id, label: company.name }))
    }
    case 'contact': {
      const response = await listContacts({ search })
      return response.data.map((contact) => ({
        id: contact.id,
        label: `${contact.name}${contact.company ? ` — ${contact.company.name}` : ''}`,
      }))
    }
    case 'opportunity': {
      const response = await listOpportunities({ search })
      return response.data.map((opportunity) => ({
        id: opportunity.id,
        label: `${opportunity.name}${opportunity.company ? ` — ${opportunity.company.name}` : ''}`,
      }))
    }
    default:
      return []
  }
}

/** The one record a deep link names, when the picker was never opened. */
const loadRecord = async (
  type: CommunicationSubjectType,
  id: string,
): Promise<RecordOption | null> => {
  switch (type) {
    case 'lead': {
      const lead = await getLead(id)
      return { id: lead.id, label: `${lead.company_name} (${lead.reference})` }
    }
    case 'company': {
      const company = await getCompany(id)
      return { id: company.id, label: company.name }
    }
    case 'contact': {
      const contact = await getContact(id)
      return { id: contact.id, label: contact.name }
    }
    case 'opportunity': {
      const opportunity = await getOpportunity(id)
      return { id: opportunity.id, label: opportunity.name }
    }
    default:
      return null
  }
}

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
