import type { Tone } from '../components/common/StatusBadge'
import type { ContactRoleType, ContactStatus } from '../types'

// Decision-maker types are people-domain (green); `other` stays neutral so a generic
// contact never reads as a decision-maker (resources/design-tokens.md).
export const contactRoleMeta: Record<ContactRoleType, { label: string; tone: Tone }> = {
  hiring_manager: { label: 'Hiring manager', tone: 'people' },
  hr: { label: 'HR', tone: 'people' },
  procurement: { label: 'Procurement', tone: 'people' },
  finance: { label: 'Finance', tone: 'people' },
  executive: { label: 'Executive', tone: 'people' },
  other: { label: 'Other', tone: 'neutral' },
}

export const contactStatusMeta: Record<ContactStatus, { label: string; tone: Tone }> = {
  active: { label: 'Active', tone: 'neutral' },
  inactive: { label: 'Inactive', tone: 'danger' },
}

/** "Grace Lim · Recruitment Officer" — position dropped when the contact has none. */
export const contactDisplayName = (contact: { name: string; position: string | null }): string =>
  contact.position?.trim() ? `${contact.name} · ${contact.position}` : contact.name
