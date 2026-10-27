import { useCallback, useEffect, useState, type FormEvent, type ReactNode } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'
import { ArrowLeft, Plus, Trash2 } from 'lucide-react'
import PageHeader from '../components/common/PageHeader'
import FormCard from '../components/common/FormCard'
import StatusBadge from '../components/common/StatusBadge'
import AlertItem from '../components/common/AlertItem'
import { listCompanies } from '../lib/companies'
import { convertLead, getConversionPreview, parseValidationError } from '../lib/leads'
import { companyStatusMeta, companySizeLabels } from '../lib/companyMeta'
import { contactRoleMeta } from '../lib/contactMeta'
import { opportunityStageMeta } from '../lib/opportunityMeta'
import { formatCurrency } from '../lib/format'
import type {
  Company,
  CompanySize,
  ContactRoleType,
  ConversionContactInput,
  ConversionPreview,
  ConversionResult,
} from '../types'

interface CompanyForm {
  name: string
  trade_name: string
  industry: string
  size: string
  website: string
  description: string
}

interface OpportunityForm {
  name: string
  amount: string
  expected_close_date: string
}

type LinkMode = 'new' | 'existing'

const steps = ['Company', 'Contacts', 'Opportunity'] as const

/**
 * Lead → client conversion wizard (spec 08 §UI): company + contact(s) + opportunity in one
 * atomic call. Duplicate companies are flagged, never blocked.
 */
