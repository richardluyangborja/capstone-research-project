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
  /** The earliest open follow-up on this lead (module 04, rule 12). */
  next_follow_up_at: string | null
  next_follow_up: FollowUpDue | null
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
  /** Since module 04 a task is owed rather than done, so the timeline carries its due moment. */
  is_follow_up: boolean
  due_at: string | null
  due: FollowUpDue | null
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
  /** Leads still awaiting qualification (`new` or `contacted`). */
  qualification: number
  /** Non-terminal leads past the stale threshold. */
  aging: number
  /** Qualified leads that have not converted yet. */
  conversion: number
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
  /** Deals still in an open stage (not won or lost). */
  open_opportunities: number
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
  /** The earliest open follow-up across the account and its people, deals and contracts. */
  next_follow_up_at: string | null
  next_follow_up: FollowUpDue | null
  /** The newest logged activity or communication on any part of the account. */
  last_activity_at: string | null
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
  /** Where the item lives, so the bell can open it — a lead, a follow-up, a message. */
  url: string | null
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
  /** When a scheduled follow-up is owed (slice S2) — null for everything that happened. */
  scheduled_for: string | null
  /** How due a scheduled message is, derived on read like a task's (spec 12 §7). */
  due: FollowUpDue | null
  duration_minutes: number | null
  outcome: CommunicationOutcome | null
  external_id: string | null
  failed_reason: string | null
  thread_id: string | null
  /** The message this one answers (slice S4), when it names one. */
  in_reply_to_id: string | null
  in_reply_to: CommunicationReplyRef | null
  /** Files that came with the message (slice S3) — on the detail, not in the book. */
  attachments?: Attachment[]
  /** How many files the message carries, on the rows that asked for the count. */
  attachments_count?: number
  owner_id: number
  owner: { id: number; name: string } | null
  is_editable: boolean
  can_send: boolean
  can_attach: boolean
  can_reply: boolean
  can_update: boolean
  can_delete: boolean
  can_restore: boolean
  deleted_at: string | null
  created_at: string
  updated_at: string
}

/** The message a reply answers (module 03 slice S4) — enough to link through to it. */
export interface CommunicationReplyRef {
  id: string
  reference: string
  subject: string
}

/** A file on a record (module 03 slice S3, spec 11 §Payloads). The stored path is never sent. */
export interface Attachment {
  id: string
  name: string
  mime_type: string
  size: number
  uploaded_by: number
  uploader?: { id: number; name: string }
  can_delete: boolean
  created_at: string
}

