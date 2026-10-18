export type Role = 'salesrep' | 'manager' | 'admin' | 'superadmin'

export type LeadStatus =
  'new' | 'contacted' | 'qualified' | 'converted' | 'disqualified' | 'nurture' | 'archived'

export type LeadRating = 'hot' | 'warm' | 'cold'

export type LeadSource =
  | 'walk_in'
  | 'email'
  | 'phone'
  | 'referral'
  | 'website'
  | 'outbound'
  | 'event'
  | 'partner'
  | 'other'

export type Urgency = 'low' | 'medium' | 'high'

export type DisqualificationReason =
  'no_budget' | 'no_need' | 'competitor' | 'unresponsive' | 'bad_fit' | 'duplicate' | 'other'

export type ActivityType = 'call' | 'email' | 'meeting' | 'walk_in' | 'task' | 'note' | 'system'

export type ActivityStatus = 'open' | 'done' | 'cancelled'

export type ActivityCategory = 'system' | 'activity'

export type AgingLevel = 'stale' | 'at_risk' | 'archive'

export interface Lead {
  id: string
  reference: string
  company_name: string
  contact_name: string
  contact_email: string | null
  contact_phone: string | null
  contact_position: string | null
  source: LeadSource
  source_detail: string | null
  industry: string | null
  location: string | null
  service_interest: string | null
  estimated_headcount: number | null
  needed_roles: string | null
  target_start_date: string | null
  authority_confirmed: boolean
  work_site: string | null
  urgency: Urgency | null
  rating: LeadRating | null
  score: number | null
  qualification_notes: string | null
  disqualification_reason: DisqualificationReason | null
  status: LeadStatus
  status_changed_at: string | null
  last_activity_at: string | null
  notes: string | null
  owner: { id: number; name: string } | null
  owner_id: number | null
  can_claim: boolean
  can_reassign: boolean
  can_update: boolean
  allowed_transitions: LeadStatus[]
  is_archived: boolean
  aging: AgingLevel | null
  days_since_activity: number
  budget_rate?: string
  missing_criteria: string[]
  meets_criteria: boolean
  can_qualify: boolean
  can_convert: boolean
  is_converted: boolean
  converted_company_id: string | null
  converted_opportunity_id: string | null
  created_at: string
  updated_at: string
}

export interface AssignmentTarget {
  id: number
  name: string
  team_id: string | null
  open_leads_count?: number
}

export interface Activity {
  id: string
  category: ActivityCategory
  type: ActivityType
  subject: string
  body: string | null
  status: ActivityStatus
  occurred_at: string | null
  owner: { id: number; name: string } | null
  created_at: string
}

export interface LeadTimeline {
  data: Activity[]
  meta: {
    status: LeadStatus
    status_changed_at: string | null
    last_activity_at: string | null
    aging: AgingLevel | null
    days_since_activity: number
  }
}

export interface LeadSummary {
  all: number
  mine: number
  unassigned: number
  team: number
  archived: number
}

// --- Submodule 06: companies / accounts ------------------------------------

export type CompanyStatus = 'prospect' | 'onboarding' | 'active' | 'at_risk' | 'dormant' | 'churned'

export type CompanyTier = 'a' | 'b' | 'c'

export type CompanySize = 'micro' | 'small' | 'medium' | 'large' | 'enterprise'

export interface CompanyCounts {
  sites: number
  branches: number
  contacts: number
  opportunities: number
  contracts: number
  activities: number
  communications: number
}

export interface CompanyParentRef {
  id: string
  reference: string
  name: string
}

export interface Company {
  id: string
  reference: string
  name: string
  trade_name: string | null
  industry: string | null
  size: CompanySize | null
  website: string | null
  description: string | null
  status: CompanyStatus
  is_client: boolean
  tier: CompanyTier | null
  client_since: string | null
  churn_reason: string | null
  churned_at: string | null
  handoff: CompanyHandoff
  tags: string[]
  parent_company_id: string | null
  parent: CompanyParentRef | null
  source_lead_id: string | null
  owner: { id: number; name: string } | null
  owner_id: number
  counts: CompanyCounts
  allowed_status_transitions: CompanyStatus[]
  can_update: boolean
  can_manage_sites: boolean
  can_manage_parent: boolean
  can_assign: boolean
  can_delete: boolean
  can_manage_lifecycle: boolean
  can_view_billing: boolean
  can_upload_contract: boolean
  created_at: string
  updated_at: string
}

