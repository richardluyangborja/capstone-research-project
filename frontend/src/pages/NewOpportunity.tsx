import { useEffect, useState, type FormEvent, type ReactNode } from 'react'
import { useNavigate, useSearchParams } from 'react-router-dom'
import PageHeader from '../components/common/PageHeader'
import FormCard from '../components/common/FormCard'
import AlertItem from '../components/common/AlertItem'
import { createOpportunity, type CreateOpportunityInput } from '../lib/opportunities'
import { listCompanies } from '../lib/companies'
import { listCompanyContacts } from '../lib/contacts'
import { getAssignableUsers, parseValidationError } from '../lib/leads'
import { useAuth } from '../lib/auth-context'
import type { AssignmentTarget, Company, Contact } from '../types'

interface DealForm {
  company_id: string
  name: string
  expected_close_date: string
  next_step: string
  probability: string
  amount: string
  primary_contact_id: string
  owner_id: string
}

const emptyForm: DealForm = {
  company_id: '',
  name: '',
  expected_close_date: '',
  next_step: '',
  probability: '',
  amount: '',
  primary_contact_id: '',
  owner_id: '',
}

// Module 02 §2 — a deal can be opened directly on an account (not only by conversion).
// Amount and owner are manager+ (ADR-0012 §4, ADR-0015 §5).
export default function NewOpportunity() {
  const navigate = useNavigate()
  const [params] = useSearchParams()
  const { user } = useAuth()
  const isManagerOrAbove = user?.role !== 'salesrep'

  const [form, setForm] = useState<DealForm>({
    ...emptyForm,
    company_id: params.get('company_id') ?? '',
  })
  const [companies, setCompanies] = useState<Company[]>([])
  const [contacts, setContacts] = useState<Contact[]>([])
  const [targets, setTargets] = useState<AssignmentTarget[]>([])
  const [saving, setSaving] = useState(false)
  const [message, setMessage] = useState('')
  const [errors, setErrors] = useState<Record<string, string[]>>({})

  useEffect(() => {
    void (async () => {
      try {
        const [response, assignable] = await Promise.all([
          listCompanies({ per_page: 100 }),
          isManagerOrAbove ? getAssignableUsers() : Promise.resolve([]),
        ])
        setCompanies(response.data)
        setTargets(assignable)
      } catch {
        // Non-critical: the pickers simply stay empty.
      }
    })()
  }, [isManagerOrAbove])

  useEffect(() => {
    setForm((previous) => ({ ...previous, primary_contact_id: '' }))
    if (form.company_id === '') {
      setContacts([])
      return
    }
    void (async () => {
      try {
        setContacts(await listCompanyContacts(form.company_id))
      } catch {
        setContacts([])
      }
    })()
  }, [form.company_id])

  const update = <K extends keyof DealForm>(field: K, value: DealForm[K]) =>
    setForm((previous) => ({ ...previous, [field]: value }))

  const onSubmit = async (event: FormEvent) => {
    event.preventDefault()
    setSaving(true)
    setMessage('')
    setErrors({})

    try {
      const deal = await createOpportunity(toInput(form, isManagerOrAbove))
      navigate(`/opportunities/${deal.id}`)
    } catch (error) {
      const validation = parseValidationError(error)
      if (validation) {
        setErrors(validation.errors)
        setMessage(validation.message)
      } else {
        setMessage('Could not create the deal.')
      }
    } finally {
      setSaving(false)
    }
  }

  return (
    <div>
      <PageHeader
        title="New deal"
        description="Open an opportunity on an account — a new engagement or an upsell."
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
        <FormCard title="Deal">
          <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
            <Field label="Account" error={errors.company_id} className="md:col-span-2">
              <select
                value={form.company_id}
                onChange={(event) => update('company_id', event.target.value)}
                className={inputClass}
              >
                <option value="">Select an account…</option>
                {companies.map((company) => (
                  <option key={company.id} value={company.id}>
                    {company.name} ({company.reference})
                  </option>
                ))}
              </select>
            </Field>

            <Field label="Deal name" error={errors.name} className="md:col-span-2">
              <input
                value={form.name}
                onChange={(event) => update('name', event.target.value)}
                placeholder="Harbor Logistics — second-shift operators"
                className={inputClass}
              />
            </Field>

            <Field label="Expected close" error={errors.expected_close_date}>
              <input
                type="date"
                value={form.expected_close_date}
                onChange={(event) => update('expected_close_date', event.target.value)}
                className={inputClass}
              />
            </Field>

            <Field label="Probability (%)" error={errors.probability}>
              <input
                type="number"
                min={0}
                max={100}
                value={form.probability}
                onChange={(event) => update('probability', event.target.value)}
                placeholder="Stage default"
                className={inputClass}
              />
            </Field>

            {isManagerOrAbove && (
              <Field label="Value (₱)" error={errors.amount}>
                <input
                  type="number"
                  min={0}
                  value={form.amount}
                  onChange={(event) => update('amount', event.target.value)}
                  className={inputClass}
                />
              </Field>
            )}

            <Field label="Primary contact" error={errors.primary_contact_id}>
              <select
                value={form.primary_contact_id}
                onChange={(event) => update('primary_contact_id', event.target.value)}
                disabled={form.company_id === '' || contacts.length === 0}
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
                onChange={(event) => update('next_step', event.target.value)}
                placeholder="Send the revised rate card"
                className={inputClass}
              />
            </Field>

            {isManagerOrAbove && (
              <Field label="Owner" error={errors.owner_id}>
                <select
                  value={form.owner_id}
                  onChange={(event) => update('owner_id', event.target.value)}
                  className={inputClass}
                >
                  <option value="">Me</option>
                  {targets.map((target) => (
                    <option key={target.id} value={target.id}>
                      {target.name}
                    </option>
                  ))}
                </select>
              </Field>
            )}
          </div>

          <div className="mt-4">
            <button
              type="submit"
              disabled={saving || form.company_id === '' || form.name.trim() === ''}
              className="rounded-md bg-primary px-4 py-2 text-sm font-medium text-primary-foreground disabled:opacity-60"
            >
              {saving ? 'Creating…' : 'Create deal'}
            </button>
          </div>
        </FormCard>
      </form>
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

function toInput(form: DealForm, includeCommercial: boolean): CreateOpportunityInput {
  const trimmed = (value: string) => (value.trim() === '' ? null : value.trim())

  const input: CreateOpportunityInput = {
    company_id: form.company_id,
    name: form.name.trim(),
    expected_close_date: trimmed(form.expected_close_date),
    next_step: trimmed(form.next_step),
    probability: form.probability === '' ? null : Number(form.probability),
    primary_contact_id: trimmed(form.primary_contact_id),
  }

  if (includeCommercial) {
    input.amount = form.amount === '' ? null : Number(form.amount)
    input.owner_id = form.owner_id === '' ? null : Number(form.owner_id)
  }

  return input
}