export default function LeadConvert() {
  const { id = '' } = useParams()
  const navigate = useNavigate()

  const [preview, setPreview] = useState<ConversionPreview | null>(null)
  const [mode, setMode] = useState<LinkMode>('new')
  const [company, setCompany] = useState<CompanyForm>({
    name: '',
    trade_name: '',
    industry: '',
    size: '',
    website: '',
    description: '',
  })
  const [linkCompanyId, setLinkCompanyId] = useState('')
  const [companySearch, setCompanySearch] = useState('')
  const [options, setOptions] = useState<Company[]>([])
  const [contacts, setContacts] = useState<ConversionContactInput[]>([])
  const [opportunity, setOpportunity] = useState<OpportunityForm>({
    name: '',
    amount: '',
    expected_close_date: '',
  })

  const [step, setStep] = useState(0)
  const [canSetAmount, setCanSetAmount] = useState(false)
  const [loading, setLoading] = useState(true)
  const [saving, setSaving] = useState(false)
  const [message, setMessage] = useState('')
  const [errors, setErrors] = useState<Record<string, string[]>>({})

  const load = useCallback(async () => {
    setLoading(true)
    setMessage('')
    try {
      const data = await getConversionPreview(id)

      setPreview(data)
      setCanSetAmount(data.can_set_amount)
      setCompany({
        name: data.company.name,
        trade_name: data.company.trade_name ?? '',
        industry: data.company.industry ?? '',
        size: data.company.size ?? '',
        website: data.company.website ?? '',
        description: data.company.description ?? '',
      })
      setContacts(data.contacts)
      setOpportunity({
        name: data.opportunity.name,
        amount: data.opportunity.amount ?? '',
        expected_close_date: data.opportunity.expected_close_date ?? '',
      })
      setLinkCompanyId('')
    } catch {
      setPreview(null)
      setMessage('Could not load this lead for conversion.')
    } finally {
      setLoading(false)
    }
  }, [id])

  useEffect(() => {
    void load()
  }, [load])

  // Step 1 "link an existing account" needs the book the caller can actually see.
  useEffect(() => {
    if (mode !== 'existing') return

    let active = true

    void (async () => {
      try {
        const response = await listCompanies({ search: companySearch || undefined })
        if (active) setOptions(response.data)
      } catch {
        if (active) setOptions([])
      }
    })()

    return () => {
      active = false
    }
  }, [mode, companySearch])

  const onSubmit = async (event: FormEvent) => {
    event.preventDefault()
    setSaving(true)
    setMessage('')
    setErrors({})

    try {
      const result: ConversionResult = await convertLead(id, {
        ...(mode === 'existing'
          ? { link_company_id: linkCompanyId }
          : {
              company: {
                name: company.name,
                trade_name: company.trade_name || null,
                industry: company.industry || null,
                size: company.size || null,
                website: company.website || null,
                description: company.description || null,
              },
            }),
        contacts,
        opportunity: {
          name: opportunity.name,
          // The amount field is only rendered (and only accepted) for manager+; a
          // salesrep converts without a value.
          ...(canSetAmount && opportunity.amount !== ''
            ? { amount: Number(opportunity.amount) }
            : {}),
          expected_close_date: opportunity.expected_close_date || null,
        },
      })

      navigate(`/accounts/${result.company.id}`, {
        state: {
          notice: `${result.lead.reference} converted into ${result.company.reference}.`,
          duplicates: result.duplicates,
        },
      })
    } catch (error) {
      const validation = parseValidationError(error)
      if (validation) {
        setErrors(validation.errors)
        setMessage(validation.message)
      } else {
        setMessage('The lead could not be converted.')
      }
    } finally {
      setSaving(false)
    }
  }

  if (loading) {
    return <p className="text-sm text-muted-foreground">Loading…</p>
  }

  if (!preview) {
    return (
      <div>
        <AlertItem variant="danger">{message || 'Lead not found.'}</AlertItem>
        <Link to="/leads" className="mt-4 inline-block text-sm font-medium text-primary">
          Back to leads
        </Link>
      </div>
    )
  }

  if (!preview.can_convert) {
    return (
      <div>
        <AlertItem variant="danger">
          {preview.blocked_reason ?? 'This lead cannot be converted.'}
        </AlertItem>
        <Link to={`/leads/${id}`} className="mt-4 inline-block text-sm font-medium text-primary">
          Back to the lead
        </Link>
      </div>
    )
  }

  const updateContact = (index: number, patch: Partial<ConversionContactInput>) => {
    setContacts((rows) => rows.map((row, i) => (i === index ? { ...row, ...patch } : row)))
  }

  const setPrimary = (index: number) => {
    setContacts((rows) => rows.map((row, i) => ({ ...row, is_primary: i === index })))
  }

  return (
    <div>
      <Link
        to={`/leads/${id}`}
        className="mb-3 inline-flex items-center gap-2 text-sm text-muted-foreground hover:text-foreground"
      >
        <ArrowLeft className="h-4 w-4" aria-hidden="true" />
        Back to the lead
      </Link>

      <PageHeader
        title="Convert to client"
        description={`${preview.lead.reference} · ${preview.lead.company_name}`}
      />

      <ol className="mb-4 flex flex-wrap items-center gap-2 text-sm">
        {steps.map((label, index) => (
          <li key={label}>
            <button
              type="button"
              onClick={() => setStep(index)}
              className={`inline-flex items-center gap-2 rounded-md px-3 py-1.5 font-medium transition-colors ${
                index === step
                  ? 'bg-secondary text-primary'
                  : 'text-muted-foreground hover:bg-muted'
              }`}
            >
              <span className="font-mono text-xs">{index + 1}</span>
              {label}
            </button>
          </li>
        ))}
      </ol>

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

      <form onSubmit={onSubmit} className="flex flex-col gap-6">
        {step === 0 && (
          <FormCard
            title="1 · Company"
            description="A converted lead opens a prospect account — it becomes a client on contract sign-off."
          >
            <div className="flex flex-col gap-4">
              <div className="flex flex-wrap gap-4">
                <label className="flex items-center gap-2 text-sm text-foreground">
                  <input
                    type="radio"
                    name="mode"
                    checked={mode === 'new'}
                    onChange={() => setMode('new')}
                  />
                  Create a new company
                </label>
                <label className="flex items-center gap-2 text-sm text-foreground">
                  <input
                    type="radio"
                    name="mode"
                    checked={mode === 'existing'}
                    onChange={() => setMode('existing')}
                  />
                  Link to an existing account
                </label>
              </div>

              {preview.duplicates.length > 0 && (
                <AlertItem variant="warning">
                  <p className="font-medium">Possible existing account(s) for this lead</p>
                  <ul className="mt-1 flex flex-col gap-1 text-xs">
                    {preview.duplicates.map((duplicate) => (
                      <li key={duplicate.id}>
                        {duplicate.name} · {duplicate.reference} · matched on{' '}
                        {duplicate.matched_on.join(', ')}
                      </li>
                    ))}
                  </ul>
                </AlertItem>
              )}

              {mode === 'new' ? (
                <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
                  <Field
                    label="Legal name"
                    error={errors['company.name']}
                    className="md:col-span-2"
                  >
                    <input
                      value={company.name}
                      onChange={(event) =>
                        setCompany((current) => ({ ...current, name: event.target.value }))
                      }
                      className={inputClass}
                      required
                    />
                  </Field>
                  <Field label="Trade name" error={errors['company.trade_name']}>
                    <input
                      value={company.trade_name}
                      onChange={(event) =>
                        setCompany((current) => ({ ...current, trade_name: event.target.value }))
                      }
                      className={inputClass}
                    />
                  </Field>
                  <Field label="Industry" error={errors['company.industry']}>
                    <input
                      value={company.industry}
                      onChange={(event) =>
                        setCompany((current) => ({ ...current, industry: event.target.value }))
                      }
                      className={inputClass}
                    />
                  </Field>
                  <Field label="Size" error={errors['company.size']}>
                    <select
                      value={company.size}
                      onChange={(event) =>
                        setCompany((current) => ({ ...current, size: event.target.value }))
                      }
                      className={inputClass}
                    >
                      <option value="">Not set</option>
                      {(Object.keys(companySizeLabels) as CompanySize[]).map((value) => (
                        <option key={value} value={value}>
                          {companySizeLabels[value]}
                        </option>
                      ))}
                    </select>
                  </Field>
                  <Field label="Website" error={errors['company.website']}>
                    <input
                      value={company.website}
                      onChange={(event) =>
                        setCompany((current) => ({ ...current, website: event.target.value }))
                      }
                      className={inputClass}
                    />
                  </Field>
                </div>
              ) : (
                <div className="flex flex-col gap-3">
                  <Field label="Find an account" error={errors.link_company_id}>
                    <input
                      value={companySearch}
                      onChange={(event) => setCompanySearch(event.target.value)}
                      placeholder="Search by name or reference"
                      className={inputClass}
                    />
                  </Field>
                  <ul className="flex max-h-64 flex-col gap-1 overflow-y-auto">
                    {options.length === 0 && (
                      <li className="text-sm text-muted-foreground">No accounts found.</li>
                    )}
                    {options.map((option) => (
                      <li key={option.id}>
                        <button
                          type="button"
                          onClick={() => setLinkCompanyId(option.id)}
                          className={`flex w-full items-center justify-between gap-2 rounded-md border px-3 py-2 text-left text-sm transition-colors ${
                            linkCompanyId === option.id
                              ? 'border-primary bg-secondary'
                              : 'border-border hover:bg-muted'
                          }`}
                        >
                          <span className="font-medium text-foreground">{option.name}</span>
                          <span className="flex items-center gap-2 text-xs text-muted-foreground">
                            {option.reference}
                            <StatusBadge tone={companyStatusMeta[option.status].tone}>
                              {companyStatusMeta[option.status].label}
                            </StatusBadge>
                          </span>
                        </button>
                      </li>
                    ))}
                  </ul>
                </div>
              )}
            </div>
          </FormCard>
        )}

        {step === 1 && (
          <FormCard
            title="2 · Contacts"
            description="Carried over from the lead. Pick the role type and who we should lead with."
          >
            <div className="flex flex-col gap-4">
              {errors.contacts && (
                <p className="text-sm text-destructive">{errors.contacts.join(' ')}</p>
              )}

              {contacts.map((contact, index) => (
                <div key={index} className="rounded-lg border border-border p-4">
                  <div className="mb-3 flex items-center justify-between">
                    <label className="flex items-center gap-2 text-sm text-foreground">
                      <input
                        type="radio"
                        name="primary"
                        checked={contact.is_primary}
                        onChange={() => setPrimary(index)}
                      />
                      Primary contact
                    </label>
                    <button
                      type="button"
                      onClick={() => setContacts((rows) => rows.filter((_, i) => i !== index))}
                      disabled={contacts.length === 1}
                      className="inline-flex items-center gap-1 text-xs font-medium text-destructive disabled:text-muted-foreground"
                    >
                      <Trash2 className="h-3.5 w-3.5" aria-hidden="true" />
                      Remove
                    </button>
                  </div>

                  <div className="grid grid-cols-1 gap-3 md:grid-cols-2">
                    <Field label="Name" error={errors[`contacts.${index}.name`]}>
                      <input
                        value={contact.name}
                        onChange={(event) => updateContact(index, { name: event.target.value })}
                        className={inputClass}
                        required
                      />
                    </Field>
                    <Field label="Position">
                      <input
                        value={contact.position ?? ''}
                        onChange={(event) => updateContact(index, { position: event.target.value })}
                        className={inputClass}
                      />
                    </Field>
                    <Field label="Role type" error={errors[`contacts.${index}.role_type`]}>
                      <select
                        value={contact.role_type}
                        onChange={(event) =>
                          updateContact(index, { role_type: event.target.value as ContactRoleType })
                        }
                        className={inputClass}
                      >
                        {(Object.keys(contactRoleMeta) as ContactRoleType[]).map((value) => (
                          <option key={value} value={value}>
                            {contactRoleMeta[value].label}
                          </option>
                        ))}
                      </select>
                    </Field>
                    <Field label="Email" error={errors[`contacts.${index}.email`]}>
                      <input
                        type="email"
                        value={contact.email ?? ''}
                        onChange={(event) => updateContact(index, { email: event.target.value })}
                        className={inputClass}
                      />
                    </Field>
                    <Field label="Phone">
                      <input
                        value={contact.phone ?? ''}
                        onChange={(event) => updateContact(index, { phone: event.target.value })}
                        className={inputClass}
                      />
                    </Field>
                    <Field label="Mobile">
                      <input
                        value={contact.mobile ?? ''}
                        onChange={(event) => updateContact(index, { mobile: event.target.value })}
                        className={inputClass}
                      />
                    </Field>
                  </div>
                </div>
              ))}

              <button
                type="button"
                onClick={() =>
                  setContacts((rows) => [
                    ...rows,
                    {
                      name: '',
                      position: null,
                      role_type: 'other',
                      email: null,
                      phone: null,
                      mobile: null,
                      is_primary: rows.length === 0,
                    },
                  ])
                }
                className="inline-flex items-center gap-2 self-start rounded-md border border-border px-3 py-2 text-sm font-medium text-foreground hover:bg-muted"
              >
                <Plus className="h-4 w-4" aria-hidden="true" />
                Add contact
              </button>
            </div>
          </FormCard>
        )}

        {step === 2 && (
          <FormCard
            title="3 · Opportunity"
            description="The deal opens at Qualified; it moves through the pipeline afterwards."
          >
            <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
              <Field label="Deal name" error={errors['opportunity.name']} className="md:col-span-2">
                <input
                  value={opportunity.name}
                  onChange={(event) =>
                    setOpportunity((current) => ({ ...current, name: event.target.value }))
                  }
                  className={inputClass}
                  required
                />
              </Field>

              <Field label="Stage">
                <p className="py-2 text-sm text-foreground">
                  <StatusBadge tone={opportunityStageMeta[preview.opportunity.stage].tone}>
                    {opportunityStageMeta[preview.opportunity.stage].label}
                  </StatusBadge>{' '}
                  <span className="text-muted-foreground">
                    {preview.opportunity.probability}% probability
                  </span>
                </p>
              </Field>

              {canSetAmount && (
                <Field
                  label="Amount (₱)"
                  error={errors['opportunity.amount']}
                  hint={formatCurrency(preview.opportunity.amount ?? 0)}
                >
                  <input
                    type="number"
                    min="0"
                    step="0.01"
                    value={opportunity.amount}
                    onChange={(event) =>
                      setOpportunity((current) => ({ ...current, amount: event.target.value }))
                    }
                    className={inputClass}
                  />
                </Field>
              )}

              <Field label="Expected close date" error={errors['opportunity.expected_close_date']}>
                <input
                  type="date"
                  value={opportunity.expected_close_date}
                  onChange={(event) =>
                    setOpportunity((current) => ({
                      ...current,
                      expected_close_date: event.target.value,
                    }))
                  }
                  className={inputClass}
                />
              </Field>

              <p className="text-xs text-muted-foreground md:col-span-2">
                {canSetAmount
                  ? 'The amount defaults to the rate captured during qualification.'
                  : 'Deal amounts are managed by a manager — the deal opens without one.'}
              </p>
            </div>
          </FormCard>
        )}

        <div className="flex flex-wrap items-center gap-3">
          {step > 0 && (
            <button
              type="button"
              onClick={() => setStep((value) => value - 1)}
              className="rounded-md border border-border px-4 py-2 text-sm font-medium text-foreground hover:bg-muted"
            >
              Back
            </button>
          )}

          {step < steps.length - 1 ? (
            <button
              type="button"
              onClick={() => setStep((value) => value + 1)}
              className="rounded-md bg-primary px-4 py-2 text-sm font-medium text-primary-foreground"
            >
              Continue
            </button>
          ) : (
            <button
              type="submit"
              disabled={saving}
              className="rounded-md bg-primary px-4 py-2 text-sm font-medium text-primary-foreground disabled:opacity-60"
            >
              {saving ? 'Converting…' : 'Convert to client'}
            </button>
          )}
        </div>
      </form>
    </div>
  )
}

const inputClass =
  'w-full rounded-md border border-input bg-card px-3 py-2 text-sm text-foreground outline-none focus:ring-2 focus:ring-ring'

function Field({
  label,
  error,
  hint,
  className,
  children,
}: {
  label: string
  error?: string[]
  hint?: string
  className?: string
  children: ReactNode
}) {
  return (
    <label className={`flex flex-col gap-1 ${className ?? ''}`}>
      <span className="text-sm font-medium text-foreground">{label}</span>
      {children}
      {hint && <span className="text-xs text-muted-foreground">{hint}</span>}
      {error && <span className="text-xs text-destructive">{error.join(' ')}</span>}
    </label>
  )
}
