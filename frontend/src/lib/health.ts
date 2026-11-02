import type { Tone } from '../components/common/StatusBadge'
import type { CompanyHealthSeverity, CompanyHealthState } from '../types'

interface HealthStateMeta {
  label: string
  tone: Tone
  description: string
}

/** Shared wording for the Account 360 block and the Account Book column (spec 16 S13). */
export const healthStateMeta: Record<CompanyHealthState, HealthStateMeta> = {
  healthy: {
    label: 'Healthy',
    tone: 'success',
    description: 'No critical or warning flags. The account is up to date.',
  },
  attention: {
    label: 'Attention',
    tone: 'cost',
    description: 'A few things need a look, but nothing is critical yet.',
  },
  at_risk: {
    label: 'At risk',
    tone: 'danger',
    description: 'Something critical needs attention now.',
  },
}

export const healthSeverityMeta: Record<CompanyHealthSeverity, { label: string; tone: Tone }> = {
  critical: { label: 'Critical', tone: 'danger' },
  warning: { label: 'Warning', tone: 'cost' },
  info: { label: 'Info', tone: 'counts' },
}
