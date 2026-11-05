import { useCallback, useEffect, useState, type FormEvent, type ReactNode } from 'react'
import { Link, useLocation, useNavigate, useParams, useSearchParams } from 'react-router-dom'
import { ArrowLeft, CalendarClock, Plus, SquareKanban } from 'lucide-react'
import PageHeader from '../components/common/PageHeader'
import FormCard from '../components/common/FormCard'
import StatusBadge from '../components/common/StatusBadge'
import AlertItem from '../components/common/AlertItem'
import AccountHealthPanel from '../components/common/AccountHealthPanel'
import EmptyState from '../components/common/EmptyState'
import DataTable, { type DataTableColumn } from '../components/common/DataTable'
import CommunicationList from '../components/common/CommunicationList'
import CompanyBillingPanel from '../components/common/CompanyBillingPanel'
import CompanyDeliveryPanel from '../components/common/CompanyDeliveryPanel'
import CompanySatisfactionPanel from '../components/common/CompanySatisfactionPanel'
import JourneyStepper, { type JourneyStep } from '../components/common/JourneyStepper'
import {
  assignCompany,
  createCompanySite,
  deleteCompany,
  deleteCompanySite,
  getCompany,
  getCompanyBilling,
  getCompanyTimeline,
  listCompanies,
  listCompanySites,
  logCompanyActivity,
  reactivateCompany,
  updateCompany,
  updateCompanyLifecycle,
  updateCompanySite,
  type CompanySiteInput,
  type DuplicateCompany,
} from '../lib/companies'
import {
  createCompanyContact,
  deleteContact,
  listCompanyContacts,
  makeContactPrimary,
  updateContact,
  type ContactInput,
} from '../lib/contacts'
import { listCompanyContracts } from '../lib/contracts'
import { getAssignableUsers, parseValidationError } from '../lib/leads'
import { listCompanyOpportunities } from '../lib/opportunities'
import { activityTypeMeta } from '../lib/leadMeta'
import { followUpDueMeta } from '../lib/followUpMeta'
import { companySizeLabels, companyStatusMeta, companyTierLabels } from '../lib/companyMeta'
import { contractStatusMeta, formatExpiry } from '../lib/contractMeta'
import { contactRoleMeta, contactStatusMeta } from '../lib/contactMeta'
import { opportunityStageMeta } from '../lib/opportunityMeta'
import { formatCurrency, formatDate, formatDateTime } from '../lib/format'
import type {
  Activity,
  AssignmentTarget,
  BillingSummary,
  Company,
  CompanyBilling,
  CompanySite,
  CompanySize,
  CompanyStatus,
  CompanyTier,
  Contact,
  ContactRoleType,
  ContactStatus,
  Contract,
  ContractStatus,
  Opportunity,
} from '../types'

type TabKey = 'overview' | 'people' | 'commercial' | 'delivery' | 'engagement'

/**
 * Account 360 groups the old detail tabs (spec 16 §Account 360). The aliases keep every existing
 * deep link working while the URL is rewritten to the canonical grouped tab.
 */
const tabAliases: Record<string, TabKey> = {
  overview: 'overview',
  contacts: 'people',
  sites: 'people',
  people: 'people',
  opportunities: 'commercial',
  contracts: 'commercial',
  commercial: 'commercial',
  delivery: 'delivery',
  communications: 'engagement',
  satisfaction: 'engagement',
  activities: 'engagement',
  engagement: 'engagement',
}

