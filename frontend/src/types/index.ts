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
  notes: string | null
  owner: { id: number; name: string } | null
  owner_id: number | null
  can_claim: boolean
  can_reassign: boolean
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
  type: ActivityType
  subject: string
  body: string | null
  status: ActivityStatus
  occurred_at: string | null
  owner: { id: number; name: string } | null
  created_at: string
}

export interface LeadSummary {
  all: number
  mine: number
  unassigned: number
  team: number
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
