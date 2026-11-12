import type { Tone } from '../components/common/StatusBadge'
import type { DiagnosticSectionState } from '../types'

/**
 * Presentation for the superadmin platform diagnostics (module 08 slice S8).
 *
 * The server decides every state; this module only maps it to a label and a token tone. A
 * missing, timed-out or unavailable section is shown as such — never silently treated as zero.
 */
export const diagnosticStateMeta: Record<
  DiagnosticSectionState,
  { label: string; tone: Tone; description: string }
> = {
  available: {
    label: 'Available',
    tone: 'success',
    description: 'The fixed read answered normally.',
  },
  degraded: {
    label: 'Needs attention',
    tone: 'warm',
    description: 'The section answered, but something needs reading.',
  },
  partial: {
    label: 'Partial',
    tone: 'warm',
    description: 'Some allow-listed counts could not be read.',
  },
  not_configured: {
    label: 'Not configured',
    tone: 'neutral',
    description: 'The subsystem is not set up in this environment.',
  },
  unavailable: {
    label: 'Unavailable',
    tone: 'danger',
    description: 'The section could not be read.',
  },
  timeout: {
    label: 'Timed out',
    tone: 'danger',
    description: 'The fixed read exceeded its time bound.',
  },
}

/** The fixed order entity totals are shown in, matching the server's allow-list. */
export const diagnosticEntityOrder: Array<{ key: string; label: string }> = [
  { key: 'leads', label: 'Leads' },
  { key: 'accounts', label: 'Accounts' },
  { key: 'contacts', label: 'Contacts' },
  { key: 'opportunities', label: 'Opportunities' },
  { key: 'contracts', label: 'Contracts' },
  { key: 'communications', label: 'Communications' },
  { key: 'activities', label: 'Activities' },
  { key: 'follow_up_tasks', label: 'Follow-up tasks' },
  { key: 'attachments', label: 'Attachments' },
  { key: 'surveys', label: 'Surveys' },
  { key: 'survey_invitations', label: 'Survey invitations' },
  { key: 'survey_responses', label: 'Survey responses' },
  { key: 'teams', label: 'Teams' },
  { key: 'users', label: 'Users' },
  { key: 'audit_logs', label: 'Audit rows' },
]
