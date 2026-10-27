import { useState, type FormEvent, type ReactNode } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import PageHeader from '../components/common/PageHeader'
import AlertItem from '../components/common/AlertItem'
import { createLead, type NewLeadInput } from '../lib/leads'
import type { Lead } from '../types'

interface FieldErrors {
  [field: string]: string[]
}

const emptyForm = {
  company_name: '',
  contact_name: '',
  contact_email: '',
  contact_phone: '',
  contact_position: '',
  source: 'walk_in',
  source_detail: '',
  industry: '',
  location: '',
  service_interest: '',
  estimated_headcount: '',
  needed_roles: '',
  target_start_date: '',
  urgency: '',
  notes: '',
}

type FormState = typeof emptyForm

export default function NewLead() {
  const navigate = useNavigate()
  const [form, setForm] = useState<FormState>(emptyForm)
  const [errors, setErrors] = useState<FieldErrors>({})
  const [message, setMessage] = useState('')
  const [duplicates, setDuplicates] = useState<
    { id: string; reference: string; company_name: string }[]
  >([])
  const [createdLead, setCreatedLead] = useState<Lead | null>(null)
  const [saving, setSaving] = useState(false)

  const update = (field: keyof FormState, value: string) =>
    setForm((previous) => ({ ...previous, [field]: value }))

  const onSubmit = async (event: FormEvent) => {
    event.preventDefault()
    setSaving(true)
    setErrors({})
    setMessage('')
    setDuplicates([])
    setCreatedLead(null)

    try {
      const response = await createLead(toPayload(form))

      if (response.duplicates.length > 0) {
        setCreatedLead(response.data)
        setDuplicates(response.duplicates)
        return
      }

      navigate('/leads')
    } catch (error) {
      const status = (
        error as {
          response?: { status?: number; data?: { message?: string; errors?: FieldErrors } }
        }
      ).response
      if (status?.status === 422 && status.data?.errors) {
        setErrors(status.data.errors)
        setMessage(status.data.message ?? 'Please correct the highlighted fields.')
      } else {
        setMessage('Could not save the lead. Please try again.')
      }
    } finally {
      setSaving(false)
    }
  }

  return (
    <div>
      <PageHeader
        title="New lead"
        description="Capture an inbound enquiry. Minimum: company, contact name and one contact channel."
      />

      {message && (
        <div className="mb-4">
          <AlertItem variant="danger">{message}</AlertItem>
        </div>
      )}

      {duplicates.length > 0 && (
        <div className="mb-4">
          <AlertItem variant="warning" title="Lead saved — possible duplicates found">
            {createdLead && (
              <p>
                Saved as{' '}
                <Link
                  to={`/leads/${createdLead.id}`}
                  className="font-medium text-primary hover:underline"
                >
                  {createdLead.reference}
                </Link>
                . Check whether it belongs with an existing lead:
              </p>
            )}
            <ul className="mt-2 list-inside list-disc">
              {duplicates.map((duplicate) => (
                <li key={duplicate.id}>
                  <Link
                    to={`/leads/${duplicate.id}`}
                    className="font-medium text-primary hover:underline"
                  >
                    {duplicate.reference}
                  </Link>{' '}
                  — {duplicate.company_name}
                </li>
              ))}
            </ul>
            <Link to="/leads" className="mt-2 inline-block font-medium text-primary">
              Back to leads
            </Link>
          </AlertItem>
        </div>
      )}

      <form onSubmit={onSubmit} className="grid max-w-3xl grid-cols-1 gap-4 md:grid-cols-2">
        <Field label="Company name" error={errors.company_name} required>
          <input
            value={form.company_name}
            onChange={(event) => update('company_name', event.target.value)}
            required
            className={inputClass}
          />
        </Field>

        <Field label="Contact name" error={errors.contact_name} required>
          <input
            value={form.contact_name}
            onChange={(event) => update('contact_name', event.target.value)}
            required
            className={inputClass}
          />
        </Field>

        <Field label="Contact email" error={errors.contact_email}>
          <input
            type="email"
            value={form.contact_email}
            onChange={(event) => update('contact_email', event.target.value)}
            className={inputClass}
          />
        </Field>

        <Field label="Contact phone" error={errors.contact_phone}>
          <input
            value={form.contact_phone}
            onChange={(event) => update('contact_phone', event.target.value)}
            placeholder="09XX XXX XXXX"
            className={inputClass}
          />
        </Field>

        <Field label="Contact position" error={errors.contact_position}>
          <input
            value={form.contact_position}
            onChange={(event) => update('contact_position', event.target.value)}
            className={inputClass}
          />
        </Field>

        <Field label="Source" error={errors.source} required>
          <select
            value={form.source}
            onChange={(event) => update('source', event.target.value)}
            className={inputClass}
          >
            <option value="walk_in">Walk-in</option>
            <option value="email">Email</option>
            <option value="phone">Phone</option>
            <option value="referral">Referral</option>
            <option value="website">Website</option>
            <option value="outbound">Outbound</option>
            <option value="event">Event</option>
            <option value="partner">Partner</option>
            <option value="other">Other</option>
          </select>
        </Field>

        {form.source === 'other' && (
          <Field label="Source detail" error={errors.source_detail} required>
            <input
              value={form.source_detail}
              onChange={(event) => update('source_detail', event.target.value)}
              className={inputClass}
            />
          </Field>
        )}

        <Field label="Industry" error={errors.industry}>
          <input
            value={form.industry}
            onChange={(event) => update('industry', event.target.value)}
            className={inputClass}
          />
        </Field>

        <Field label="Location" error={errors.location}>
          <input
            value={form.location}
            onChange={(event) => update('location', event.target.value)}
            className={inputClass}
          />
        </Field>

        <Field label="Service interest" error={errors.service_interest}>
          <input
            value={form.service_interest}
            onChange={(event) => update('service_interest', event.target.value)}
            className={inputClass}
          />
        </Field>

        <Field label="Estimated headcount" error={errors.estimated_headcount}>
          <input
            type="number"
            min={1}
            value={form.estimated_headcount}
            onChange={(event) => update('estimated_headcount', event.target.value)}
            className={inputClass}
          />
        </Field>

        <Field label="Target start date" error={errors.target_start_date}>
          <input
            type="date"
            value={form.target_start_date}
            onChange={(event) => update('target_start_date', event.target.value)}
            className={inputClass}
          />
        </Field>

        <Field label="Urgency" error={errors.urgency}>
          <select
            value={form.urgency}
            onChange={(event) => update('urgency', event.target.value)}
            className={inputClass}
          >
            <option value="">Not set</option>
            <option value="low">Low</option>
            <option value="medium">Medium</option>
            <option value="high">High</option>
          </select>
        </Field>

        <Field label="Needed roles" error={errors.needed_roles} className="md:col-span-2">
          <textarea
            value={form.needed_roles}
            onChange={(event) => update('needed_roles', event.target.value)}
            rows={2}
            className={inputClass}
          />
        </Field>

        <Field label="Notes" error={errors.notes} className="md:col-span-2">
          <textarea
            value={form.notes}
            onChange={(event) => update('notes', event.target.value)}
            rows={3}
            className={inputClass}
          />
        </Field>

        <div className="flex items-center gap-3 md:col-span-2">
          <button
            type="submit"
            disabled={saving}
            className="rounded-md bg-primary px-4 py-2 text-sm font-medium text-primary-foreground disabled:opacity-60"
          >
            {saving ? 'Saving…' : 'Save lead'}
          </button>
          <Link
            to="/leads"
            className="text-sm font-medium text-muted-foreground hover:text-foreground"
          >
            Cancel
          </Link>
        </div>
      </form>
    </div>
  )
}

