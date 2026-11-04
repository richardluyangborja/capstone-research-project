import { api } from './api'

/**
 * The public request-staffing form (spec 16 §Public intake).
 *
 * This is the only unauthenticated write the CRM accepts besides a survey response. It carries
 * just what the landing page asks for — never an owner, status or source.
 */
export interface PublicLeadInput {
  company_name: string
  contact_name: string
  contact_email?: string
  contact_phone?: string
  industry?: string
  service_interest?: string
  estimated_headcount?: number
  needed_roles?: string
  target_start_date?: string
  message?: string
  consent: boolean
  /** Honeypot — a real visitor never fills the hidden field. */
  website?: string
}

export interface PublicLeadResponse {
  message: string
}

export const submitPublicLead = async (input: PublicLeadInput): Promise<PublicLeadResponse> => {
  const { data } = await api.post<PublicLeadResponse>('/public/leads', input)
  return data
}