interface CompanyForm {
  name: string
  trade_name: string
  industry: string
  size: string
  website: string
  description: string
  tier: string
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

interface ContactForm {
  id: string | null
  name: string
  position: string
  role_type: string
  email: string
  phone: string
  mobile: string
  status: string
  notes: string
  is_primary: boolean
}

interface ChurnForm {
  reason: string
  note: string
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

const emptyContact: ContactForm = {
  id: null,
  name: '',
  position: '',
  role_type: 'hiring_manager',
  email: '',
  phone: '',
  mobile: '',
  status: 'active',
  notes: '',
  is_primary: false,
}

const formFromContact = (contact: Contact): ContactForm => ({
  id: contact.id,
  name: contact.name,
  position: contact.position ?? '',
  role_type: contact.role_type,
  email: contact.email ?? '',
  phone: contact.phone ?? '',
  mobile: contact.mobile ?? '',
  status: contact.status,
  notes: contact.notes ?? '',
  // Primary is moved with the explicit action, not by the edit form (spec 07 §3).
  is_primary: false,
})

const formFromCompany = (company: Company): CompanyForm => ({
  name: company.name,
  trade_name: company.trade_name ?? '',
  industry: company.industry ?? '',
  size: company.size ?? '',
  website: company.website ?? '',
  description: company.description ?? '',
  tier: company.tier ?? '',
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

// Account 360 (module 01 refresh) — five grouped tabs over the account's existing records.
export default function CompanyDetail() {
  const { id = '' } = useParams()
  const navigate = useNavigate()
  const location = useLocation()
  const [searchParams, setSearchParams] = useSearchParams()
  // A won deal can deep-link here to start its contract (module 02 §5).
  const requestedTab = searchParams.get('tab')
  const tab: TabKey = requestedTab ? (tabAliases[requestedTab] ?? 'overview') : 'overview'
  const section = searchParams.get('section') ?? ''
  const duplicates =
    (location.state as { duplicates?: DuplicateCompany[] } | null)?.duplicates ?? []
  const notice = (location.state as { notice?: string } | null)?.notice ?? ''

  const [company, setCompany] = useState<Company | null>(null)
  const [sites, setSites] = useState<CompanySite[]>([])
  const [contacts, setContacts] = useState<Contact[]>([])
  const [opportunities, setOpportunities] = useState<Opportunity[]>([])
  const [contracts, setContracts] = useState<Contract[]>([])
  const [billing, setBilling] = useState<BillingSummary | null>(null)
  const [billingMeta, setBillingMeta] = useState<CompanyBilling['meta'] | null>(null)
  const [branches, setBranches] = useState<Company[]>([])
  const [options, setOptions] = useState<Company[]>([])
  const [targets, setTargets] = useState<AssignmentTarget[]>([])
  const [activities, setActivities] = useState<Activity[]>([])

  const [form, setForm] = useState<CompanyForm | null>(null)
  const [siteForm, setSiteForm] = useState<SiteForm | null>(null)
  const [contactForm, setContactForm] = useState<ContactForm | null>(null)
  const [churnForm, setChurnForm] = useState<ChurnForm | null>(null)
  const [contractStatus, setContractStatus] = useState('')
  const [contactRole, setContactRole] = useState('')
  const [contactStatus, setContactStatus] = useState('')
  const [activityForm, setActivityForm] = useState({ type: 'call', subject: '', body: '' })

  const [loading, setLoading] = useState(true)
  const [saving, setSaving] = useState(false)
  const [confirmingDelete, setConfirmingDelete] = useState(false)
  const [message, setMessage] = useState(notice)
  const [alertVariant, setAlertVariant] = useState<'success' | 'danger'>('success')
  const [errors, setErrors] = useState<Record<string, string[]>>({})

  const setTab = (next: TabKey, nextSection?: string) => {
    const nextParams = new URLSearchParams(searchParams)
    if (next === 'overview') {
      nextParams.delete('tab')
    } else {
      nextParams.set('tab', next)
    }
    if (nextSection) {
      nextParams.set('section', nextSection)
    } else {
      nextParams.delete('section')
    }
    setSearchParams(nextParams)
  }

  // Rewrite an old tab deep link to its grouped tab, keeping every other query value.
  useEffect(() => {
    if (!requestedTab || tabAliases[requestedTab] === requestedTab) {
      return
    }

    const nextParams = new URLSearchParams(searchParams)
    if (tab === 'overview') {
      nextParams.delete('tab')
      nextParams.delete('section')
    } else {
      nextParams.set('tab', tab)
      const mappedSection =
        tab === 'commercial' && ['opportunities', 'contracts'].includes(requestedTab)
          ? requestedTab
          : tab === 'engagement' &&
              ['communications', 'satisfaction', 'activities'].includes(requestedTab)
            ? requestedTab
            : null
      if (mappedSection) {
        nextParams.set('section', mappedSection)
      } else {
        nextParams.delete('section')
      }
    }
    setSearchParams(nextParams, { replace: true })
  }, [requestedTab, searchParams, setSearchParams, tab])

  // An old section deep link or a quick action lands on the right block once its data is in.
  useEffect(() => {
    if (!section || loading) {
      return
    }

    const target = document.getElementById(`account-${section}`)
    target?.scrollIntoView?.({ behavior: 'smooth', block: 'start' })
  }, [section, loading])

  const load = useCallback(async () => {
    setLoading(true)
    try {
      const detail = await getCompany(id)
      const [siteList, contactList, dealList, contractList, branchList, history, assignable] =
        await Promise.all([
          listCompanySites(id),
          listCompanyContacts(id),
          listCompanyOpportunities(id),
          listCompanyContracts(id),
          listCompanies({ parent_company_id: id }),
          getCompanyTimeline(id),
          getAssignableUsers(),
        ])

      setCompany(detail)
      setForm(formFromCompany(detail))
      setSites(siteList)
      setContacts(contactList)
      setOpportunities(dealList.data)
      setContracts(contractList.data)
      setBranches(branchList.data)
      setActivities(history.data)
      setTargets(assignable)
      setOptions(detail.can_manage_parent ? (await listCompanies()).data : [])

      // Billing comes from the mocked Finance department and is manager+ only (spec 09 §7).
      if (detail.can_view_billing) {
        const billingResponse = await getCompanyBilling(id)
        setBilling(billingResponse.data)
        setBillingMeta(billingResponse.meta)
      }
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

  const loadContacts = async (role: string, status: string) => {
    setLoading(true)
    try {
      setContacts(
        await listCompanyContacts(id, {
          role_type: role || undefined,
          status: status || undefined,
        }),
      )
    } catch {
      handleFailure(null, 'Could not load the contacts.')
    } finally {
      setLoading(false)
    }
  }

  const refreshContacts = async () => {
    const [list, detail] = await Promise.all([
      listCompanyContacts(id, {
        role_type: contactRole || undefined,
        status: contactStatus || undefined,
      }),
      getCompany(id),
    ])
    setContacts(list)
    setCompany(detail)
  }

  const onFilterContacts = (role: string, status: string) => {
    setContactRole(role)
    setContactStatus(status)
    void loadContacts(role, status)
  }

  const onSaveContact = async (event: FormEvent) => {
    event.preventDefault()
    if (!contactForm) return

    const input: ContactInput = {
      name: contactForm.name.trim(),
      position: blankToNull(contactForm.position),
      role_type: contactForm.role_type,
      email: blankToNull(contactForm.email),
      phone: blankToNull(contactForm.phone),
      mobile: blankToNull(contactForm.mobile),
      notes: blankToNull(contactForm.notes),
    }

    setSaving(true)
    try {
      // A new contact may be flagged primary; an existing one changes hands only through
      // the explicit "make primary" action.
      const { duplicates } = contactForm.id
        ? await updateContact(contactForm.id, { ...input, status: contactForm.status })
        : await createCompanyContact(id, { ...input, is_primary: contactForm.is_primary })

      const clash = duplicates.length > 0 ? ' That email is already on another contact here.' : ''

      setContactForm(null)
      await refreshContacts()
      succeed(`${contactForm.id ? 'Contact updated.' : 'Contact added.'}${clash}`)
    } catch (error) {
      handleFailure(error, 'Could not save the contact.')
    } finally {
      setSaving(false)
    }
  }

  const onMakeContactPrimary = async (contact: Contact) => {
    setSaving(true)
    try {
      await makeContactPrimary(contact.id)
      await refreshContacts()
      succeed(`${contact.name} is now the primary contact.`)
    } catch (error) {
      handleFailure(error, 'Could not set the primary contact.')
    } finally {
      setSaving(false)
    }
  }

  const onDeleteContact = async (contact: Contact) => {
    setSaving(true)
    try {
      await deleteContact(contact.id)
      await refreshContacts()
      succeed(`${contact.name} retired from this account.`)
    } catch (error) {
      handleFailure(error, 'Could not retire the contact.')
    } finally {
      setSaving(false)
    }
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
    if (!company || ownerId === company.owner_id) return
    setSaving(true)
    try {
      const updated = await assignCompany(id, ownerId)
      setCompany(updated)
      succeed('Owner updated. The new owner was notified.')

      // The assignment lands on the timeline as a system activity (spec 16 S5); a refresh miss
      // must not turn a successful handover into an error message.
      try {
        setActivities((await getCompanyTimeline(id)).data)
      } catch {
        // The account page still shows the new owner.
      }
    } catch (error) {
      handleFailure(error, 'Could not reassign this company.')
    } finally {
      setSaving(false)
    }
  }

  // --- Submodule 09: client lifecycle + contracts ---------------------------

  const refreshLifecycle = async () => {
    const [detail, history] = await Promise.all([getCompany(id), getCompanyTimeline(id)])
    setCompany(detail)
    setActivities(history.data)

    if (detail.can_view_billing) {
      const billingResponse = await getCompanyBilling(id)
      setBilling(billingResponse.data)
      setBillingMeta(billingResponse.meta)
    }
  }

  const onFilterContracts = (status: string) => {
    setContractStatus(status)
    void listCompanyContracts(id, { status: status || undefined })
      .then((list) => setContracts(list.data))
      .catch(() => handleFailure(null, 'Could not load the contracts.'))
  }

  const onTransition = async (status: CompanyStatus, reason?: string, note?: string) => {
    if (!company) return

    setSaving(true)
    try {
      // Win-back from churned has its own action so the reason is recorded (spec 09 §5).
      const updated =
        company.status === 'churned' && status === 'active'
          ? await reactivateCompany(id, note)
          : await updateCompanyLifecycle(id, { status, reason, note })

      setCompany(updated)
      setChurnForm(null)
      await refreshLifecycle()
      succeed(`Client moved to ${companyStatusMeta[status].label.toLowerCase()}.`)
    } catch (error) {
      handleFailure(error, 'Could not change the client lifecycle.')
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
      navigate('/accounts')
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
        <Link to="/accounts" className="mt-4 inline-block text-sm font-medium text-primary">
          Back to accounts
        </Link>
      </div>
    )
  }

  const statusMeta = companyStatusMeta[company.status]
  const tabs: { key: TabKey; label: string; count?: number }[] = [
    { key: 'overview', label: 'Overview' },
    {
      key: 'people',
      label: 'People & Sites',
      count: company.counts.contacts + company.counts.sites + company.counts.branches,
    },
    {
      key: 'commercial',
      label: 'Commercial',
      count: company.counts.opportunities + company.counts.contracts,
    },
    { key: 'delivery', label: 'Delivery' },
    {
      key: 'engagement',
      label: 'Engagement',
      count: company.counts.communications + company.counts.activities,
    },
  ]

  // The journey is read-only and derived (spec 16 §Account 360). A manual account skips the
  // lead steps; the next unmet step is marked current, never moved automatically.
  const hasSourceLead = company.source_lead_id !== null
  const hasSignedContract = contracts.some((contract) => contract.status === 'signed')
  const handoffAccepted = company.handoff.status === 'sent'
  // A salesrep only ever sees themselves in the target list; there is nothing to choose.
  const assignTargets = targets.filter((target) => target.id !== company.owner_id)
  const journey = [
    {
      key: 'captured',
      label: 'Lead captured',
      done: hasSourceLead,
      skipped: !hasSourceLead,
      hint: hasSourceLead ? 'From a qualified lead' : 'Created directly',
    },
    {
      key: 'qualified',
      label: 'Qualified',
      done: hasSourceLead,
      skipped: !hasSourceLead,
      hint: hasSourceLead ? 'Criteria met before conversion' : 'Skipped',
    },
    {
      key: 'converted',
      label: 'Converted',
      done: hasSourceLead,
      skipped: !hasSourceLead,
      hint: hasSourceLead ? 'Account opened' : 'Skipped',
    },
    {
      key: 'contract',
      label: 'Contract signed',
      done: hasSignedContract,
      skipped: false,
      hint: hasSignedContract ? 'Signed agreement on file' : 'Signing activates the client',
    },
    {
      key: 'delivery',
      label: 'Delivery active',
      done: handoffAccepted,
      skipped: false,
      hint: handoffAccepted
        ? company.handoff.reference
          ? `Service accepted · ${company.handoff.reference}`
          : 'Service accepted'
        : company.handoff.status === 'failed'
          ? 'Handoff failed — retry on Delivery'
          : hasSignedContract
            ? 'Not requested yet'
            : 'After the first signed contract',
    },
    {
      key: 'client',
      label: 'Client',
      done: company.is_client,
      skipped: false,
      hint: company.is_client
        ? `${statusMeta.label}${
            company.client_since ? ` since ${formatDate(company.client_since)}` : ''
          }`
        : 'Not yet a client',
    },
  ]
  const nextJourneyKey = journey.find((step) => !step.done && !step.skipped)?.key
  const journeySteps: JourneyStep[] = journey.map((step) => ({
    key: step.key,
    label: step.label,
    hint: step.hint,
    state: step.skipped
      ? 'skipped'
      : step.done
        ? 'done'
        : step.key === nextJourneyKey
          ? 'current'
          : 'pending',
  }))

  const contractColumns: DataTableColumn<Contract>[] = [
    {
      key: 'reference',
      header: 'Reference',
      className: 'font-mono text-xs text-muted-foreground',
      render: (contract) => contract.reference,
    },
    {
      key: 'title',
      header: 'Agreement',
      render: (contract) => (
        <div className="flex flex-col">
          <span className="font-medium text-foreground">{contract.title}</span>
          {contract.renewal_of && (
            <span className="font-mono text-xs text-muted-foreground">
              renews {contract.renewal_of.reference}
            </span>
          )}
          {(contract.payment_terms || contract.po_reference || contract.auto_renew) && (
            <span className="text-xs text-muted-foreground">
              {[
                contract.payment_terms,
                contract.po_reference,
                contract.auto_renew ? 'Auto-renew' : null,
              ]
                .filter(Boolean)
                .join(' · ')}
            </span>
          )}
        </div>
      ),
    },
    {
      key: 'status',
      header: 'Status',
      render: (contract) => (
        <div className="flex items-center gap-2">
          <StatusBadge tone={contractStatusMeta[contract.status].tone}>
            {contractStatusMeta[contract.status].label}
          </StatusBadge>
          {contract.is_expiring_soon && <StatusBadge tone="warm">Renewal due</StatusBadge>}
        </div>
      ),
    },
    {
      key: 'value',
      header: 'Value',
      className: 'text-right text-muted-foreground',
      headerClassName: 'text-right',
      render: (contract) => (contract.value === null ? '—' : formatCurrency(contract.value)),
    },
    {
      key: 'run_rate',
      header: 'Run rate',
      className: 'text-right',
      headerClassName: 'text-right',
      render: (contract) =>
        contract.rate_cards.length === 0 ? (
          <span className="text-xs text-muted-foreground">No rate cards</span>
        ) : (
          <div className="flex flex-col items-end">
            <span className="text-foreground">
              {formatCurrency(contract.commercial.client_monthly_value)}/mo
            </span>
            <span className="text-xs text-muted-foreground">
              {contract.rate_cards.length} {contract.rate_cards.length === 1 ? 'line' : 'lines'}
              {contract.commercial.can_view_margin &&
              contract.commercial.gross_margin_percent !== null
                ? ` · ${contract.commercial.gross_margin_percent.toFixed(1)}% margin`
                : ''}
            </span>
          </div>
        ),
    },
    {
      key: 'term',
      header: 'Term',
      className: 'text-muted-foreground',
      render: (contract) => (
        <div className="flex flex-col">
          <span>
            {contract.start_date ? formatDate(contract.start_date) : '—'} →{' '}
            {contract.end_date ? formatDate(contract.end_date) : '—'}
          </span>
          <span className="text-xs">{formatExpiry(contract.days_until_expiry)}</span>
        </div>
      ),
    },
    {
      key: 'actions',
      header: 'Actions',
      headerClassName: 'text-right',
      className: 'text-right',
      render: (contract) => (
        <div className="flex items-center justify-end gap-2">
          <Link to={`/contracts/${contract.id}`} className="rounded-md border border-border px-2.5 py-1 text-xs font-medium text-foreground hover:bg-muted">
            View contract
          </Link>
          <Link
            to={`/communications/new?subject_type=contract&subject_id=${contract.id}&subject_label=${encodeURIComponent(contract.title)}`}
            title="Log a communication against this contract"
            className="rounded-md border border-border px-2.5 py-1 text-xs font-medium text-foreground hover:bg-muted"
          >
            Log
          </Link>
          <Link
            to={`/follow-ups/new?subject_type=contract&subject_id=${contract.id}&subject_label=${encodeURIComponent(contract.title)}`}
            title="Put a follow-up on this contract"
            className="rounded-md border border-border px-2.5 py-1 text-xs font-medium text-foreground hover:bg-muted"
          >
            Follow-up
          </Link>
        </div>
      ),
    },
  ]

  const opportunityColumns: DataTableColumn<Opportunity>[] = [
    {
      key: 'reference',
      header: 'Reference',
      className: 'font-mono text-xs text-muted-foreground',
      render: (deal) => deal.reference,
    },
    {
      key: 'name',
      header: 'Deal',
      className: 'font-medium text-foreground',
      render: (deal) => (
        <Link
          to={`/opportunities/${deal.id}`}
          className="font-medium text-foreground hover:underline"
        >
          {deal.name}
        </Link>
      ),
    },
    {
      key: 'stage',
      header: 'Stage',
      render: (deal) => (
        <StatusBadge tone={opportunityStageMeta[deal.stage].tone}>
          {opportunityStageMeta[deal.stage].label}
        </StatusBadge>
      ),
    },
    {
      key: 'amount',
      header: 'Amount',
      className: 'text-right text-muted-foreground',
      headerClassName: 'text-right',
      render: (deal) => (deal.amount === null ? '—' : formatCurrency(deal.amount)),
    },
    {
      key: 'probability',
      header: 'Probability',
      className: 'text-right text-muted-foreground',
      headerClassName: 'text-right',
      render: (deal) => `${deal.probability ?? 0}%`,
    },
    {
      key: 'close_date',
      header: 'Expected close',
      className: 'text-muted-foreground',
      render: (deal) =>
        deal.actual_close_date
          ? formatDate(deal.actual_close_date)
          : deal.expected_close_date
            ? formatDate(deal.expected_close_date)
            : '—',
    },
    {
      key: 'owner',
      header: 'Owner',
      className: 'text-muted-foreground',
      render: (deal) => deal.owner?.name ?? '—',
    },
    {
      key: 'source_lead',
      header: 'Source lead',
      className: 'text-right',
      headerClassName: 'text-right',
      render: (deal) =>
        deal.lead ? (
          <Link to={`/leads/${deal.lead.id}`} className="font-mono text-xs text-primary">
            {deal.lead.reference}
          </Link>
        ) : (
          <span className="text-muted-foreground">—</span>
        ),
    },
  ]

  const contactColumns: DataTableColumn<Contact>[] = [
    {
      key: 'name',
      header: 'Contact',
      render: (contact) => (
        <div className="flex items-center gap-2">
          <span className="font-medium text-foreground">{contact.name}</span>
          {contact.is_primary && <StatusBadge tone="people">Primary</StatusBadge>}
        </div>
      ),
    },
    {
      key: 'position',
      header: 'Position',
      className: 'text-muted-foreground',
      render: (contact) => contact.position ?? '—',
    },
    {
      key: 'role',
      header: 'Role',
      render: (contact) => (
        <StatusBadge tone={contactRoleMeta[contact.role_type].tone}>
          {contactRoleMeta[contact.role_type].label}
        </StatusBadge>
      ),
    },
    {
      key: 'email',
      header: 'Email',
      className: 'text-muted-foreground',
      render: (contact) => contact.email ?? '—',
    },
    {
      key: 'phone',
      header: 'Phone',
      className: 'text-muted-foreground',
      render: (contact) => contact.mobile ?? contact.phone ?? '—',
    },
    {
      key: 'status',
      header: 'Status',
      render: (contact) => (
        <StatusBadge tone={contactStatusMeta[contact.status].tone}>
          {contactStatusMeta[contact.status].label}
        </StatusBadge>
      ),
    },
    {
      key: 'actions',
      header: '',
      headerClassName: 'text-right',
      className: 'text-right',
      render: (contact) => (
        <div className="flex items-center justify-end gap-2">
          {contact.can_make_primary && (
            <button
              type="button"
              disabled={saving}
              onClick={() => void onMakeContactPrimary(contact)}
              className="rounded-md border border-border px-2.5 py-1 text-xs font-medium text-foreground hover:bg-muted disabled:opacity-60"
            >
              Make primary
            </button>
          )}
          {contact.can_update && (
            <button
              type="button"
              onClick={() => setContactForm(formFromContact(contact))}
              className="rounded-md border border-border px-2.5 py-1 text-xs font-medium text-foreground hover:bg-muted"
            >
              Edit
            </button>
          )}
          {contact.can_delete && (
            <button
              type="button"
              disabled={saving}
              onClick={() => void onDeleteContact(contact)}
              className="rounded-md px-2.5 py-1 text-xs font-medium text-destructive"
            >
              Retire
            </button>
          )}
        </div>
      ),
    },
  ]

  return (
    <div>
      <Link
        to="/accounts"
        className="mb-3 inline-flex items-center gap-2 text-sm text-muted-foreground hover:text-foreground"
      >
        <ArrowLeft className="h-4 w-4" aria-hidden="true" />
        Back to accounts
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
            {company.can_update && (
              <Link
                to={`/follow-ups/new?subject_type=company&subject_id=${company.id}`}
                className="inline-flex items-center gap-2 rounded-md border border-border bg-card px-3 py-2 text-sm font-medium text-foreground hover:bg-muted"
              >
                <CalendarClock className="h-4 w-4" aria-hidden="true" />
                Follow-up
              </Link>
            )}
          </div>
        }
      />

      <dl className="mb-4 grid grid-cols-2 gap-4 rounded-lg border border-border bg-card p-4 md:grid-cols-4">
        <HeaderDetail label="Owner" value={company.owner?.name ?? '—'} />
        <HeaderDetail label="Primary contact" value={company.primary_contact?.name ?? '—'} />
        <HeaderDetail
          label="Client since"
          value={company.client_since ? formatDate(company.client_since) : '—'}
        />
        <HeaderDetail
          label="Source"
          value={company.source_lead_id ? 'Converted lead' : 'Created directly'}
        />
      </dl>

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

      {company.handoff.status === 'failed' && (
        <div className="mb-4">
          <AlertItem variant="danger" title="Service handoff failed">
            <p className="text-xs">{company.handoff.error}</p>
            <p className="mt-1 text-xs">
              The signed contract stands — the client waits in onboarding until Service acknowledges
              the handoff.
            </p>
            <p className="mt-1 text-xs text-muted-foreground">Tracked status only; CRM does not retry handoffs.</p>
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
              aria-pressed={active}
              onClick={() => setTab(entry.key)}
              className={`inline-flex items-center gap-2 rounded-md px-3 py-1.5 text-sm font-medium transition-colors ${
                active ? 'bg-secondary text-primary' : 'text-muted-foreground hover:bg-muted'
              }`}
            >
              {entry.label}
              {entry.count !== undefined && (
                <span
                  className={`rounded px-1.5 text-xs ${
                    active ? 'bg-primary/10 text-primary' : 'bg-muted text-muted-foreground'
                  }`}
                >
                  {entry.count}
                </span>
              )}
            </button>
          )
        })}
      </div>

      {tab === 'overview' && form && (
        <div className="grid grid-cols-1 gap-6 lg:grid-cols-3">
          <div className="lg:col-span-3">
            <FormCard
              title="Journey"
              description="Derived from the source lead, signed contracts and the mocked Service handoff. Nothing moves automatically."
            >
              <JourneyStepper steps={journeySteps} />
            </FormCard>
          </div>

          <div className="lg:col-span-3">
            <FormCard
              title="Account health"
              description="Computed on read from the account's records and the read-only department feeds. It never changes the lifecycle."
            >
              {company.health ? (
                <AccountHealthPanel health={company.health} />
              ) : (
                <p className="text-sm text-muted-foreground">
                  Health is not available for this account.
                </p>
              )}
            </FormCard>
          </div>

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
            <FormCard
              title="Client lifecycle"
              description={
                company.can_manage_lifecycle
                  ? 'Every move is recorded on the account timeline.'
                  : 'Only a manager or admin can move an account along the lifecycle.'
              }
            >
              <div className="flex items-center justify-between text-sm">
                <span className="text-muted-foreground">Status</span>
                <StatusBadge tone={statusMeta.tone}>{statusMeta.label}</StatusBadge>
              </div>

              <div className="mt-3 flex items-center justify-between text-sm">
                <span className="text-muted-foreground">Client since</span>
                <span className="text-foreground">
                  {company.client_since ? formatDate(company.client_since) : '—'}
                </span>
              </div>

              {company.handoff.status === 'sent' && (
                <div className="mt-3 flex items-center justify-between gap-3 text-sm">
                  <span className="text-muted-foreground">Service handoff</span>
                  <span className="text-right font-mono text-xs text-foreground">
                    {company.handoff.reference ?? 'accepted'}
                  </span>
                </div>
              )}

              {company.churn_reason && (
                <div className="mt-3 flex items-center justify-between gap-3 text-sm">
                  <span className="text-muted-foreground">Churn reason</span>
                  <span className="text-right text-foreground">{company.churn_reason}</span>
                </div>
              )}

              {company.churned_at && (
                <div className="mt-3 flex items-center justify-between text-sm">
                  <span className="text-muted-foreground">Churned</span>
                  <span className="text-foreground">{formatDate(company.churned_at)}</span>
                </div>
              )}

              {company.can_manage_lifecycle && (
                <div className="mt-4 border-t border-border pt-4">
                  {churnForm ? (
                    <form
                      onSubmit={(event) => {
                        event.preventDefault()
                        void onTransition('churned', churnForm.reason, churnForm.note)
                      }}
                      className="flex flex-col gap-3"
                    >
                      <Field label="Why did the client leave?" error={errors.reason}>
                        <input
                          value={churnForm.reason}
                          onChange={(event) =>
                            setChurnForm(
                              (previous) => previous && { ...previous, reason: event.target.value },
                            )
                          }
                          className={inputClass}
                        />
                      </Field>

                      <Field label="Note (optional)" error={errors.note}>
                        <input
                          value={churnForm.note}
                          onChange={(event) =>
                            setChurnForm(
                              (previous) => previous && { ...previous, note: event.target.value },
                            )
                          }
                          className={inputClass}
                        />
                      </Field>

                      <div className="flex items-center gap-2">
                        <button
                          type="submit"
                          disabled={saving || churnForm.reason.trim() === ''}
                          className="rounded-md bg-destructive px-3 py-1.5 text-xs font-medium text-primary-foreground disabled:opacity-60"
                        >
                          {saving ? 'Churning…' : 'Churn client'}
                        </button>
                        <button
                          type="button"
                          onClick={() => setChurnForm(null)}
                          className="rounded-md border border-border px-3 py-1.5 text-xs font-medium text-foreground hover:bg-muted"
                        >
                          Cancel
                        </button>
                      </div>
                    </form>
                  ) : (
                    <div className="flex flex-wrap gap-2">
                      {company.allowed_status_transitions.map((next) => {
                        const isWinBack = company.status === 'churned' && next === 'active'
                        return (
                          <button
                            key={next}
                            type="button"
                            disabled={saving}
                            onClick={() => {
                              if (next === 'churned') {
                                setChurnForm({ reason: '', note: '' })
                                return
                              }
                              void onTransition(next)
                            }}
                            className="rounded-md border border-border px-2.5 py-1 text-xs font-medium text-foreground hover:bg-muted disabled:opacity-60"
                          >
                            {isWinBack ? 'Reactivate' : `Move to ${companyStatusMeta[next].label}`}
                          </button>
                        )
                      })}
                    </div>
                  )}
                </div>
              )}
            </FormCard>

            <FormCard title="Ownership">
              <div className="flex items-center justify-between">
                <span className="text-sm text-muted-foreground">Account owner</span>
                <span className="text-sm font-medium text-foreground">
                  {company.owner?.name ?? '—'}
                </span>
              </div>

              {company.can_assign && assignTargets.length > 0 && (
                <div className="mt-3">
                  <Field label="Reassign to">
                    <select
                      value=""
                      disabled={saving}
                      onChange={(event) => {
                        const next = Number(event.target.value)
                        if (next) void onAssign(next)
                      }}
                      className={inputClass}
                    >
                      <option value="">Choose a new owner…</option>
                      {assignTargets.map((target) => (
                        <option key={target.id} value={target.id}>
                          {target.name}
                        </option>
                      ))}
                    </select>
                  </Field>
                  <p className="mt-2 text-xs text-muted-foreground">
                    The new owner is notified and the move is logged on the timeline.
                  </p>
                </div>
              )}
            </FormCard>

            <FormCard title="Quick actions">
              <div className="flex flex-wrap gap-2">
                {company.can_update && (
                  <button
                    type="button"
                    onClick={() => {
                      setTab('people', 'contacts')
                      setContactForm({ ...emptyContact })
                    }}
                    className="rounded-md border border-border px-3 py-1.5 text-xs font-medium text-foreground hover:bg-muted"
                  >
                    Add contact
                  </button>
                )}
                <Link
                  to={`/opportunities/new?company_id=${id}`}
                  className="rounded-md border border-border px-3 py-1.5 text-xs font-medium text-foreground hover:bg-muted"
                >
                  Add deal
                </Link>
                {company.can_update && (
                  <Link
                    to={`/communications/new?subject_type=company&subject_id=${id}`}
                    className="rounded-md border border-border px-3 py-1.5 text-xs font-medium text-foreground hover:bg-muted"
                  >
                    Log communication
                  </Link>
                )}
                {company.can_update && (
                  <Link
                    to={`/follow-ups/new?subject_type=company&subject_id=${id}`}
                    className="rounded-md border border-border px-3 py-1.5 text-xs font-medium text-foreground hover:bg-muted"
                  >
                    Follow-up
                  </Link>
                )}
              </div>
            </FormCard>

            <FormCard title="Record">
              <dl className="flex flex-col gap-3 text-sm">
                <Detail label="Reference" value={company.reference} />
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

      {tab === 'people' && (
        <div id="account-sites" className="grid grid-cols-1 gap-6 lg:grid-cols-3">
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

      {tab === 'people' && (
        <div id="account-contacts" className="mt-6 grid grid-cols-1 gap-6 lg:grid-cols-3">
          <div className="flex flex-col gap-6 lg:col-span-2">
            <div className="flex flex-wrap items-center gap-2">
              <select
                value={contactRole}
                onChange={(event) => onFilterContacts(event.target.value, contactStatus)}
                className="rounded-md border border-input bg-card px-3 py-2 text-sm text-foreground outline-none focus:ring-2 focus:ring-ring"
              >
                <option value="">All roles</option>
                {(Object.keys(contactRoleMeta) as ContactRoleType[]).map((value) => (
                  <option key={value} value={value}>
                    {contactRoleMeta[value].label}
                  </option>
                ))}
              </select>

              <select
                value={contactStatus}
                onChange={(event) => onFilterContacts(contactRole, event.target.value)}
                className="rounded-md border border-input bg-card px-3 py-2 text-sm text-foreground outline-none focus:ring-2 focus:ring-ring"
              >
                <option value="">All statuses</option>
                {(Object.keys(contactStatusMeta) as ContactStatus[]).map((value) => (
                  <option key={value} value={value}>
                    {contactStatusMeta[value].label}
                  </option>
                ))}
              </select>
            </div>

            <DataTable
              columns={contactColumns}
              rows={contacts}
              rowKey={(contact) => contact.id}
              loading={loading}
              loadingLabel="Loading contacts…"
              empty={
                <EmptyState
                  title="No contacts yet"
                  description="Add the people we work with here — start with the hiring manager or HR."
                />
              }
            />
          </div>

          <div className="flex flex-col gap-6">
            {company.can_update ? (
              <FormCard
                title={contactForm?.id ? 'Edit contact' : 'Add contact'}
                description="Decision-makers (hiring, HR, procurement, finance) are who staffing runs through."
              >
                {contactForm ? (
                  <form onSubmit={onSaveContact} className="flex flex-col gap-3">
                    <Field label="Name" error={errors.name}>
                      <input
                        value={contactForm.name}
                        onChange={(event) =>
                          setContactForm(
                            (previous) => previous && { ...previous, name: event.target.value },
                          )
                        }
                        className={inputClass}
                      />
                    </Field>

                    <Field label="Position" error={errors.position}>
                      <input
                        value={contactForm.position}
                        onChange={(event) =>
                          setContactForm(
                            (previous) => previous && { ...previous, position: event.target.value },
                          )
                        }
                        placeholder="Talent Acquisition Lead"
                        className={inputClass}
                      />
                    </Field>

                    <Field label="Role" error={errors.role_type}>
                      <select
                        value={contactForm.role_type}
                        onChange={(event) =>
                          setContactForm(
                            (previous) =>
                              previous && { ...previous, role_type: event.target.value },
                          )
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

                    <Field label="Email" error={errors.email}>
                      <input
                        type="email"
                        value={contactForm.email}
                        onChange={(event) =>
                          setContactForm(
                            (previous) => previous && { ...previous, email: event.target.value },
                          )
                        }
                        className={inputClass}
                      />
                    </Field>

                    <Field label="Phone" error={errors.phone}>
                      <input
                        value={contactForm.phone}
                        onChange={(event) =>
                          setContactForm(
                            (previous) => previous && { ...previous, phone: event.target.value },
                          )
                        }
                        placeholder="+63 2 8712 4455"
                        className={inputClass}
                      />
                    </Field>

                    <Field label="Mobile" error={errors.mobile}>
                      <input
                        value={contactForm.mobile}
                        onChange={(event) =>
                          setContactForm(
                            (previous) => previous && { ...previous, mobile: event.target.value },
                          )
                        }
                        placeholder="0917 555 2210"
                        className={inputClass}
                      />
                    </Field>

                    {contactForm.id ? (
                      <Field label="Status" error={errors.status}>
                        <select
                          value={contactForm.status}
                          onChange={(event) =>
                            setContactForm(
                              (previous) => previous && { ...previous, status: event.target.value },
                            )
                          }
                          className={inputClass}
                        >
                          {(Object.keys(contactStatusMeta) as ContactStatus[]).map((value) => (
                            <option key={value} value={value}>
                              {contactStatusMeta[value].label}
                            </option>
                          ))}
                        </select>
                      </Field>
                    ) : (
                      <label className="flex items-center gap-2 text-sm text-foreground">
                        <input
                          type="checkbox"
                          checked={contactForm.is_primary}
                          onChange={(event) =>
                            setContactForm(
                              (previous) =>
                                previous && {
                                  ...previous,
                                  is_primary: event.target.checked,
                                },
                            )
                          }
                          className="h-4 w-4 rounded border-input"
                        />
                        Primary contact
                      </label>
                    )}

                    <Field label="Notes" error={errors.notes}>
                      <textarea
                        rows={2}
                        value={contactForm.notes}
                        onChange={(event) =>
                          setContactForm(
                            (previous) => previous && { ...previous, notes: event.target.value },
                          )
                        }
                        className={inputClass}
                      />
                    </Field>

                    <div className="flex items-center gap-2">
                      <button
                        type="submit"
                        disabled={saving || contactForm.name.trim() === ''}
                        className="rounded-md bg-primary px-4 py-2 text-sm font-medium text-primary-foreground disabled:opacity-60"
                      >
                        {saving ? 'Saving…' : contactForm.id ? 'Save contact' : 'Add contact'}
                      </button>
                      <button
                        type="button"
                        onClick={() => setContactForm(null)}
                        className="rounded-md border border-border px-4 py-2 text-sm font-medium text-foreground hover:bg-muted"
                      >
                        Cancel
                      </button>
                    </div>
                  </form>
                ) : (
                  <button
                    type="button"
                    onClick={() => setContactForm({ ...emptyContact })}
                    className="rounded-md bg-primary px-4 py-2 text-sm font-medium text-primary-foreground"
                  >
                    Add a contact
                  </button>
                )}
              </FormCard>
            ) : (
              <FormCard title="Contacts">
                <p className="text-sm text-muted-foreground">
                  You need to own this account (or be its manager) to maintain its contacts.
                </p>
              </FormCard>
            )}
          </div>
        </div>
      )}

      {tab === 'people' && (
        <div id="account-branches" className="mt-6">
          <FormCard
            title="Branch"
            description="Branches are separate records — they inherit nothing."
          >
            <div className="flex items-center justify-between text-sm">
              <span className="text-muted-foreground">Parent</span>
              {company.parent ? (
                <Link to={`/accounts/${company.parent.id}`} className="font-medium text-primary">
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
                    <Link to={`/accounts/${branch.id}`} className="text-primary">
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
        </div>
      )}

      {tab === 'commercial' && (
        <div className="space-y-6">
          <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
            <FormCard title="Deals" description="Every deal on this account, open or closed.">
              <div className="flex items-center justify-between text-sm">
                <span className="text-muted-foreground">Deals</span>
                <span className="text-foreground">{company.counts.opportunities}</span>
              </div>
              <div className="mt-3 flex items-center justify-between text-sm">
                <span className="text-muted-foreground">Open</span>
                <span className="text-foreground">
                  {opportunities.filter((deal) => deal.is_open).length}
                </span>
              </div>
              <div className="mt-3 flex items-center justify-between text-sm">
                <span className="text-muted-foreground">Open value</span>
                <span className="text-cost">
                  {formatCurrency(
                    opportunities
                      .filter((deal) => deal.is_open)
                      .reduce((total, deal) => total + Number(deal.amount ?? 0), 0),
                  )}
                </span>
              </div>
              <Link
                to="/pipeline"
                className="mt-4 inline-flex items-center gap-2 text-sm font-medium text-primary hover:underline"
              >
                <SquareKanban className="h-4 w-4" aria-hidden="true" />
                View the pipeline board
              </Link>
            </FormCard>

            <FormCard title="Contract summary">
              <div className="flex items-center justify-between text-sm">
                <span className="text-muted-foreground">Contracts</span>
                <span className="text-foreground">{company.counts.contracts}</span>
              </div>
              <div className="mt-3 flex items-center justify-between text-sm">
                <span className="text-muted-foreground">Active</span>
                <span className="text-foreground">
                  {contracts.filter((contract) => contract.is_active).length}
                </span>
              </div>
              <div className="mt-3 flex items-center justify-between text-sm">
                <span className="text-muted-foreground">Renewal due</span>
                <span className="text-foreground">
                  {contracts.filter((contract) => contract.is_expiring_soon).length}
                </span>
              </div>
            </FormCard>
          </div>

          {!company.can_view_billing && company.billing_risk?.has_overdue && (
            <AlertItem variant="warning" title="Finance follow-up needed">
              {company.billing_risk.message ??
                'Finance reports an overdue balance on this account. Follow up with Finance.'}
            </AlertItem>
          )}

          {company.can_view_billing && (
            <div id="account-billing">
              <CompanyBillingPanel billing={billing} meta={billingMeta} />
            </div>
          )}

          <section id="account-opportunities">
            <div className="mb-3 flex flex-wrap items-center justify-between gap-3">
              <div>
                <h2 className="text-lg font-semibold text-foreground">Deals</h2>
                <p className="text-sm text-muted-foreground">
                  Every deal on this account, open or closed.
                </p>
              </div>
              <Link
                to={`/opportunities/new?company_id=${id}`}
                className="inline-flex items-center gap-2 rounded-md bg-primary px-3 py-2 text-sm font-medium text-primary-foreground"
              >
                <Plus className="h-4 w-4" aria-hidden="true" />
                Add deal
              </Link>
            </div>

            <DataTable
              columns={opportunityColumns}
              rows={opportunities}
              rowKey={(deal) => deal.id}
              loading={loading}
              loadingLabel="Loading opportunities…"
              empty={
                <EmptyState
                  title="No deals yet"
                  description="Open a deal here, or convert a qualified lead into this account."
                  action={
                    <Link
                      to={`/opportunities/new?company_id=${id}`}
                      className="inline-flex items-center gap-2 rounded-md bg-primary px-3 py-2 text-sm font-medium text-primary-foreground"
                    >
                      <Plus className="h-4 w-4" aria-hidden="true" />
                      Add deal
                    </Link>
                  }
                />
              }
            />
          </section>

          <section id="account-contracts">
            <div className="mb-3 flex flex-wrap items-center justify-between gap-3">
              <div>
                <h2 className="text-lg font-semibold text-foreground">Contracts</h2>
                <p className="text-sm text-muted-foreground">
                  Read-only contract projection from Contract Management.
                </p>
                {contracts[0] && (
                  <p className="text-xs text-muted-foreground">
                    Source: {contracts[0].source ?? 'Contract Management (mocked, read-only)'} ·
                    As of {contracts[0].as_of ? formatDate(contracts[0].as_of) : '—'}
                  </p>
                )}
              </div>
              <div className="flex flex-wrap items-center gap-2">
                <label className="text-xs font-medium text-muted-foreground">
                  Status
                  <select
                    value={contractStatus}
                    onChange={(event) => onFilterContracts(event.target.value)}
                    className="ml-2 rounded-md border border-input bg-card px-2 py-1 text-xs text-foreground outline-none focus:ring-2 focus:ring-ring"
                  >
                    <option value="">All</option>
                    {(Object.keys(contractStatusMeta) as ContractStatus[]).map((status) => (
                      <option key={status} value={status}>
                        {contractStatusMeta[status].label}
                      </option>
                    ))}
                  </select>
                </label>
              </div>
            </div>
            <DataTable
              columns={contractColumns}
              rows={contracts}
              rowKey={(contract) => contract.id}
              loading={loading}
              loadingLabel="Loading contracts…"
              empty={
                <EmptyState
                  title="No contracts yet"
                  description="Contracts are supplied by Contract Management and cannot be changed in CRM."
                />
              }
            />
          </section>
        </div>
      )}
      {tab === 'delivery' && (
        <CompanyDeliveryPanel
          companyId={company.id}
          refreshKey={`${company.handoff.status ?? 'none'}:${company.handoff.requested_at ?? ''}`}
        />
      )}

      {tab === 'engagement' && (
        <div id="account-communications">
          <FormCard
            title="Communication history"
            description="Every call, email, meeting and letter logged against this account. A logged entry also lands on the Activities timeline."
          >
            <CommunicationList
              subjectType="company"
              subjectId={company.id}
              canLog={company.can_update}
            />
          </FormCard>
        </div>
      )}

      {tab === 'engagement' && (
        <div id="account-satisfaction" className="mt-6">
          <CompanySatisfactionPanel companyId={company.id} canSend={company.can_update} />
        </div>
      )}

      {tab === 'engagement' && (
        <div id="account-activities" className="mt-6 grid grid-cols-1 gap-6 lg:grid-cols-3">
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
                          {activity.is_follow_up && activity.due && (
                            <p className="mt-1 flex flex-wrap items-center gap-1.5">
                              <StatusBadge tone={followUpDueMeta[activity.due.state].tone}>
                                {followUpDueMeta[activity.due.state].label}
                              </StatusBadge>
                              <span className="text-xs text-muted-foreground">
                                Due {formatDateTime(activity.due_at)}
                              </span>
                            </p>
                          )}
                          <p className="mt-0.5 text-xs text-muted-foreground">
                            {activity.owner?.name ? `${activity.owner.name} · ` : ''}
                            {activity.is_follow_up
                              ? `Added ${formatDateTime(activity.created_at)}`
                              : formatDateTime(activity.occurred_at ?? activity.created_at)}
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

function HeaderDetail({ label, value }: { label: string; value: string }) {
  return (
    <div>
      <dt className="text-xs text-muted-foreground">{label}</dt>
      <dd className="mt-0.5 text-sm font-medium text-foreground">{value}</dd>
    </div>
  )
}