export interface CommunicationSummary {
  all: number
  /** Messages written but not sent yet (module 04 slice S2). */
  scheduled: number
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

/** What a pull of the mocked mailbox recorded (`POST /communications/sync-inbound`). */
export interface CommunicationSyncResult {
  recorded: number
  source: string
}

/**
 * What an admin's dispatch of the due sends did (`POST /communications/dispatch-due`, slice S2):
 * how many were due, and how they ended up. A second run reports nothing, because what went out
 * is no longer scheduled.
 */
export interface CommunicationDispatchResult {
  attempted: number
  sent: number
  failed: number
  source: string
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
  /** Required when the status is `scheduled`: when the message should go out (slice S2). */
  scheduled_for?: string | null
  duration_minutes?: number | null
  outcome?: CommunicationOutcome | null
}

/**
 * One conversation (module 03 slice S4) — a group of messages that answer each other, as
 * `GET /communications/threads` reads them.
 */
export interface CommunicationThread {
  thread_id: string
  title: string | null
  record: CommunicationRecordRef | null
  channel: CommunicationChannel | null
  message_count: number
  first_at: string | null
  last_at: string | null
  last_message: CommunicationThreadLast | null
}

export interface CommunicationThreadLast {
  id: string
  reference: string
  direction: CommunicationDirection
  status: CommunicationStatus
  from_name: string | null
  occurred_at: string | null
}

/** The conversations the caller may see — pagination only, no header summary. */
export type CommunicationThreadsPage = Omit<Paginated<CommunicationThread>, 'links'>

/** One conversation in full (`GET /communications/threads/{threadId}`), oldest message first. */
export interface CommunicationThreadDetail {
  thread_id: string
  title: string | null
  record: CommunicationRecordRef | null
  channel: CommunicationChannel | null
  message_count: number
  first_at: string | null
  last_at: string | null
  messages: Communication[]
}

/**
 * Answering a message (`POST /communications/{id}/reply`). The record is inherited from the
 * parent, so it is not sent; whatever the parent does not settle — the channel, the title line,
 * the status — is optional (slice S4).
 */
export interface CommunicationReplyInput {
  channel?: CommunicationChannel
  direction?: CommunicationDirection
  status?: CommunicationStatus
  contact_id?: string | null
  subject?: string | null
  body?: string | null
  from_name?: string | null
  from_address?: string | null
  to_name?: string | null
  to_address?: string | null
  occurred_at?: string | null
  duration_minutes?: number | null
  outcome?: CommunicationOutcome | null
}

// --- Module 03 slice S5: reusable templates ----------------------------------

/**
 * A reusable template (module 03 slice S5) — the house's wording, rendered into the composer.
 * Nothing links back to it once a message is saved, so editing or retiring one is harmless.
 */
export interface CommunicationTemplate {
  id: string
  name: string
  channel: CommunicationChannel
  subject: string
  body: string
  /** Offered in the composer's picker. A retired (`deleted_at`) one is out of the book entirely. */
  is_active: boolean
  created_by: number | null
  author: { id: number; name: string } | null
  can_update: boolean
  can_delete: boolean
  can_restore: boolean
  deleted_at: string | null
  created_at: string
  updated_at: string
}

export type CommunicationTemplatesPage = Omit<Paginated<CommunicationTemplate>, 'links'>

export interface CommunicationTemplateInput {
  name: string
  channel: CommunicationChannel
  subject: string
  body: string
  is_active?: boolean
}

/** One merge field the renderer can fill (`GET /communication-templates/fields`). */
export interface CommunicationMergeField {
  token: string
  label: string
}

/** A template rendered against a record — what the composer prefills from (slice S5). */
export interface CommunicationTemplateRender {
  template_id: string
  channel: CommunicationChannel
  subject: string
  body: string
  /** Placeholders that could not be filled. They stay verbatim in the text. */
  unresolved: string[]
}

export interface CommunicationTemplateRenderInput {
  subject_type: CommunicationSubjectType
  subject_id: string
  contact_id?: string | null
}

// --- Module 04: follow-up reminders ----------------------------------------

/** The two shapes a follow-up comes in: a task, and a scheduled message (slice S2). */
export type FollowUpKind = 'task' | 'message'

/** The due state, derived on read against the PH business day (spec 12 §7) — never stored. */
export type FollowUpDueStateName = 'overdue' | 'today' | 'upcoming'

/**
 * Where a generated reminder came from (slice S4, spec 12 §11) — the prefix of its
 * `source_key`: a contract renewal, a quiet-client check-in, or a stale lead.
 */
export type FollowUpSourceName = 'renewal' | 'check_in' | 'stale_lead'

export interface FollowUpDue {
  state: FollowUpDueStateName
  label: string
  days: number
  overdue: boolean
}

/** A follow-up task as its own resource (`/tasks`, spec 12 §Payloads). */
export interface Task {
  id: string
  subject: string
  body: string | null
  record: CommunicationRecordRef | null
  owner: { id: number; name: string } | null
  owner_id: number
  due_at: string
  due: FollowUpDue
  status: { value: ActivityStatus; label: string }
  /** Where a generated reminder came from; null when somebody wrote it by hand (slice S4). */
  source: { value: FollowUpSourceName; label: string } | null
  source_key: string | null
  /** Always null for a task: it has no channel to send on (rule 13). */
  channel: CommunicationChannel | null
  /** Always null for a task: it is nobody's message (slice S2). */
  recipient: string | null
  can_update: boolean
  can_transition: boolean
  can_send: boolean
  can_delete: boolean
  created_at: string
  updated_at: string
}

/** A worklist row that is a task. */
export interface FollowUpTask extends Task {
  kind: 'task'
}

/**
 * A worklist row that is a scheduled message (slice S2): a task's keys, plus what only a
 * message has — its reference, its channel and who it is going to. It is sent or retired, never
 * completed, so `can_transition` is always false and its state is always `scheduled`.
 */
export interface FollowUpMessage extends Omit<Task, 'status'> {
  kind: 'message'
  status: { value: 'scheduled'; label: string }
  reference: string
  channel: CommunicationChannel
  recipient: string | null
  to_name: string | null
  to_address: string | null
}

/** A worklist row, whichever shape it is. Both read alike on purpose (spec 12 §Payloads). */
export type FollowUp = FollowUpTask | FollowUpMessage

export interface FollowUpCounts {
  overdue: number
  today: number
  upcoming: number
  open: number
  done: number
}

export type FollowUpsPage = Omit<Paginated<FollowUp>, 'links'> & { counts: FollowUpCounts }

/** A reminder the generator raised on a record (slice S4). */
export interface GeneratedFollowUp {
  id: string
  subject: string
  record: CommunicationRecordRef
  owner: { id: number; name: string } | null
  due_at: string
}

/** A record the generator could not place, and why. */
export interface GeneratedFollowUpSkip {
  record: CommunicationRecordRef
  reason: string
}

/** One source's half of the generate summary — renewals, check-ins or stale leads. */
export interface GeneratedFollowUpSource {
  key: FollowUpSourceName
  label: string
  created: number
  skipped: number
  tasks: GeneratedFollowUp[]
  skips: GeneratedFollowUpSkip[]
}

/** What `POST /follow-ups/generate` reports (slice S4). */
export interface GenerateRemindersResult {
  created: number
  skipped: number
  sources: GeneratedFollowUpSource[]
}

export interface TaskInput {
  subject_type: CommunicationSubjectType
  subject_id: string
  subject: string
  body?: string | null
  /** A reminder without a time is not a reminder (rule S1). */
  due_at: string
  owner_id?: number
}

export interface TaskUpdateInput {
  subject?: string
  body?: string | null
  due_at?: string
  owner_id?: number
}

// --- Module 05: client satisfaction & survey --------------------------------

/** The five ways a survey asks a question (spec 13, slice S1). */
export type SurveyQuestionTypeName = 'rating' | 'nps' | 'single_choice' | 'multi_choice' | 'text'

/** One question in a survey, in the order it is asked. */
export interface SurveyQuestion {
  id: string
  type: { value: SurveyQuestionTypeName; label: string }
  prompt: string
  help: string | null
  required: boolean
  position: number
  /** The choices for the two choice types; `[]` for the rest. */
  options: string[]
}

/**
 * A question as the form sends it: no id and no position — the array order is the asking order,
 * and the set is replaced wholesale while the survey has never been sent (spec 13 §S1).
 */
export interface SurveyQuestionInput {
  type: SurveyQuestionTypeName
  prompt: string
  help?: string | null
  required?: boolean
  options?: string[]
}

/**
 * A reusable satisfaction survey (module 05 slice S1) — the house's wording and its ordered
 * question set. Slice S2 sends it as an invitation and freezes the questions once it has gone out,
 * so `questions_editable` is what the form reads; `duplicate` is the way to change them.
 */
export interface Survey {
  id: string
  name: string
  description: string | null
  is_active: boolean
  questions?: SurveyQuestion[]
  questions_count?: number
  invitations_count?: number
  responses_count?: number
  /** False once any invitation exists (spec 13 §Business rules 1). */
  questions_editable?: boolean
  created_by: number | null
  author: { id: number; name: string } | null
  can_update: boolean
  can_delete: boolean
  can_restore: boolean
  can_duplicate: boolean
  deleted_at: string | null
  created_at: string
  updated_at: string
}

export type SurveysPage = Omit<Paginated<Survey>, 'links'>

export interface SurveyInput {
  name: string
  description?: string | null
  is_active?: boolean
  questions?: SurveyQuestionInput[]
}

/** What an invitation reads as today — `expired` is derived from its window, not stored. */
export type SurveyInvitationStateName = 'sent' | 'expired' | 'failed' | 'submitted' | 'cancelled'

/** The scored band of a promoter answer (9–10 / 7–8 / 0–6). */
export type SurveyBandName = 'promoter' | 'passive' | 'detractor'

/** One answer inside a response, with the question it belongs to. */
export interface SurveyAnswer {
  id: string
  question_id: string
  question?: {
    id: string
    type: SurveyQuestionTypeName | null
    prompt: string
    options: string[] | null
  } | null
  value_number: number | null
  value_text: string | null
  value_options: string[] | null
}

/**
 * What a client answered (module 05 slice S2) — scored at submission. The response book and the
 * account card build on this shape in slice S3.
 */
export interface SurveyResponseSummary {
  id: string
  submitted_at: string | null
  csat: number | null
  nps: number | null
  band: SurveyBandName | null
  band_label?: string | null
  needs_attention: boolean
  /** The closed-loop record (module 05 slice S4); the answer itself never changes. */
  resolved: boolean
  resolved_at: string | null
  resolved_by: { id: number; name: string } | null
  resolution_note: string | null
  can_resolve: boolean
  survey?: { id: string; name: string } | null
  company?: { id: string; name: string } | null
  contact?: { id: string; name: string } | null
  invitation?: { id: string; reference: string } | null
  answers?: SurveyAnswer[]
  created_at: string
}

/** The response book's filters (module 05 slice S3, spec 13 §S3). */
export interface SurveyResponseFilters {
  survey?: string
  company?: string
  band?: SurveyBandName | ''
  attention?: 'true' | 'false' | ''
  date_from?: string
  date_to?: string
  search?: string
  page?: number
}

/**
 * The satisfaction numbers every view shares (module 05 slice S3). Nothing here is stored: CSAT
 * is the mean of the responses' own scores, NPS is promoters − detractors over the answers that
 * carried the promoter question, and the rate pairs answers with answerable invitations.
 */
export interface SatisfactionMetrics {
  responses: number
  csat: number | null
  nps: number | null
  promoters: number
  passives: number
  detractors: number
  answered: number
  attention: number
  invitations: number
  response_rate: number | null
}

/** The org summary: the numbers plus the latest answers, and who is still unhappy. */
export interface SatisfactionSummary extends SatisfactionMetrics {
  recent: SurveyResponseSummary[]
  attention_responses: SurveyResponseSummary[]
}

/** The account's card: the same numbers, its latest answer and a short trend (oldest first). */
export interface CompanySatisfaction extends SatisfactionMetrics {
  latest: {
    id: string
    survey: { id: string; name: string } | null
    csat: number | null
    nps: number | null
    band: SurveyBandName | null
    band_label: string | null
    needs_attention: boolean
    submitted_at: string | null
  } | null
  trend: { submitted_at: string | null; csat: number | null; nps: number | null }[]
  recent: SurveyResponseSummary[]
}

/**
 * One send of a survey (module 05 slice S2). `state` is the derived reading; `public_url` travels
 * only while the client can still use it.
 */
export interface SurveyInvitation {
  id: string
  reference: string
  survey?: { id: string; name: string; description: string | null } | null
  company?: { id: string; name: string } | null
  contact?: { id: string; name: string; email: string | null } | null
  contract?: { id: string; title: string; reference: string } | null
  recipient_name: string | null
  recipient_email: string
  message: string | null
  status: string | null
  state: SurveyInvitationStateName
  state_label: string
  failed_reason: string | null
  sent_at: string | null
  expires_at: string | null
  submitted_at: string | null
  public_url?: string
  communication?: {
    id: string
    reference: string
    subject: string | null
    status: string | null
    external_id: string | null
  } | null
  response?: SurveyResponseSummary | null
  can_cancel: boolean
  can_resend: boolean
  created_at: string
  updated_at: string
}

export type SurveyInvitationsPage = Omit<Paginated<SurveyInvitation>, 'links'>

/** What sending a survey needs: the account, and optionally a person, a contract and a note. */
export interface SurveyInvitationInput {
  company_id: string
  contact_id?: string | null
  contract_id?: string | null
  recipient_name?: string | null
  recipient_email?: string | null
  message?: string | null
}

/**
 * The client's page (module 05 slice S2). It carries the survey being asked and the greeting's
 * account name — never the account's other data (spec 13 §Business rules 6).
 */
export interface PublicSurvey {
  survey: {
    id: string
    name: string
    description: string | null
    questions: PublicSurveyQuestion[]
  }
  company: { name: string }
  contact: { name: string } | null
  expires_at: string | null
}

/** One question on the client's page: the type, and the range or options it accepts. */
export interface PublicSurveyQuestion {
  id: string
  type: SurveyQuestionTypeName
  prompt: string
  help: string | null
  required: boolean
  options: string[]
  bounds: { min: number; max: number } | null
}

/** One answer as the public form submits it — only the column its type uses is filled. */
export interface PublicSurveyAnswerInput {
  question_id: string
  value_number?: number | null
  value_text?: string | null
  value_options?: string[] | null
}
