import { useCallback, useEffect, useState, type FormEvent, type ReactNode } from 'react'
import { Link, useLocation, useNavigate, useParams } from 'react-router-dom'
import { ArrowLeft } from 'lucide-react'
import PageHeader from '../components/common/PageHeader'
import FormCard from '../components/common/FormCard'
import StatusBadge from '../components/common/StatusBadge'
import AlertItem from '../components/common/AlertItem'
import {
  createCompanySite,
  deleteCompany,
  deleteCompanySite,
  getCompany,
  getCompanyTimeline,
  listCompanies,
  listCompanySites,
  logCompanyActivity,
  updateCompany,
  updateCompanySite,
  type CompanySiteInput,
  type DuplicateCompany,
} from '../lib/companies'
import { getAssignableUsers, parseValidationError } from '../lib/leads'
import { activityTypeMeta } from '../lib/leadMeta'
import { companySizeLabels, companyStatusMeta, companyTierLabels } from '../lib/companyMeta'
import { formatDate, formatDateTime } from '../lib/format'
import type {
  Activity,
  AssignmentTarget,
  Company,
  CompanySite,
  CompanySize,
  CompanyTier,
} from '../types'

type TabKey = 'overview' | 'sites' | 'activities'

interface CompanyForm {
  name: string
  trade_name: string
  industry: string
  size: string
  website: string
  description: string
  tier: string
  client_since: string
  parent_company_id: string
  tags: string
}

interface SiteForm {
  id: string | null
  label: string
  street_address: string
  barangay: string
  city_municipality: string
  province: string
  region: string
  postal_code: string
  is_primary: boolean
}

const emptySite: SiteForm = {
  id: null,
  label: '',
  street_address: '',
  barangay: '',
  city_municipality: '',
  province: '',
  region: '',
  postal_code: '',
  is_primary: false,
}

const formFromCompany = (company: Company): CompanyForm => ({
  name: company.name,
  trade_name: company.trade_name ?? '',
  industry: company.industry ?? '',
  size: company.size ?? '',
  website: company.website ?? '',
  description: company.description ?? '',
  tier: company.tier ?? '',
  client_since: company.client_since ?? '',
  parent_company_id: company.parent_company_id ?? '',
  tags: company.tags.join(', '),
})

const formFromSite = (site: CompanySite): SiteForm => ({
  id: site.id,
  label: site.label,
  street_address: site.street_address ?? '',
  barangay: site.barangay ?? '',
  city_municipality: site.city_municipality ?? '',
  province: site.province ?? '',
  region: site.region ?? '',
  postal_code: site.postal_code ?? '',
  is_primary: site.is_primary,
})

