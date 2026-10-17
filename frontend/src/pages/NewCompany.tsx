import { useEffect, useState, type FormEvent, type ReactNode } from 'react'
import { useNavigate } from 'react-router-dom'
import PageHeader from '../components/common/PageHeader'
import FormCard from '../components/common/FormCard'
import AlertItem from '../components/common/AlertItem'
import { createCompany, listCompanies, type CompanyInput } from '../lib/companies'
import { getAssignableUsers, parseValidationError } from '../lib/leads'
import { companySizeLabels, companyStatusMeta, companyTierLabels } from '../lib/companyMeta'
import { useAuth } from '../lib/auth-context'
import type { AssignmentTarget, Company, CompanySize, CompanyStatus, CompanyTier } from '../types'

interface CompanyForm {
  name: string
  trade_name: string
  industry: string
  size: string
  website: string
  description: string
  tier: string
  status: string
  parent_company_id: string
  owner_id: string
}

const emptyForm: CompanyForm = {
  name: '',
  trade_name: '',
  industry: '',
  size: '',
  website: '',
  description: '',
  tier: '',
  status: '',
  parent_company_id: '',
  owner_id: '',
}

// Submodule 06 — manual creation (importing an existing client). Every role may create;
// only manager+ may open it in a client state or hand it to another owner.
export default function NewCompany() {
  const navigate = useNavigate()
  const { user } = useAuth()
  const isManagerOrAbove = user?.role !== 'salesrep'

  const [form, setForm] = useState<CompanyForm>(emptyForm)
  const [companies, setCompanies] = useState<Company[]>([])
  const [targets, setTargets] = useState<AssignmentTarget[]>([])
  const [saving, setSaving] = useState(false)
  const [message, setMessage] = useState('')
  const [errors, setErrors] = useState<Record<string, string[]>>({})

  useEffect(() => {
    void (async () => {
      try {
        const [response, assignable] = await Promise.all([
          listCompanies(),
          isManagerOrAbove ? getAssignableUsers() : Promise.resolve([]),
        ])
        setCompanies(response.data)
        setTargets(assignable)
      } catch {
        // Non-critical: the parent/owner pickers simply stay empty.
      }
    })()
  }, [isManagerOrAbove])

  const update = <K extends keyof CompanyForm>(field: K, value: CompanyForm[K]) =>
    setForm((previous) => ({ ...previous, [field]: value }))

  const onSubmit = async (event: FormEvent) => {
    event.preventDefault()
    setSaving(true)
    setMessage('')
    setErrors({})

    try {
      const { data, duplicates } = await createCompany(toInput(form, isManagerOrAbove))
      navigate(`/companies/${data.id}`, { state: { duplicates } })
    } catch (error) {
      const validation = parseValidationError(error)
      if (validation) {
        setErrors(validation.errors)
        setMessage(validation.message)
      } else {
        setMessage('Could not create the company.')
      }
    } finally {
      setSaving(false)
    }
  }

  return (
    <div>
      <PageHeader
        title="New company"
        description="Add an organization — an existing client or a fresh prospect."
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
        <FormCard title="Company details">
          <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
            <Field label="Legal name" error={errors.name} className="md:col-span-2">
              <input
                value={form.name}
                onChange={(event) => update('name', event.target.value)}
                placeholder="Sunrise Foods Inc"
                className={inputClass}
              />
            </Field>

            <Field label="Trade name" error={errors.trade_name}>
              <input
                value={form.trade_name}
                onChange={(event) => update('trade_name', event.target.value)}
                className={inputClass}
              />
            </Field>

            <Field label="Industry" error={errors.industry}>
              <input
                value={form.industry}
                onChange={(event) => update('industry', event.target.value)}
                className={inputClass}
              />
            </Field>

            <Field label="Company size" error={errors.size}>
              <select
                value={form.size}
                onChange={(event) => update('size', event.target.value)}
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

            <Field label="Tier" error={errors.tier}>
              <select
                value={form.tier}
                onChange={(event) => update('tier', event.target.value)}
                className={inputClass}
              >
                <option value="">Not set</option>
                {(Object.keys(companyTierLabels) as CompanyTier[]).map((value) => (
                  <option key={value} value={value}>
                    {companyTierLabels[value]}
                  </option>
                ))}
              </select>
            </Field>

            <Field label="Website" error={errors.website} className="md:col-span-2">
              <input
                value={form.website}
                onChange={(event) => update('website', event.target.value)}
                placeholder="https://example.ph"
                className={inputClass}
              />
            </Field>

            <Field label="Description" error={errors.description} className="md:col-span-2">
              <textarea
                rows={3}
                value={form.description}
                onChange={(event) => update('description', event.target.value)}
                className={inputClass}
              />
            </Field>
          </div>
        </FormCard>

        <div className="mt-6">
          <FormCard
            title="Classification & ownership"
            description={
              isManagerOrAbove
                ? 'Import an existing client in its current state, or leave it as a prospect.'
                : 'Your new company starts as a prospect and is owned by you.'
            }
          >
            <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
              {isManagerOrAbove && (
                <>
                  <Field label="Status" error={errors.status}>
                    <select
                      value={form.status}
                      onChange={(event) => update('status', event.target.value)}
                      className={inputClass}
                    >
                      <option value="">Prospect (default)</option>
                      {(Object.keys(companyStatusMeta) as CompanyStatus[]).map((value) => (
                        <option key={value} value={value}>
                          {companyStatusMeta[value].label}
                        </option>
                      ))}
                    </select>
                  </Field>

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
                </>
              )}

              <Field
                label="Parent company (branch of)"
                error={errors.parent_company_id}
                className="md:col-span-2"
              >
                <select
                  value={form.parent_company_id}
                  onChange={(event) => update('parent_company_id', event.target.value)}
                  className={inputClass}
                >
                  <option value="">Not a branch</option>
                  {companies.map((company) => (
                    <option key={company.id} value={company.id}>
                      {company.name} ({company.reference})
                    </option>
                  ))}
                </select>
              </Field>
            </div>

            <div className="mt-4">
              <button
                type="submit"
                disabled={saving || form.name.trim() === ''}
                className="rounded-md bg-primary px-4 py-2 text-sm font-medium text-primary-foreground disabled:opacity-60"
              >
                {saving ? 'Creating…' : 'Create company'}
              </button>
            </div>
          </FormCard>
        </div>
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

function toInput(form: CompanyForm, includeClassification: boolean): CompanyInput {
  const trimmed = (value: string) => (value.trim() === '' ? null : value.trim())

  const input: CompanyInput = {
    name: form.name.trim(),
    trade_name: trimmed(form.trade_name),
    industry: trimmed(form.industry),
    size: trimmed(form.size),
    website: trimmed(form.website),
    description: trimmed(form.description),
    tier: trimmed(form.tier),
    parent_company_id: trimmed(form.parent_company_id),
  }

  if (includeClassification) {
    if (form.status !== '') input.status = form.status
    if (form.owner_id !== '') input.owner_id = Number(form.owner_id)
  }

  return input
}
