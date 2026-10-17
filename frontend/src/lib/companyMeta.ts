import type { Tone } from '../components/common/StatusBadge'
import type { CompanySize, CompanyStatus, CompanyTier } from '../types'

// Company lifecycle (spec 00). `prospect` is not yet a client; everything past it is.
export const companyStatusMeta: Record<CompanyStatus, { label: string; tone: Tone }> = {
  prospect: { label: 'Prospect', tone: 'counts' },
  onboarding: { label: 'Onboarding', tone: 'activity' },
  active: { label: 'Active', tone: 'people' },
  at_risk: { label: 'At risk', tone: 'warm' },
  dormant: { label: 'Dormant', tone: 'neutral' },
  churned: { label: 'Churned', tone: 'danger' },
}

export const companyTierLabels: Record<CompanyTier, string> = {
  a: 'Tier A',
  b: 'Tier B',
  c: 'Tier C',
}

export const companySizeLabels: Record<CompanySize, string> = {
  micro: 'Micro',
  small: 'Small',
  medium: 'Medium',
  large: 'Large',
  enterprise: 'Enterprise',
}

/** Display name: trade name when set, otherwise the legal name. */
export const companyDisplayName = (company: { name: string; trade_name: string | null }): string =>
  company.trade_name?.trim() ? company.trade_name : company.name
