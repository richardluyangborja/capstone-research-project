export type Role = 'salesrep' | 'manager' | 'admin' | 'superadmin'

export type LeadStatus =
  | 'new'
  | 'contacted'
  | 'qualified'
  | 'converted'
  | 'disqualified'
  | 'nurture'
  | 'archived'

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
  urgency: Urgency | null
  rating: LeadRating | null
  status: LeadStatus
  notes: string | null
  owner: { id: number; name: string } | null
  owner_id: number | null
  budget_rate?: string
  created_at: string
  updated_at: string
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
