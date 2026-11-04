import { useState, type FormEvent, type ReactNode } from 'react'
import { isAxiosError } from 'axios'
import { CheckCircle2 } from 'lucide-react'
import AlertItem from '../common/AlertItem'
import { parseValidationError } from '../../lib/leads'
import { submitPublicLead, type PublicLeadInput } from '../../lib/publicLeads'

interface FormState {
  company_name: string
  contact_name: string
  contact_email: string
  contact_phone: string
  industry: string
  service_interest: string
  estimated_headcount: string
  needed_roles: string
  target_start_date: string
  message: string
  consent: boolean
  website: string
}

const emptyForm: FormState = {
  company_name: '',
  contact_name: '',
  contact_email: '',
  contact_phone: '',
  industry: '',
  service_interest: '',
  estimated_headcount: '',
  needed_roles: '',
  target_start_date: '',
  message: '',
  consent: false,
  website: '',
}

type FieldErrors = Record<string, string[]>

const inputClass =
  'mt-1.5 w-full rounded-md border border-input bg-background px-3 py-2 text-sm text-foreground outline-none placeholder:text-muted-foreground focus:ring-2 focus:ring-ring'

/**
 * The embedded request-staffing form (spec 16 §Public intake, S17).
 *
 * It speaks only to the narrow public endpoint: no account picker, no owner, no status. The
 * server returns a friendly message with no internal ids, and its validation errors map back
 * onto the fields the visitor can fix.
 */
