import { useCallback, useEffect, useState, type FormEvent, type ReactNode } from 'react'
import { useNavigate, useSearchParams } from 'react-router-dom'
import PageHeader from '../components/common/PageHeader'
import FormCard from '../components/common/FormCard'
import AlertItem from '../components/common/AlertItem'
import { createCommunication } from '../lib/communications'
import {
  communicationChannelMeta,
  communicationOutcomes,
  communicationOutcomeMeta,
  defaultCommunicationStatus,
  isConversationalChannel,
  settableCommunicationStatuses,
  communicationStatusMeta,
  communicationSubjectLabels,
} from '../lib/communicationMeta'
import { getLead, listLeads, parseValidationError } from '../lib/leads'
import { getCompany, listCompanies } from '../lib/companies'
import { getContact, listCompanyContacts, listContacts } from '../lib/contacts'
import { getOpportunity, listOpportunities } from '../lib/opportunities'
import { toDateTimeLocal } from '../lib/format'
import type {
  CommunicationChannel,
  CommunicationDirection,
  CommunicationInput,
  CommunicationOutcome,
  CommunicationStatus,
  CommunicationSubjectType,
} from '../types'

interface RecordOption {
  id: string
  label: string
  /** Some records can offer their account's contacts (spec 11 §UI). */
  companyId: string | null
}

const inputClass =
  'mt-1 w-full rounded-md border border-input bg-card px-3 py-2 text-sm text-foreground outline-none focus:ring-2 focus:ring-ring disabled:opacity-60'

/** The record types the picker can search without an account context. */
const pickableTypes: CommunicationSubjectType[] = ['lead', 'company', 'contact', 'opportunity']