export interface CompanySite {
  id: string
  company_id: string
  label: string
  street_address: string | null
  barangay: string | null
  city_municipality: string | null
  province: string | null
  region: string | null
  postal_code: string | null
  is_primary: boolean
  full_address: string
  created_at: string
  updated_at: string
}

export interface CompanyTimeline {
  data: Activity[]
  meta: { total: number }
}

// --- Submodule 07: contacts -------------------------------------------------

export type ContactRoleType =
  'hiring_manager' | 'hr' | 'procurement' | 'finance' | 'executive' | 'other'

export type ContactStatus = 'active' | 'inactive'

export interface ContactCompanyRef {
  id: string
  reference: string
  name: string
  status: CompanyStatus
}

export interface Contact {
  id: string
  company_id: string
  company?: ContactCompanyRef
  name: string
  position: string | null
  role_type: ContactRoleType
  is_decision_maker: boolean
  email: string | null
  phone: string | null
  mobile: string | null
  is_primary: boolean
  status: ContactStatus
  notes: string | null
  can_update: boolean
  can_make_primary: boolean
  can_delete: boolean
  created_at: string
  updated_at: string
}

/** Advisories returned alongside a save — never a hard failure (spec 07 §4). */
export interface DuplicateContact {
  id: string
  name: string
  email: string | null
  role_type: ContactRoleType
  status: ContactStatus
}

// --- Submodule 08: opportunities + lead→client conversion -------------------

export type OpportunityStage =
  | 'new_inquiry'
  | 'qualified'
  | 'discovery'
  | 'proposal_sent'
  | 'negotiation'
  | 'won'
  | 'lost'
  | 'on_hold'

export type LostReason = 'price' | 'competitor' | 'no_budget' | 'timing' | 'no_decision' | 'other'

export interface OpportunityCompanyRef {
  id: string
  reference: string
  name: string
}

export interface OpportunityLeadRef {
  id: string
  reference: string
}

/** A deal on a company (spec 08). Opened by conversion or directly; moved by module 02. */
export interface Opportunity {
  id: string
  reference: string
  company_id: string
  company?: OpportunityCompanyRef & { status?: CompanyStatus }
  lead_id: string | null
  lead?: OpportunityLeadRef | null
  name: string
  stage: OpportunityStage
  probability: number | null
  amount: string | null
  currency: string
  expected_close_date: string | null
  actual_close_date: string | null
  next_step: string | null
  source: LeadSource | null
  primary_contact_id: string | null
  primary_contact?: { id: string; name: string; role_type: ContactRoleType } | null
  owner: { id: number; name: string } | null
  owner_id: number
  lost_reason: LostReason | null
  is_open: boolean
  is_won: boolean
  is_lost: boolean
  /** Pipeline affordances (module 02). */
  stage_changed_at: string | null
  last_activity_at: string | null
  days_in_stage: number
  days_since_activity: number
  is_stale: boolean
  weighted_value: number
  /** Legal target stages — any other; terminal deals reopen only (ADR-0015 §1). */
  allowed_transitions: OpportunityStage[]
  contracts?: { id: string; reference: string; title: string; status: ContractStatus }[]
  can_update: boolean
  can_change_stage: boolean
  can_set_amount: boolean
  can_assign: boolean
  can_delete: boolean
  can_restore: boolean
  created_at: string
  updated_at: string
}

export interface CompanyOpportunities {
  data: Opportunity[]
  meta: { total: number; open: number }
}

// --- Module 02: opportunity pipeline ---------------------------------------

export interface OpportunitySummary {
  all: number
  open: number
  won: number
  lost: number
}

/** The deal list (`GET /opportunities`) carries the pagination meta plus a summary. */
export type OpportunitiesPage = Paginated<Opportunity> & { summary: OpportunitySummary }

export interface PipelineColumn {
  stage: OpportunityStage
  count: number
  value: number
  weighted_value: number
  deals: Opportunity[]
}

export interface PipelineMeta {
  open_count: number
  open_value: number
  weighted_value: number
  won_count: number
  won_value: number
  lost_count: number
  lost_value: number
  win_rate: number | null
  average_deal: number | null
  stale_count: number
  by_stage: { stage: OpportunityStage; count: number; value: number; weighted_value: number }[]
}