// Submodule 06 — a single account: profile, branch link, sites and activity history.
export default function CompanyDetail() {
  const { id = '' } = useParams()
  const navigate = useNavigate()
  const location = useLocation()
  const duplicates =
    (location.state as { duplicates?: DuplicateCompany[] } | null)?.duplicates ?? []

  const [company, setCompany] = useState<Company | null>(null)
  const [sites, setSites] = useState<CompanySite[]>([])
  const [branches, setBranches] = useState<Company[]>([])
  const [options, setOptions] = useState<Company[]>([])
  const [targets, setTargets] = useState<AssignmentTarget[]>([])
  const [activities, setActivities] = useState<Activity[]>([])

  const [tab, setTab] = useState<TabKey>('overview')
  const [form, setForm] = useState<CompanyForm | null>(null)
  const [siteForm, setSiteForm] = useState<SiteForm | null>(null)
  const [activityForm, setActivityForm] = useState({ type: 'call', subject: '', body: '' })

  const [loading, setLoading] = useState(true)
  const [saving, setSaving] = useState(false)
  const [confirmingDelete, setConfirmingDelete] = useState(false)
  const [message, setMessage] = useState('')
  const [alertVariant, setAlertVariant] = useState<'success' | 'danger'>('success')
  const [errors, setErrors] = useState<Record<string, string[]>>({})

  const load = useCallback(async () => {
    setLoading(true)
    try {
      const detail = await getCompany(id)
      const [siteList, branchList, history, assignable] = await Promise.all([
        listCompanySites(id),
        listCompanies({ parent_company_id: id }),
        getCompanyTimeline(id),
        getAssignableUsers(),
      ])

      setCompany(detail)
      setForm(formFromCompany(detail))
      setSites(siteList)
      setBranches(branchList.data)
      setActivities(history.data)
      setTargets(assignable)
      setOptions(detail.can_manage_parent ? (await listCompanies()).data : [])
    } catch {
      setCompany(null)
      setAlertVariant('danger')
      setMessage('Could not load this company.')
    } finally {
      setLoading(false)
    }
  }, [id])

  useEffect(() => {
    void load()
  }, [load])

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

  const succeed = (note: string) => {
    setErrors({})
    setAlertVariant('success')
    setMessage(note)
  }

  const refreshSitesAndCounts = async () => {
    const [siteList, detail] = await Promise.all([listCompanySites(id), getCompany(id)])
    setSites(siteList)
    setCompany(detail)
  }

  const refreshActivities = async () => {
    const [history, detail] = await Promise.all([getCompanyTimeline(id), getCompany(id)])
    setActivities(history.data)
    setCompany(detail)
  }

  const onSaveCompany = async (event: FormEvent) => {
    event.preventDefault()
    if (!form) return

    setSaving(true)
    try {
      const updated = await updateCompany(id, {
        name: form.name.trim(),
        trade_name: blankToNull(form.trade_name),
        industry: blankToNull(form.industry),
        size: blankToNull(form.size),
        website: blankToNull(form.website),
        description: blankToNull(form.description),
        tier: blankToNull(form.tier),
        client_since: blankToNull(form.client_since),
        tags: form.tags
          .split(',')
          .map((tag) => tag.trim())
          .filter((tag) => tag !== ''),
        ...(company?.can_manage_parent
          ? { parent_company_id: blankToNull(form.parent_company_id) }
          : {}),
      })
      setCompany(updated)
      setForm(formFromCompany(updated))
      const branchList = await listCompanies({ parent_company_id: id })
      setBranches(branchList.data)
      succeed('Company updated.')
    } catch (error) {
      handleFailure(error, 'Could not save the company.')
    } finally {
      setSaving(false)
    }
  }

  const onAssign = async (ownerId: number) => {
    setSaving(true)
    try {
      const updated = await updateCompany(id, { owner_id: ownerId })
      setCompany(updated)
      succeed('Owner updated.')
    } catch (error) {
      handleFailure(error, 'Could not reassign this company.')
    } finally {
      setSaving(false)
    }
  }

  const onSaveSite = async (event: FormEvent) => {
    event.preventDefault()
    if (!siteForm) return

    const input: CompanySiteInput = {
      label: siteForm.label.trim(),
      street_address: blankToNull(siteForm.street_address),
      barangay: blankToNull(siteForm.barangay),
      city_municipality: blankToNull(siteForm.city_municipality),
      province: blankToNull(siteForm.province),
      region: blankToNull(siteForm.region),
      postal_code: blankToNull(siteForm.postal_code),
      is_primary: siteForm.is_primary,
    }

    setSaving(true)
    try {
      if (siteForm.id) {
        await updateCompanySite(id, siteForm.id, input)
      } else {
        await createCompanySite(id, input)
      }
      setSiteForm(null)
      await refreshSitesAndCounts()
      succeed(siteForm.id ? 'Site updated.' : 'Site added.')
    } catch (error) {
      handleFailure(error, 'Could not save the site.')
    } finally {
      setSaving(false)
    }
  }

  const onMakePrimary = async (site: CompanySite) => {
    setSaving(true)
    try {
      await updateCompanySite(id, site.id, { is_primary: true })
      await refreshSitesAndCounts()
      succeed(`${site.label} is now the primary site.`)
    } catch (error) {
      handleFailure(error, 'Could not set the primary site.')
    } finally {
      setSaving(false)
    }
  }

  const onDeleteSite = async (site: CompanySite) => {
    setSaving(true)
    try {
      await deleteCompanySite(id, site.id)
      await refreshSitesAndCounts()
      succeed(`${site.label} removed.`)
    } catch (error) {
      handleFailure(error, 'Could not remove the site.')
    } finally {
      setSaving(false)
    }
  }

  const onLogActivity = async (event: FormEvent) => {
    event.preventDefault()
    setSaving(true)
    try {
      await logCompanyActivity(id, {
        type: activityForm.type,
        subject: activityForm.subject.trim(),
        body: blankToNull(activityForm.body) ?? undefined,
      })
      setActivityForm({ type: 'call', subject: '', body: '' })
      await refreshActivities()
      succeed('Activity logged.')
    } catch (error) {
      handleFailure(error, 'Could not log the activity.')
    } finally {
      setSaving(false)
    }
  }

  const onDeleteCompany = async () => {
    setSaving(true)
    try {
      await deleteCompany(id)
      navigate('/companies')
    } catch (error) {
      setConfirmingDelete(false)
      handleFailure(error, 'Could not delete the company.')
    } finally {
      setSaving(false)
    }
  }

  if (loading && !company) {
    return <p className="text-sm text-muted-foreground">Loading…</p>
  }

  if (!company) {
    return (
      <div>
        <AlertItem variant="danger">{message || 'Company not found.'}</AlertItem>
        <Link to="/companies" className="mt-4 inline-block text-sm font-medium text-primary">
          Back to companies
        </Link>
      </div>
    )
  }

  const statusMeta = companyStatusMeta[company.status]
  const tabs: { key: TabKey; label: string; count: number }[] = [
    { key: 'overview', label: 'Overview', count: company.counts.branches },
    { key: 'sites', label: 'Sites', count: company.counts.sites },
    { key: 'activities', label: 'Activities', count: company.counts.activities },
  ]

  return (
    <div>
      <Link
        to="/companies"
        className="mb-3 inline-flex items-center gap-2 text-sm text-muted-foreground hover:text-foreground"
      >
        <ArrowLeft className="h-4 w-4" aria-hidden="true" />
        Back to companies
      </Link>

      <PageHeader
        title={company.name}
        description={[company.reference, company.trade_name, company.industry]
          .filter(Boolean)
          .join(' · ')}
        actions={
          <div className="flex items-center gap-2">
            {company.tier && (
              <StatusBadge tone="cost">{companyTierLabels[company.tier]}</StatusBadge>
            )}
            <StatusBadge tone={statusMeta.tone}>{statusMeta.label}</StatusBadge>
          </div>
        }
      />

      {duplicates.length > 0 && (
        <div className="mb-4">
          <AlertItem variant="warning" title="Possible duplicate company">
            {duplicates.map((duplicate) => (
              <p key={duplicate.id} className="text-xs">
                {duplicate.name} ({duplicate.reference})
              </p>
            ))}
          </AlertItem>
        </div>
      )}

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

      <div className="mb-4 flex flex-wrap gap-1 rounded-lg border border-border bg-card p-1">
        {tabs.map((entry) => {
          const active = entry.key === tab
          return (
            <button
              key={entry.key}
              type="button"
              onClick={() => setTab(entry.key)}
              className={`inline-flex items-center gap-2 rounded-md px-3 py-1.5 text-sm font-medium transition-colors ${
                active ? 'bg-secondary text-primary' : 'text-muted-foreground hover:bg-muted'
              }`}
            >
              {entry.label}
              <span
                className={`rounded px-1.5 text-xs ${
                  active ? 'bg-primary/10 text-primary' : 'bg-muted text-muted-foreground'
                }`}
              >
                {entry.count}
              </span>
            </button>
          )
        })}
      </div>

      {tab === 'overview' && form && (
        <div className="grid grid-cols-1 gap-6 lg:grid-cols-3">
          <div className="lg:col-span-2">
            <FormCard
              title="Company details"
              description={
                company.can_update
                  ? undefined
                  : 'You need to own this account (or be its manager) to edit it.'
              }
            >
              <form onSubmit={onSaveCompany} className="grid grid-cols-1 gap-4 md:grid-cols-2">
                <Field label="Legal name" error={errors.name} className="md:col-span-2">
                  <input
                    value={form.name}
                    disabled={!company.can_update}
                    onChange={(event) =>
                      setForm((previous) => previous && { ...previous, name: event.target.value })
                    }
                    className={inputClass}
                  />
                </Field>

                <Field label="Trade name" error={errors.trade_name}>
                  <input
                    value={form.trade_name}
                    disabled={!company.can_update}
                    onChange={(event) =>
                      setForm(
                        (previous) => previous && { ...previous, trade_name: event.target.value },
                      )
                    }
                    className={inputClass}
                  />
                </Field>

                <Field label="Industry" error={errors.industry}>
                  <input
                    value={form.industry}
                    disabled={!company.can_update}
                    onChange={(event) =>
                      setForm(
                        (previous) => previous && { ...previous, industry: event.target.value },
                      )
                    }
                    className={inputClass}
                  />
                </Field>

                <Field label="Size" error={errors.size}>
                  <select
                    value={form.size}
                    disabled={!company.can_update}
                    onChange={(event) =>
                      setForm((previous) => previous && { ...previous, size: event.target.value })
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

                <Field label="Tier" error={errors.tier}>
                  <select
                    value={form.tier}
                    disabled={!company.can_update}
                    onChange={(event) =>
                      setForm((previous) => previous && { ...previous, tier: event.target.value })
                    }
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

                <Field label="Website" error={errors.website}>
                  <input
                    value={form.website}
                    disabled={!company.can_update}
                    onChange={(event) =>
                      setForm(
                        (previous) => previous && { ...previous, website: event.target.value },
                      )
                    }
                    className={inputClass}
                  />
                </Field>

                <Field label="Client since" error={errors.client_since}>
                  <input
                    type="date"
                    value={form.client_since}
                    disabled={!company.can_update}
                    onChange={(event) =>
                      setForm(
                        (previous) => previous && { ...previous, client_since: event.target.value },
                      )
                    }
                    className={inputClass}
                  />
                </Field>

                <Field
                  label="Parent company (branch of)"
                  error={errors.parent_company_id}
                  className="md:col-span-2"
                >
                  <select
                    value={form.parent_company_id}
                    disabled={!company.can_manage_parent}
                    onChange={(event) =>
                      setForm(
                        (previous) =>
                          previous && { ...previous, parent_company_id: event.target.value },
                      )
                    }
                    className={inputClass}
                  >
                    <option value="">Not a branch</option>
                    {company.parent && (
                      <option value={company.parent.id}>{company.parent.name}</option>
                    )}
                    {options
                      .filter(
                        (option) =>
                          option.id !== company.id && option.id !== company.parent_company_id,
                      )
                      .map((option) => (
                        <option key={option.id} value={option.id}>
                          {option.name} ({option.reference})
                        </option>
                      ))}
                  </select>
                  {!company.can_manage_parent && (
                    <span className="mt-1 block text-xs text-muted-foreground">
                      Only a manager or admin can change the branch link.
                    </span>
                  )}
                </Field>

                <Field label="Tags (comma separated)" error={errors.tags} className="md:col-span-2">
                  <input
                    value={form.tags}
                    disabled={!company.can_update}
                    onChange={(event) =>
                      setForm((previous) => previous && { ...previous, tags: event.target.value })
                    }
                    placeholder="warehouse, referral"
                    className={inputClass}
                  />
                </Field>

                <Field label="Description" error={errors.description} className="md:col-span-2">
                  <textarea
                    rows={3}
                    value={form.description}
                    disabled={!company.can_update}
                    onChange={(event) =>
                      setForm(
                        (previous) => previous && { ...previous, description: event.target.value },
                      )
                    }
                    className={inputClass}
                  />
                </Field>

                {company.can_update && (
                  <div className="md:col-span-2">
                    <button
                      type="submit"
                      disabled={saving}
                      className="rounded-md bg-primary px-4 py-2 text-sm font-medium text-primary-foreground disabled:opacity-60"
                    >
                      {saving ? 'Saving…' : 'Save company'}
                    </button>
                  </div>
                )}
              </form>
            </FormCard>
          </div>

          <div className="flex flex-col gap-6">
            <FormCard title="Ownership">
              <div className="flex items-center justify-between">
                <span className="text-sm text-muted-foreground">Account owner</span>
                <span className="text-sm font-medium text-foreground">
                  {company.owner?.name ?? '—'}
                </span>
              </div>

              {company.can_assign && (
                <div className="mt-3">
                  <Field label="Reassign to">
                    <select
                      value={company.owner_id}
                      disabled={saving}
                      onChange={(event) => void onAssign(Number(event.target.value))}
                      className={inputClass}
                    >
                      {targets.map((target) => (
                        <option key={target.id} value={target.id}>
                          {target.name}
                        </option>
                      ))}
                      {company.owner &&
                        !targets.some((target) => target.id === company.owner_id) && (
                          <option value={company.owner_id}>{company.owner.name}</option>
                        )}
                    </select>
                  </Field>
                </div>
              )}
            </FormCard>

            <FormCard
              title="Branch"
              description="Branches are separate records — they inherit nothing."
            >
              <div className="flex items-center justify-between text-sm">
                <span className="text-muted-foreground">Parent</span>
                {company.parent ? (
                  <Link to={`/companies/${company.parent.id}`} className="font-medium text-primary">
                    {company.parent.name}
                  </Link>
                ) : (
                  <span className="text-foreground">None</span>
                )}
              </div>

              <div className="mt-3 flex items-center justify-between text-sm">
                <span className="text-muted-foreground">Branches</span>
                <span className="text-foreground">{company.counts.branches}</span>
              </div>

              {branches.length > 0 && (
                <ul className="mt-3 flex flex-col gap-1 border-t border-border pt-3">
                  {branches.map((branch) => (
                    <li key={branch.id} className="text-sm">
                      <Link to={`/companies/${branch.id}`} className="text-primary">
                        {branch.name}
                      </Link>
                      <span className="ml-2 font-mono text-xs text-muted-foreground">
                        {branch.reference}
                      </span>
                    </li>
                  ))}
                </ul>
              )}
            </FormCard>

            <FormCard title="Record">
              <dl className="flex flex-col gap-3 text-sm">
                <Detail label="Reference" value={company.reference} />
                <Detail label="Status" value={statusMeta.label} />
                <Detail
                  label="Client since"
                  value={company.client_since ? formatDate(company.client_since) : '—'}
                />
                <Detail label="Created" value={formatDate(company.created_at)} />
                <Detail
                  label="Source lead"
                  value={company.source_lead_id ? 'Converted from a lead' : 'Created manually'}
                />
              </dl>

              {company.can_delete && (
                <div className="mt-4 border-t border-border pt-4">
                  {confirmingDelete ? (
                    <div className="flex flex-wrap items-center gap-2">
                      <span className="text-xs text-muted-foreground">Delete this company?</span>
                      <button
                        type="button"
                        disabled={saving}
                        onClick={() => void onDeleteCompany()}
                        className="rounded-md bg-destructive px-3 py-1.5 text-xs font-medium text-primary-foreground disabled:opacity-60"
                      >
                        Yes, delete
                      </button>
                      <button
                        type="button"
                        onClick={() => setConfirmingDelete(false)}
                        className="rounded-md border border-border px-3 py-1.5 text-xs font-medium text-foreground hover:bg-muted"
                      >
                        Cancel
                      </button>
                    </div>
                  ) : (
                    <button
                      type="button"
                      onClick={() => setConfirmingDelete(true)}
                      className="text-xs font-medium text-destructive"
                    >
                      Delete company
                    </button>
                  )}
                </div>
              )}
            </FormCard>
          </div>
        </div>
      )}

      {tab === 'sites' && (
        <div className="grid grid-cols-1 gap-6 lg:grid-cols-3">
          <div className="flex flex-col gap-6 lg:col-span-2">
            <FormCard
              title="Sites"
              description={`${sites.length} location(s). One is the primary site.`}
            >
              {sites.length === 0 ? (
                <p className="text-sm text-muted-foreground">No sites recorded yet.</p>
              ) : (
                <ul className="flex flex-col divide-y divide-border">
                  {sites.map((site) => (
                    <li key={site.id} className="flex flex-wrap items-start gap-3 py-3 first:pt-0">
                      <div className="min-w-0 flex-1">
                        <div className="flex items-center gap-2">
                          <span className="text-sm font-medium text-foreground">{site.label}</span>
                          {site.is_primary && <StatusBadge tone="people">Primary</StatusBadge>}
                        </div>
                        <p className="mt-0.5 text-xs text-muted-foreground">
                          {site.full_address || 'No address on file'}
                        </p>
                      </div>

                      {company.can_manage_sites && (
                        <div className="flex items-center gap-2">
                          {!site.is_primary && (
                            <button
                              type="button"
                              disabled={saving}
                              onClick={() => void onMakePrimary(site)}
                              className="rounded-md border border-border px-2.5 py-1 text-xs font-medium text-foreground hover:bg-muted disabled:opacity-60"
                            >
                              Make primary
                            </button>
                          )}
                          <button
                            type="button"
                            onClick={() => setSiteForm(formFromSite(site))}
                            className="rounded-md border border-border px-2.5 py-1 text-xs font-medium text-foreground hover:bg-muted"
                          >
                            Edit
                          </button>
                          <button
                            type="button"
                            disabled={saving}
                            onClick={() => void onDeleteSite(site)}
                            className="rounded-md px-2.5 py-1 text-xs font-medium text-destructive"
                          >
                            Remove
                          </button>
                        </div>
                      )}
                    </li>
                  ))}
                </ul>
              )}

              {!company.can_manage_sites && (
                <p className="mt-4 border-t border-border pt-4 text-xs text-muted-foreground">
                  Sites and branches are maintained by a manager or admin.
                </p>
              )}
            </FormCard>
          </div>

          {company.can_manage_sites && (
            <FormCard
              title={siteForm?.id ? 'Edit site' : 'Add site'}
              description="Philippine address — all parts but the label are optional."
            >
              {siteForm ? (
                <form onSubmit={onSaveSite} className="flex flex-col gap-3">
                  <Field label="Label" error={errors.label}>
                    <input
                      value={siteForm.label}
                      onChange={(event) =>
                        setSiteForm(
                          (previous) => previous && { ...previous, label: event.target.value },
                        )
                      }
                      placeholder="Head office"
                      className={inputClass}
                    />
                  </Field>

                  <Field label="Street address" error={errors.street_address}>
                    <input
                      value={siteForm.street_address}
                      onChange={(event) =>
                        setSiteForm(
                          (previous) =>
                            previous && { ...previous, street_address: event.target.value },
                        )
                      }
                      className={inputClass}
                    />
                  </Field>

                  <Field label="Barangay" error={errors.barangay}>
                    <input
                      value={siteForm.barangay}
                      onChange={(event) =>
                        setSiteForm(
                          (previous) => previous && { ...previous, barangay: event.target.value },
                        )
                      }
                      className={inputClass}
                    />
                  </Field>

                  <Field label="City / municipality" error={errors.city_municipality}>
                    <input
                      value={siteForm.city_municipality}
                      onChange={(event) =>
                        setSiteForm(
                          (previous) =>
                            previous && { ...previous, city_municipality: event.target.value },
                        )
                      }
                      className={inputClass}
                    />
                  </Field>

                  <Field label="Province" error={errors.province}>
                    <input
                      value={siteForm.province}
                      onChange={(event) =>
                        setSiteForm(
                          (previous) => previous && { ...previous, province: event.target.value },
                        )
                      }
                      className={inputClass}
                    />
                  </Field>

                  <Field label="Region" error={errors.region}>
                    <input
                      value={siteForm.region}
                      onChange={(event) =>
                        setSiteForm(
                          (previous) => previous && { ...previous, region: event.target.value },
                        )
                      }
                      className={inputClass}
                    />
                  </Field>

                  <Field label="Postal code" error={errors.postal_code}>
                    <input
                      value={siteForm.postal_code}
                      onChange={(event) =>
                        setSiteForm(
                          (previous) =>
                            previous && { ...previous, postal_code: event.target.value },
                        )
                      }
                      className={inputClass}
                    />
                  </Field>

                  <label className="flex items-center gap-2 text-sm text-foreground">
                    <input
                      type="checkbox"
                      checked={siteForm.is_primary}
                      onChange={(event) =>
                        setSiteForm(
                          (previous) =>
                            previous && { ...previous, is_primary: event.target.checked },
                        )
                      }
                      className="h-4 w-4 rounded border-input"
                    />
                    Primary site
                  </label>

                  <div className="flex items-center gap-2">
                    <button
                      type="submit"
                      disabled={saving || siteForm.label.trim() === ''}
                      className="rounded-md bg-primary px-4 py-2 text-sm font-medium text-primary-foreground disabled:opacity-60"
                    >
                      {saving ? 'Saving…' : siteForm.id ? 'Save site' : 'Add site'}
                    </button>
                    <button
                      type="button"
                      onClick={() => setSiteForm(null)}
                      className="rounded-md border border-border px-4 py-2 text-sm font-medium text-foreground hover:bg-muted"
                    >
                      Cancel
                    </button>
                  </div>
                </form>
              ) : (
                <button
                  type="button"
                  onClick={() => setSiteForm({ ...emptySite })}
                  className="rounded-md bg-primary px-4 py-2 text-sm font-medium text-primary-foreground"
                >
                  Add a site
                </button>
              )}
            </FormCard>
          )}
        </div>
      )}

      {tab === 'activities' && (
        <div className="grid grid-cols-1 gap-6 lg:grid-cols-3">
          <div className="flex flex-col gap-6 lg:col-span-2">
            <FormCard
              title="Activities"
              description="Calls, emails, meetings and notes, newest first."
            >
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
            {company.can_update ? (
              <FormCard title="Log activity">
                <form onSubmit={onLogActivity} className="flex flex-col gap-3">
                  <Field label="Type" error={errors.type}>
                    <select
                      value={activityForm.type}
                      onChange={(event) =>
                        setActivityForm((previous) => ({ ...previous, type: event.target.value }))
                      }
                      className={inputClass}
                    >
                      {(['call', 'email', 'meeting', 'walk_in', 'note', 'task'] as const).map(
                        (value) => (
                          <option key={value} value={value}>
                            {activityTypeMeta[value].label}
                          </option>
                        ),
                      )}
                    </select>
                  </Field>

                  <Field label="Subject" error={errors.subject}>
                    <input
                      value={activityForm.subject}
                      onChange={(event) =>
                        setActivityForm((previous) => ({
                          ...previous,
                          subject: event.target.value,
                        }))
                      }
                      placeholder="Quarterly check-in"
                      className={inputClass}
                    />
                  </Field>

                  <Field label="Notes" error={errors.body}>
                    <textarea
                      rows={2}
                      value={activityForm.body}
                      onChange={(event) =>
                        setActivityForm((previous) => ({ ...previous, body: event.target.value }))
                      }
                      className={inputClass}
                    />
                  </Field>

                  <button
                    type="submit"
                    disabled={saving || activityForm.subject.trim() === ''}
                    className="rounded-md bg-activity px-4 py-2 text-sm font-medium text-primary-foreground disabled:opacity-60"
                  >
                    {saving ? 'Saving…' : 'Log activity'}
                  </button>
                </form>
              </FormCard>
            ) : (
              <FormCard title="Log activity">
                <p className="text-sm text-muted-foreground">
                  You need to own this account (or be its manager) to log activity.
                </p>
              </FormCard>
            )}

            <FormCard
              title="Coming in later submodules"
              description="Contacts, opportunities and contracts attach here."
            >
              <p className="text-xs text-muted-foreground">
                Submodule 07 adds contacts, 08 conversion, and 09 the client lifecycle and
                contracts.
              </p>
            </FormCard>
          </div>
        </div>
      )}
    </div>
  )
}

const inputClass =
  'mt-1 w-full rounded-md border border-input bg-card px-3 py-2 text-sm text-foreground outline-none focus:ring-2 focus:ring-ring disabled:opacity-60'

function blankToNull(value: string): string | null {
  const trimmed = value.trim()
  return trimmed === '' ? null : trimmed
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

function Detail({ label, value }: { label: string; value: string }) {
  return (
    <div className="flex items-center justify-between gap-3">
      <dt className="text-muted-foreground">{label}</dt>
      <dd className="text-right text-foreground">{value}</dd>
    </div>
  )
}