// Module 03 §UI — log a call, email, meeting or letter against a record. This is the detailed
// entry point; the quick "log activity" form on the record pages stays as it is.
export default function NewCommunication() {
  const navigate = useNavigate()
  const [params] = useSearchParams()

  const linkedType = (params.get('subject_type') as CommunicationSubjectType | null) ?? null
  const linkedId = params.get('subject_id') ?? ''
  const linkedLabel = params.get('subject_label') ?? ''

  const [subjectType, setSubjectType] = useState<CommunicationSubjectType>(linkedType ?? 'company')
  const [selected, setSelected] = useState<RecordOption | null>(
    linkedId ? { id: linkedId, label: linkedLabel || linkedId, companyId: null } : null,
  )
  const [options, setOptions] = useState<RecordOption[]>([])
  const [recordQuery, setRecordQuery] = useState('')
  const [contacts, setContacts] = useState<{ id: string; name: string }[]>([])

  const [channel, setChannel] = useState<CommunicationChannel>('email')
  const [direction, setDirection] = useState<CommunicationDirection>('outbound')
  const [status, setStatus] = useState<CommunicationStatus>(
    defaultCommunicationStatus('email', 'outbound'),
  )
  const [statusTouched, setStatusTouched] = useState(false)
  const [contactId, setContactId] = useState('')
  const [occurredAt, setOccurredAt] = useState(toDateTimeLocal(new Date()))
  const [duration, setDuration] = useState('')
  const [outcome, setOutcome] = useState<CommunicationOutcome | ''>('')
  const [fromName, setFromName] = useState('')
  const [fromAddress, setFromAddress] = useState('')
  const [toName, setToName] = useState('')
  const [toAddress, setToAddress] = useState('')
  const [title, setTitle] = useState('')
  const [body, setBody] = useState('')

  const [saving, setSaving] = useState(false)
  const [message, setMessage] = useState('')
  const [errors, setErrors] = useState<Record<string, string[]>>({})

  // A record the caller linked to: fetch its label (and account) rather than trusting the URL.
  useEffect(() => {
    if (!linkedId) return
    void (async () => {
      try {
        const record = await loadRecord(subjectType, linkedId, linkedLabel)
        if (record) setSelected(record)
      } catch {
        // Keep the label from the link; the API still checks the record is visible.
      }
    })()
  }, [linkedId, subjectType, linkedLabel])

  // Search the chosen record type as the user types.
  useEffect(() => {
    if (linkedId) return
    const timer = setTimeout(() => {
      void (async () => {
        try {
          setOptions(await searchRecords(subjectType, recordQuery))
        } catch {
          setOptions([])
        }
      })()
    }, 250)
    return () => clearTimeout(timer)
  }, [subjectType, recordQuery, linkedId])

  // The account's contacts, when the record belongs to one.
  useEffect(() => {
    const companyId = selected?.companyId
    if (!companyId) {
      setContacts([])
      return
    }
    void (async () => {
      try {
        const list = await listCompanyContacts(companyId)
        setContacts(list.map((contact) => ({ id: contact.id, name: contact.name })))
      } catch {
        setContacts([])
      }
    })()
  }, [selected?.companyId])

  // The status follows the channel and direction until the user overrides it — and a status the
  // pair cannot have (a draft on a call) always falls back, so the select never goes blank.
  useEffect(() => {
    const available = settableCommunicationStatuses(channel, direction)
    if (!statusTouched || !available.includes(status)) {
      setStatus(defaultCommunicationStatus(channel, direction))
    }
  }, [channel, direction, status, statusTouched])

  const conversational = isConversationalChannel(channel)

  const chooseRecord = useCallback(
    (id: string) => {
      setSelected((previous) => {
        const match = options.find((option) => option.id === id) ?? null
        if (match) return match
        return previous && previous.id === id ? previous : null
      })
      setContactId('')
    },
    [options],
  )

  const onSubmit = async (event: FormEvent) => {
    event.preventDefault()
    setMessage('')
    setErrors({})

    if (!selected) {
      setMessage('Pick the record this communication belongs to.')
      return
    }

    setSaving(true)
    try {
      const created = await createCommunication(
        toInput({
          subjectType,
          selected,
          channel,
          direction,
          status,
          contactId,
          occurredAt,
          duration,
          outcome,
          fromName,
          fromAddress,
          toName,
          toAddress,
          title,
          body,
        }),
      )
      navigate(`/communications/${created.id}`)
    } catch (error) {
      const validation = parseValidationError(error)
      if (validation) {
        setErrors(validation.errors)
        setMessage(validation.message)
      } else {
        setMessage('Could not log the communication.')
      }
    } finally {
      setSaving(false)
    }
  }

  const statusNote =
    status === 'draft'
      ? 'A draft is saved but not sent — you can send it from its own page.'
      : status === 'logged'
        ? 'A logged entry can still be corrected by its author.'
        : 'A sent or received message is a record of what was said — it cannot be edited later.'

  const isDraft = status === 'draft'

  return (
    <div>
      <PageHeader
        title="Log communication"
        description="Record a call, email, meeting or letter against a lead, account, contact or deal."
      />
      {message && (
        <div className="mb-4">
          <AlertItem variant="danger">
            <p>{message}</p>
            {Object.entries(errors).map(([field, messages]) => (
              <p key={field} className="text-xs">
                {messages.join(' ')}
              </p>
            ))}
          </AlertItem>
        </div>
      )}

      <form onSubmit={onSubmit} className="max-w-3xl">
        <FormCard title="Record">
          <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
            <Field label="Record type" error={errors.subject_type}>
              <select
                value={subjectType}
                onChange={(event) => {
                  setSubjectType(event.target.value as CommunicationSubjectType)
                  setSelected(null)
                  setContactId('')
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
              {linkedId || selected ? (
                <div className="mt-1 flex items-center justify-between gap-2 rounded-md border border-border bg-muted px-3 py-2 text-sm">
                  <span className="text-foreground">{selected?.label ?? linkedId}</span>
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
                  Open a contract from the account's Contracts tab to log against it.
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
                    // Reaching this branch means nothing is chosen yet: either the user is
                    // picking for the first time, or they cleared the earlier choice.
                    value=""
                    onChange={(event) => chooseRecord(event.target.value)}
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
        </FormCard>

        <div className="mt-6">
          <FormCard title="The communication" description={statusNote}>
            <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
              <Field label="Channel" error={errors.channel}>
                <select
                  value={channel}
                  onChange={(event) => setChannel(event.target.value as CommunicationChannel)}
                  className={inputClass}
                >
                  {(Object.keys(communicationChannelMeta) as CommunicationChannel[]).map(
                    (value) => (
                      <option key={value} value={value}>
                        {communicationChannelMeta[value].label}
                      </option>
                    ),
                  )}
                </select>
              </Field>

              <Field label="Direction" error={errors.direction}>
                <select
                  value={direction}
                  onChange={(event) => setDirection(event.target.value as CommunicationDirection)}
                  className={inputClass}
                >
                  <option value="outbound">Outbound</option>
                  <option value="inbound">Inbound</option>
                </select>
              </Field>

              <Field label="Status" error={errors.status}>
                <select
                  value={status}
                  onChange={(event) => {
                    setStatusTouched(true)
                    setStatus(event.target.value as CommunicationStatus)
                  }}
                  className={inputClass}
                >
                  {settableCommunicationStatuses(channel, direction).map((value) => (
                    <option key={value} value={value}>
                      {communicationStatusMeta[value].label}
                    </option>
                  ))}
                </select>
              </Field>

              <Field label="When" error={errors.occurred_at}>
                <input
                  type="datetime-local"
                  value={occurredAt}
                  onChange={(event) => setOccurredAt(event.target.value)}
                  className={inputClass}
                />
              </Field>

              {contacts.length > 0 && (
                <Field label="Contact" error={errors.contact_id}>
                  <select
                    value={contactId}
                    onChange={(event) => setContactId(event.target.value)}
                    className={inputClass}
                  >
                    <option value="">No contact</option>
                    {contacts.map((contact) => (
                      <option key={contact.id} value={contact.id}>
                        {contact.name}
                      </option>
                    ))}
                  </select>
                </Field>
              )}

              {conversational && (
                <>
                  <Field label="Duration (minutes)" error={errors.duration_minutes}>
                    <input
                      type="number"
                      min={0}
                      value={duration}
                      onChange={(event) => setDuration(event.target.value)}
                      className={inputClass}
                    />
                  </Field>

                  <Field label="Outcome" error={errors.outcome}>
                    <select
                      value={outcome}
                      onChange={(event) =>
                        setOutcome(event.target.value as CommunicationOutcome | '')
                      }
                      className={inputClass}
                    >
                      <option value="">Not recorded</option>
                      {communicationOutcomes.map((value) => (
                        <option key={value} value={value}>
                          {communicationOutcomeMeta[value].label}
                        </option>
                      ))}
                    </select>
                  </Field>
                </>
              )}

              <Field label="From name" error={errors.from_name}>
                <input
                  value={fromName}
                  onChange={(event) => setFromName(event.target.value)}
                  placeholder={direction === 'inbound' ? 'Who got in touch' : 'Who wrote'}
                  className={inputClass}
                />
              </Field>
              <Field label="From address" error={errors.from_address}>
                <input
                  value={fromAddress}
                  onChange={(event) => setFromAddress(event.target.value)}
                  placeholder="name@example.com"
                  className={inputClass}
                />
              </Field>
              <Field label="To name" error={errors.to_name}>
                <input
                  value={toName}
                  onChange={(event) => setToName(event.target.value)}
                  className={inputClass}
                />
              </Field>
              <Field label="To address" error={errors.to_address}>
                <input
                  value={toAddress}
                  onChange={(event) => setToAddress(event.target.value)}
                  placeholder="name@example.com"
                  className={inputClass}
                />
              </Field>
            </div>
          </FormCard>
        </div>

        <div className="mt-6">
          <FormCard title="What was said">
            <Field label="Subject" error={errors.subject}>
              <input
                value={title}
                onChange={(event) => setTitle(event.target.value)}
                placeholder="Revised rate sheet and crew schedule"
                className={inputClass}
              />
            </Field>

            <div className="mt-4">
              <Field label="Body" error={errors.body}>
                <textarea
                  value={body}
                  onChange={(event) => setBody(event.target.value)}
                  rows={6}
                  className={inputClass}
                />
              </Field>
            </div>
          </FormCard>
        </div>

        <div className="mt-6 flex items-center gap-3">
          <button
            type="submit"
            disabled={saving}
            className="rounded-md bg-primary px-4 py-2 text-sm font-medium text-primary-foreground disabled:opacity-60"
          >
            {saving ? 'Saving…' : isDraft ? 'Save draft' : 'Log communication'}
          </button>
          <button
            type="button"
            onClick={() => navigate(-1)}
            className="rounded-md border border-border bg-card px-4 py-2 text-sm font-medium text-foreground hover:bg-muted"
          >
            Cancel
          </button>
        </div>
      </form>
    </div>
  )
}

const loadRecord = async (
  type: CommunicationSubjectType,
  id: string,
  fallbackLabel: string,
): Promise<RecordOption | null> => {
  switch (type) {
    case 'lead': {
      const lead = await getLead(id)
      return { id: lead.id, label: `${lead.company_name} (${lead.reference})`, companyId: null }
    }
    case 'company': {
      const company = await getCompany(id)
      return { id: company.id, label: company.name, companyId: company.id }
    }
    case 'contact': {
      const contact = await getContact(id)
      return { id: contact.id, label: contact.name, companyId: contact.company_id }
    }
    case 'opportunity': {
      const opportunity = await getOpportunity(id)
      return {
        id: opportunity.id,
        label: opportunity.name,
        companyId: opportunity.company?.id ?? null,
      }
    }
    case 'contract':
      return { id, label: fallbackLabel || id, companyId: null }
  }
}

const searchRecords = async (
  type: CommunicationSubjectType,
  query: string,
): Promise<RecordOption[]> => {
  const search = query.trim() || undefined

  switch (type) {
    case 'lead': {
      const response = await listLeads({ search })
      return response.data.map((lead) => ({
        id: lead.id,
        label: `${lead.company_name} (${lead.reference})`,
        companyId: null,
      }))
    }
    case 'company': {
      const response = await listCompanies({ search })
      return response.data.map((company) => ({
        id: company.id,
        label: company.name,
        companyId: company.id,
      }))
    }
    case 'contact': {
      const response = await listContacts({ search })
      return response.data.map((contact) => ({
        id: contact.id,
        label: `${contact.name}${contact.company ? ` — ${contact.company.name}` : ''}`,
        companyId: contact.company_id,
      }))
    }
    case 'opportunity': {
      const response = await listOpportunities({ search })
      return response.data.map((opportunity) => ({
        id: opportunity.id,
        label: `${opportunity.name}${opportunity.company ? ` — ${opportunity.company.name}` : ''}`,
        companyId: opportunity.company?.id ?? null,
      }))
    }
    case 'contract':
      return []
  }
}

interface FormState {
  subjectType: CommunicationSubjectType
  selected: RecordOption
  channel: CommunicationChannel
  direction: CommunicationDirection
  status: CommunicationStatus
  contactId: string
  occurredAt: string
  duration: string
  outcome: CommunicationOutcome | ''
  fromName: string
  fromAddress: string
  toName: string
  toAddress: string
  title: string
  body: string
}

function toInput(state: FormState): CommunicationInput {
  const trimmed = (value: string) => (value.trim() === '' ? null : value.trim())

  return {
    subject_type: state.subjectType,
    subject_id: state.selected.id,
    channel: state.channel,
    direction: state.direction,
    status: state.status,
    contact_id: trimmed(state.contactId),
    subject: state.title.trim(),
    body: trimmed(state.body),
    from_name: trimmed(state.fromName),
    from_address: trimmed(state.fromAddress),
    to_name: trimmed(state.toName),
    to_address: trimmed(state.toAddress),
    occurred_at: state.occurredAt ? new Date(state.occurredAt).toISOString() : null,
    duration_minutes:
      state.duration.trim() === '' || !isConversationalChannel(state.channel)
        ? null
        : Number(state.duration),
    outcome: state.outcome === '' ? null : state.outcome,
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