export interface PipelineBoard {
  data: { columns: PipelineColumn[]; closed: Opportunity[] }
  meta: PipelineMeta
}

export interface OpportunityInput {
  name: string
  amount?: number | null
  probability?: number | null
  expected_close_date?: string | null
  next_step?: string | null
  source?: LeadSource | null
  primary_contact_id?: string | null
}

export interface OpportunityStageMove {
  stage: OpportunityStage
  lost_reason?: LostReason | null
  probability?: number | null
  note?: string | null
}

export interface OpportunityTimeline {
  data: Activity[]
  meta: {
    stage: OpportunityStage
    stage_changed_at: string | null
    last_activity_at: string | null
    days_in_stage: number
    is_stale: boolean
  }
}

/** A company the lead may already belong to (advisory, spec 08 §3). */
export interface ConversionDuplicate {
  id: string
  reference: string
  name: string
  trade_name: string | null
  status: CompanyStatus
  owner: { id: number; name: string } | null
  matched_on: ('name' | 'email' | 'phone')[]
}

export interface ConversionContactInput {
  name: string
  position: string | null
  role_type: ContactRoleType
  email: string | null
  phone: string | null
  mobile: string | null
  is_primary: boolean
}

export interface ConversionPreview {
  lead: Lead
  company: {
    name: string
    trade_name: string | null
    industry: string | null
    size: CompanySize | null
    website: string | null
    description: string | null
    owner_id: number
    owner: { id: number; name: string } | null
  }
  contacts: ConversionContactInput[]
  opportunity: {
    name: string
    stage: OpportunityStage
    amount: string | null
    currency: string
    probability: number
    expected_close_date: string | null
    source: LeadSource | null
    owner_id: number
  }
  duplicates: ConversionDuplicate[]
  can_convert: boolean
  can_set_amount: boolean
  blocked_reason: string | null
}

export interface ConversionInput {
  link_company_id?: string | null
  company?: {
    name: string
    trade_name?: string | null
    industry?: string | null
    size?: string | null
    website?: string | null
    description?: string | null
  }
  contacts: ConversionContactInput[]
  opportunity: {
    name: string
    amount?: number | null
    probability?: number | null
    expected_close_date?: string | null
  }
}

export interface ConversionResult {
  company: Company
  contacts: Contact[]
  opportunity: Opportunity
  lead: Lead
  duplicates: ConversionDuplicate[]
}

export interface NotificationItem {
  id: string
  type: string | null
  message: string
  lead_id: string | null
  reference: string | null
  company_name: string | null
  read_at: string | null
  created_at: string
}

export interface NotificationsResponse {
  data: NotificationItem[]
  meta: { unread: number }
}

export interface AuthUser {
  id: number
  name: string
  email: string
  role: Role
}

export interface Paginated<T> {
  data: T[]
  meta: {
    current_page: number
    per_page: number
    total: number
    last_page: number
  }
  links: Record<string, string | null>
}

// --- Submodule 09: client account lifecycle + contracts ---------------------

export type ContractStatus = 'draft' | 'sent' | 'signed' | 'expired' | 'terminated'

/** Outcome of the mocked Service handoff (spec 09 §2). */
export type HandoffStatus = 'sent' | 'failed'

export interface CompanyHandoff {
  status: HandoffStatus | null
  reference: string | null
  error: string | null
  requested_at: string | null
  can_retry: boolean
}

export interface ContractFileRef {
  present: boolean
  name: string | null
  size: number | null
  mime: string | null
}

export interface ContractRenewalRef {
  id: string
  reference: string
  end_date: string | null
}

export interface ContractCompanyRef {
  id: string
  reference: string
  name: string
  status: CompanyStatus
}

export interface ContractOpportunityRef {
  id: string
  reference: string
  name: string
}