export default function PublicLeadForm() {
  const [form, setForm] = useState<FormState>(emptyForm)
  const [errors, setErrors] = useState<FieldErrors>({})
  const [message, setMessage] = useState('')
  const [successMessage, setSuccessMessage] = useState('')
  const [submitting, setSubmitting] = useState(false)

  const update = <K extends keyof FormState>(field: K, value: FormState[K]) => {
    setForm((current) => ({ ...current, [field]: value }))
    setErrors((current) => {
      if (!current[field]) return current
      const next = { ...current }
      delete next[field]
      return next
    })
  }

  const handleSubmit = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault()
    setErrors({})
    setMessage('')
    setSubmitting(true)

    const headcount = form.estimated_headcount.trim()
    const payload: PublicLeadInput = {
      company_name: form.company_name.trim(),
      contact_name: form.contact_name.trim(),
      contact_email: form.contact_email.trim() || undefined,
      contact_phone: form.contact_phone.trim() || undefined,
      industry: form.industry.trim() || undefined,
      service_interest: form.service_interest.trim() || undefined,
      estimated_headcount: headcount ? Number(headcount) : undefined,
      needed_roles: form.needed_roles.trim() || undefined,
      target_start_date: form.target_start_date || undefined,
      message: form.message.trim() || undefined,
      consent: form.consent,
      website: form.website,
    }

    try {
      const response = await submitPublicLead(payload)
      setSuccessMessage(response.message)
    } catch (error) {
      const validation = parseValidationError(error)
      if (validation) {
        setErrors(validation.errors)
        setMessage(validation.message)
        return
      }

      if (isAxiosError(error) && error.response?.status === 429) {
        setMessage('Too many requests from this browser. Please wait a minute and try again.')
        return
      }

      setMessage(
        'We could not send your request just now. Please try again, or email hello@primepower.com.ph.',
      )
    } finally {
      setSubmitting(false)
    }
  }

  if (successMessage) {
    return (
      <div
        role="status"
        aria-live="polite"
        className="rounded-xl border border-people/40 bg-people/10 p-6"
      >
        <CheckCircle2 className="h-8 w-8 text-people" aria-hidden="true" />
        <h3 className="mt-3 text-base font-semibold text-foreground">Request received</h3>
        <p className="mt-2 text-sm text-muted-foreground">{successMessage}</p>
        <p className="mt-2 text-sm text-muted-foreground">
          If you need to add anything, reply to the confirmation email or call us on{' '}
          <a href="tel:+63288881000" className="font-medium text-foreground underline">
            +63 2 8888 1000
          </a>
          .
        </p>
      </div>
    )
  }

  return (
    <form noValidate onSubmit={handleSubmit} className="flex flex-col gap-4">
      <div className="hidden" aria-hidden="true">
        <label htmlFor="public-lead-website">Website</label>
        <input
          id="public-lead-website"
          name="website"
          tabIndex={-1}
          autoComplete="off"
          value={form.website}
          onChange={(event) => update('website', event.target.value)}
        />
      </div>

      {message && (
        <AlertItem
          variant="danger"
          title={Object.keys(errors).length > 0 ? 'Please check the form' : 'Request not sent'}
        >
          {message}
        </AlertItem>
      )}

      <div className="grid gap-4 sm:grid-cols-2">
        <Field
          id="public-lead-company-name"
          label="Company name"
          required
          error={errors.company_name?.[0]}
        >
          <input
            id="public-lead-company-name"
            value={form.company_name}
            onChange={(event) => update('company_name', event.target.value)}
            autoComplete="organization"
            required
            className={inputClass}
            aria-invalid={Boolean(errors.company_name)}
            aria-describedby={errors.company_name ? 'public-lead-company-name-error' : undefined}
          />
        </Field>

        <Field
          id="public-lead-contact-name"
          label="Your name"
          required
          error={errors.contact_name?.[0]}
        >
          <input
            id="public-lead-contact-name"
            value={form.contact_name}
            onChange={(event) => update('contact_name', event.target.value)}
            autoComplete="name"
            required
            className={inputClass}
            aria-invalid={Boolean(errors.contact_name)}
            aria-describedby={errors.contact_name ? 'public-lead-contact-name-error' : undefined}
          />
        </Field>

        <Field
          id="public-lead-contact-email"
          label="Email"
          hint="Email or phone — whichever you prefer."
          error={errors.contact_email?.[0]}
        >
          <input
            id="public-lead-contact-email"
            type="email"
            value={form.contact_email}
            onChange={(event) => update('contact_email', event.target.value)}
            autoComplete="email"
            className={inputClass}
            aria-invalid={Boolean(errors.contact_email)}
            aria-describedby={
              errors.contact_email
                ? 'public-lead-contact-email-error'
                : 'public-lead-contact-email-hint'
            }
          />
        </Field>

        <Field
          id="public-lead-contact-phone"
          label="Phone"
          hint="We use this only to discuss your request."
          error={errors.contact_phone?.[0]}
        >
          <input
            id="public-lead-contact-phone"
            type="tel"
            value={form.contact_phone}
            onChange={(event) => update('contact_phone', event.target.value)}
            autoComplete="tel"
            className={inputClass}
            aria-invalid={Boolean(errors.contact_phone)}
            aria-describedby={
              errors.contact_phone
                ? 'public-lead-contact-phone-error'
                : 'public-lead-contact-phone-hint'
            }
          />
        </Field>

        <Field id="public-lead-industry" label="Industry" error={errors.industry?.[0]}>
          <input
            id="public-lead-industry"
            value={form.industry}
            onChange={(event) => update('industry', event.target.value)}
            className={inputClass}
            aria-invalid={Boolean(errors.industry)}
            aria-describedby={errors.industry ? 'public-lead-industry-error' : undefined}
          />
        </Field>

        <Field
          id="public-lead-service-interest"
          label="Service needed"
          error={errors.service_interest?.[0]}
        >
          <input
            id="public-lead-service-interest"
            value={form.service_interest}
            onChange={(event) => update('service_interest', event.target.value)}
            placeholder="Warehouse staffing, housekeeping…"
            className={inputClass}
            aria-invalid={Boolean(errors.service_interest)}
            aria-describedby={
              errors.service_interest ? 'public-lead-service-interest-error' : undefined
            }
          />
        </Field>

        <Field
          id="public-lead-headcount"
          label="People needed"
          error={errors.estimated_headcount?.[0]}
        >
          <input
            id="public-lead-headcount"
            type="number"
            min={1}
            inputMode="numeric"
            value={form.estimated_headcount}
            onChange={(event) => update('estimated_headcount', event.target.value)}
            className={inputClass}
            aria-invalid={Boolean(errors.estimated_headcount)}
            aria-describedby={
              errors.estimated_headcount ? 'public-lead-headcount-error' : undefined
            }
          />
        </Field>

        <Field
          id="public-lead-start-date"
          label="Target start date"
          error={errors.target_start_date?.[0]}
        >
          <input
            id="public-lead-start-date"
            type="date"
            value={form.target_start_date}
            onChange={(event) => update('target_start_date', event.target.value)}
            className={inputClass}
            aria-invalid={Boolean(errors.target_start_date)}
            aria-describedby={errors.target_start_date ? 'public-lead-start-date-error' : undefined}
          />
        </Field>

        <Field
          id="public-lead-roles"
          label="Roles needed"
          error={errors.needed_roles?.[0]}
          className="sm:col-span-2"
        >
          <textarea
            id="public-lead-roles"
            value={form.needed_roles}
            onChange={(event) => update('needed_roles', event.target.value)}
            rows={2}
            placeholder="Warehouse associates, inventory lead"
            className={inputClass}
            aria-invalid={Boolean(errors.needed_roles)}
            aria-describedby={errors.needed_roles ? 'public-lead-roles-error' : undefined}
          />
        </Field>

        <Field
          id="public-lead-message"
          label="Anything else we should know?"
          error={errors.message?.[0]}
          className="sm:col-span-2"
        >
          <textarea
            id="public-lead-message"
            value={form.message}
            onChange={(event) => update('message', event.target.value)}
            rows={3}
            className={inputClass}
            aria-invalid={Boolean(errors.message)}
            aria-describedby={errors.message ? 'public-lead-message-error' : undefined}
          />
        </Field>
      </div>

      <div>
        <label className="flex items-start gap-2 text-sm text-muted-foreground">
          <input
            type="checkbox"
            checked={form.consent}
            onChange={(event) => update('consent', event.target.checked)}
            required
            className="mt-0.5 h-4 w-4 rounded border-input"
            aria-invalid={Boolean(errors.consent)}
            aria-describedby={errors.consent ? 'public-lead-consent-error' : undefined}
          />
          <span>
            I agree to be contacted about this request. My details are used only to respond to it.
          </span>
        </label>
        {errors.consent?.[0] && (
          <p id="public-lead-consent-error" className="mt-1 text-xs text-destructive">
            {errors.consent[0]}
          </p>
        )}
      </div>

      <div className="flex flex-wrap items-center gap-3">
        <button
          type="submit"
          disabled={submitting}
          className="inline-flex items-center gap-2 rounded-md bg-primary px-4 py-2.5 text-sm font-medium text-primary-foreground hover:bg-primary/90 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-card disabled:opacity-60"
        >
          {submitting ? 'Sending…' : 'Send request'}
        </button>
        <p className="text-xs text-muted-foreground">We reply within one business day.</p>
      </div>
    </form>
  )
}

function Field({
  id,
  label,
  error,
  hint,
  required,
  className,
  children,
}: {
  id: string
  label: string
  error?: string
  hint?: string
  required?: boolean
  className?: string
  children: ReactNode
}) {
  return (
    <div className={className}>
      <label htmlFor={id} className="block text-sm font-medium text-foreground">
        {label}
        {required && (
          <span className="text-destructive" aria-hidden="true">
            {' '}
            *
          </span>
        )}
      </label>
      {hint && (
        <p id={`${id}-hint`} className="mt-1 text-xs text-muted-foreground">
          {hint}
        </p>
      )}
      {children}
      {error && (
        <p id={`${id}-error`} className="mt-1 text-xs text-destructive">
          {error}
        </p>
      )}
    </div>
  )
}
