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
  activities: number
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
  tags: string[]
  parent_company_id: string | null
  parent: CompanyParentRef | null
  source_lead_id: string | null
  owner: { id: number; name: string } | null
  owner_id: number
  counts: CompanyCounts
  can_update: boolean
  can_manage_sites: boolean
  can_manage_parent: boolean
  can_assign: boolean
  can_delete: boolean
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