/** A contract on a client account (spec 09). Signing activates the client. */
export interface Contract {
  id: string
  reference: string
  company_id: string
  company?: ContractCompanyRef
  opportunity_id: string | null
  opportunity?: ContractOpportunityRef | null
  renewal_of_contract_id: string | null
  renewal_of?: ContractRenewalRef | null
  title: string
  status: ContractStatus
  start_date: string | null
  end_date: string | null
  signed_at: string | null
  value: string | null
  currency: string
  terms: string | null
  notes: string | null
  file: ContractFileRef
  days_until_expiry: number | null
  is_expiring_soon: boolean
  is_signed: boolean
  is_active: boolean
  owner: { id: number; name: string } | null
  owner_id: number
  /** Legal `PATCH` moves — signing has its own action and is never listed. */
  allowed_transitions: ContractStatus[]
  can_update: boolean
  can_mark_signed: boolean
  can_delete: boolean
  created_at: string
  updated_at: string
}

export interface CompanyContracts {
  data: Contract[]
  meta: { total: number; active: number }
}

export interface ContractInput {
  file: File
  title: string
  opportunity_id?: string | null
  renewal_of_contract_id?: string | null
  start_date?: string | null
  end_date?: string | null
  value?: number | null
  currency?: string
  terms?: string | null
  notes?: string | null
  status?: ContractStatus
}

/** Read-only AR snapshot consumed from the mocked Finance department (spec 09 §7). */
export interface BillingSummary {
  account_status: 'current' | 'overdue' | 'closed'
  outstanding: string
  overdue: string
  overdue_invoices: number
  last_invoice_date: string | null
  next_due_date: string | null
  credit_terms: string
  currency: string
  aging: Record<'current' | '1_30' | '31_60' | '60_plus', string>
}

export interface CompanyBilling {
  data: BillingSummary
  meta: { source: string; read_only: boolean; as_of: string }
}

// --- Module 03: communication history ---------------------------------------

export type CommunicationChannel =
  'email' | 'call' | 'sms' | 'whatsapp' | 'meeting' | 'letter' | 'other'

export type CommunicationDirection = 'inbound' | 'outbound'

export type CommunicationStatus = 'draft' | 'scheduled' | 'sent' | 'received' | 'failed' | 'logged'

export type CommunicationOutcome = 'connected' | 'no_answer' | 'voicemail' | 'busy' | 'other'

/** The records a message can hang off (`Communication::subjectTypes()`). */
export type CommunicationSubjectType = 'lead' | 'company' | 'contact' | 'opportunity' | 'contract'

export interface CommunicationRecordRef {
  type: CommunicationSubjectType
  id: string
  label: string | null
}

export interface CommunicationContactRef {
  id: string
  name: string
  email: string | null
  role_type: ContactRoleType
}

/** The detailed history entry (module 03, spec 11 §Payloads). */
export interface Communication {
  id: string
  reference: string
  subject_type: CommunicationSubjectType
  subject_id: string
  record: CommunicationRecordRef | null
  contact_id: string | null
  contact: CommunicationContactRef | null
  channel: CommunicationChannel
  direction: CommunicationDirection
  status: CommunicationStatus
  subject: string
  body: string | null
  from_name: string | null
  from_address: string | null
  to_name: string | null
  to_address: string | null
  occurred_at: string | null
  duration_minutes: number | null
  outcome: CommunicationOutcome | null
  external_id: string | null
  failed_reason: string | null
  thread_id: string | null
  owner_id: number
  owner: { id: number; name: string } | null
  is_editable: boolean
  can_update: boolean
  can_delete: boolean
  can_restore: boolean
  deleted_at: string | null
  created_at: string
  updated_at: string
}

export interface CommunicationSummary {
  all: number
  by_direction: Partial<Record<CommunicationDirection, number>>
  by_channel: Partial<Record<CommunicationChannel, number>>
  last_contacted_at: string | null
}

/** The history book (`GET /communications`) — pagination meta plus the header summary. */
export type CommunicationsPage = Paginated<Communication> & { summary: CommunicationSummary }

/** One record's history (`GET /leads/{id}/communications` and friends). */
export interface CommunicationHistory {
  data: Communication[]
  meta: { total: number }
}

export interface CommunicationInput {
  subject_type: CommunicationSubjectType
  subject_id: string
  channel: CommunicationChannel
  direction: CommunicationDirection
  status?: CommunicationStatus
  contact_id?: string | null
  subject: string
  body?: string | null
  from_name?: string | null
  from_address?: string | null
  to_name?: string | null
  to_address?: string | null
  occurred_at?: string | null
  duration_minutes?: number | null
  outcome?: CommunicationOutcome | null
}