const inputClass =
  'mt-1 w-full rounded-md border border-input bg-card px-3 py-2 text-sm text-foreground outline-none focus:ring-2 focus:ring-ring'

function Field({
  label,
  error,
  required,
  className,
  children,
}: {
  label: string
  error?: string[]
  required?: boolean
  className?: string
  children: ReactNode
}) {
  return (
    <label className={`block text-sm font-medium text-foreground ${className ?? ''}`}>
      {label}
      {required && <span className="text-destructive"> *</span>}
      {children}
      {error && <span className="mt-1 block text-xs text-destructive">{error[0]}</span>}
    </label>
  )
}

function toPayload(form: FormState): NewLeadInput {
  const trimmed = (value: string) => {
    const result = value.trim()
    return result === '' ? undefined : result
  }

  return {
    company_name: form.company_name.trim(),
    contact_name: form.contact_name.trim(),
    contact_email: trimmed(form.contact_email),
    contact_phone: trimmed(form.contact_phone),
    contact_position: trimmed(form.contact_position),
    source: form.source,
    source_detail: trimmed(form.source_detail),
    industry: trimmed(form.industry),
    location: trimmed(form.location),
    service_interest: trimmed(form.service_interest),
    estimated_headcount: form.estimated_headcount ? Number(form.estimated_headcount) : undefined,
    needed_roles: trimmed(form.needed_roles),
    target_start_date: trimmed(form.target_start_date),
    urgency: trimmed(form.urgency),
    notes: trimmed(form.notes),
  }
}
