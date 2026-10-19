import type { Tone } from '../components/common/StatusBadge'
import type {
  CommunicationChannel,
  CommunicationDirection,
  CommunicationOutcome,
  CommunicationRecordRef,
  CommunicationStatus,
  CommunicationSubjectType,
} from '../types'

// Communication history (module 03, spec 11). Correspondence reads as activity (purple),
// calls and meetings as people work (green), delivery problems as danger — never a
// hardcoded colour (resources/design-tokens.md).
export const communicationChannelMeta: Record<CommunicationChannel, { label: string; tone: Tone }> =
  {
    email: { label: 'Email', tone: 'activity' },
    call: { label: 'Call', tone: 'people' },
    sms: { label: 'SMS', tone: 'counts' },
    whatsapp: { label: 'WhatsApp', tone: 'counts' },
    meeting: { label: 'Meeting', tone: 'people' },
    letter: { label: 'Letter', tone: 'neutral' },
    other: { label: 'Other', tone: 'neutral' },
  }

export const communicationDirectionMeta: Record<
  CommunicationDirection,
  { label: string; tone: Tone }
> = {
  inbound: { label: 'Inbound', tone: 'counts' },
  outbound: { label: 'Outbound', tone: 'activity' },
}

export const communicationStatusMeta: Record<CommunicationStatus, { label: string; tone: Tone }> = {
  draft: { label: 'Draft', tone: 'neutral' },
  scheduled: { label: 'Scheduled', tone: 'warm' },
  sent: { label: 'Sent', tone: 'success' },
  received: { label: 'Received', tone: 'counts' },
  failed: { label: 'Failed', tone: 'danger' },
  logged: { label: 'Logged', tone: 'neutral' },
}

export const communicationOutcomeMeta: Record<CommunicationOutcome, { label: string; tone: Tone }> =
  {
    connected: { label: 'Connected', tone: 'success' },
    no_answer: { label: 'No answer', tone: 'warm' },
    voicemail: { label: 'Voicemail', tone: 'warm' },
    busy: { label: 'Busy', tone: 'warm' },
    other: { label: 'Other', tone: 'neutral' },
  }

export const communicationSubjectLabels: Record<CommunicationSubjectType, string> = {
  lead: 'Lead',
  company: 'Account',
  contact: 'Contact',
  opportunity: 'Deal',
  contract: 'Contract',
}

export const communicationChannels = Object.keys(communicationChannelMeta) as CommunicationChannel[]

export const communicationDirections = Object.keys(
  communicationDirectionMeta,
) as CommunicationDirection[]

export const communicationOutcomes = Object.keys(communicationOutcomeMeta) as CommunicationOutcome[]

/** Statuses the log form may submit, per channel and direction — mirrors
 * `CommunicationStatus::settableFor()`. Only an outbound email is written first and sent
 * later, so only it gets a `draft` or a `scheduled` moment (slices S1–S2, module 04 §S2). */
export const settableCommunicationStatuses = (
  channel: CommunicationChannel,
  direction: CommunicationDirection,
): CommunicationStatus[] => {
  const statuses: CommunicationStatus[] = ['sent', 'received', 'failed', 'logged']

  return channel === 'email' && direction === 'outbound'
    ? ['draft', 'scheduled', ...statuses]
    : statuses
}

/** A status the composer must not offer: a reply is answered in its own conversation, so it is
 * never put off to a later time (module 04 §S2). */
export const isSchedulableStatus = (status: CommunicationStatus): boolean => status === 'scheduled'

/** Duration and outcome only mean something for a conversation (spec 11 §Channels). */
export const isConversationalChannel = (channel: CommunicationChannel): boolean =>
  channel === 'call' || channel === 'meeting'

/**
 * The default a channel/direction pair gets when the caller does not pick a status — kept in
 * step with `CommunicationStatus::defaultFor()` on the API.
 */
export const defaultCommunicationStatus = (
  channel: CommunicationChannel,
  direction: CommunicationDirection,
): CommunicationStatus => {
  if (direction === 'inbound') return 'received'
  return channel === 'email' || channel === 'sms' || channel === 'whatsapp' ? 'sent' : 'logged'
}

/**
 * Where a message's record lives in the app. The API returns the record's type and id, not a
 * route; contacts and contracts have no detail page of their own yet, so they are plain text.
 */
export const communicationRecordPath = (record: CommunicationRecordRef | null): string | null => {
  if (!record) return null
  switch (record.type) {
    case 'lead':
      return `/leads/${record.id}`
    case 'company':
      return `/companies/${record.id}`
    case 'opportunity':
      return `/opportunities/${record.id}`
    default:
      return null
  }
}

/** The API path segment for one record's nested history. */
export const communicationSubjectPath: Record<CommunicationSubjectType, string> = {
  lead: 'leads',
  company: 'companies',
  contact: 'contacts',
  opportunity: 'opportunities',
  contract: 'contracts',
}
