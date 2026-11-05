import type { JourneyStep, JourneyStepState } from '../components/common/JourneyStepper'
import type { Lead, LeadStatus, LeadTimeline } from '../types'
import { formatDate } from './format'
import { disqualificationReasonLabels } from './leadMeta'

export const qualificationCriterionLabels: Record<string, string> = {
  estimated_headcount: 'Estimated headcount',
  needed_roles: 'Needed roles',
  contact: 'Contact channel',
}

/** The API keeps raw criterion keys; the UI never shows them (ADR-0028). */
export function qualificationCriterionLabel(key: string): string {
  return (
    qualificationCriterionLabels[key] ??
    key
      .split('_')
      .filter(Boolean)
      .map((part) => part[0]?.toUpperCase() + part.slice(1))
      .join(' ')
  )
}

/**
 * A branch status does not itself prove that Contacted happened. The status-change activities are
 * the audit history, so look for an arrival at `contacted` (new → contacted, disqualified →
 * contacted, and so on) rather than inferring it from the current state.
 */
export function timelineShowsContacted(timeline: LeadTimeline | null): boolean {
  return (timeline?.data ?? []).some(
    (activity) =>
      activity.category === 'system' && /(?:→|->)\s*contacted\b/i.test(activity.subject),
  )
}

export type LeadJourneyAction =
  | { kind: 'status'; status: LeadStatus; label: string; variant: 'primary' | 'secondary' }
  | { kind: 'link'; href: string; label: string; variant: 'primary' | 'secondary' }
  | { kind: 'checklist'; label: string; variant: 'primary' | 'secondary' }

export interface LeadJourneyModel {
  steps: JourneyStep[]
  message: string
  missingCriteria: string[]
  branch: {
    variant: 'info' | 'warning'
    title: string
    message: string
  } | null
  actions: LeadJourneyAction[]
}

type StepState = JourneyStepState

export function buildLeadJourney(lead: Lead, timeline: LeadTimeline | null): LeadJourneyModel {
  const missingCriteria = lead.missing_criteria.map(qualificationCriterionLabel)
  const contactedInTimeline = timelineShowsContacted(timeline)
  const capturedHint = formatDate(lead.created_at)
    ? `Captured ${formatDate(lead.created_at)}`
    : 'Captured'

  const step = (key: string, label: string, state: StepState, hint: string): JourneyStep => ({
    key,
    label,
    state,
    hint,
  })

  switch (lead.status) {
    case 'new':
      return {
        steps: [
          step('captured', 'Captured', 'done', capturedHint),
          step('contacted', 'Contacted', 'current', 'Log the first outbound contact'),
          step('qualified', 'Qualified', 'pending', 'After Contacted'),
          step('converted', 'Converted', 'pending', 'After Qualification'),
        ],
        message:
          'Contacted comes first. Log an outbound activity below, or mark this lead contacted now.',
        missingCriteria: [],
        branch: null,
        actions: lead.can_update
          ? [{ kind: 'status', status: 'contacted', label: 'Mark contacted', variant: 'primary' }]
          : [],
      }

    case 'contacted':
      return {
        steps: [
          step('captured', 'Captured', 'done', capturedHint),
          step('contacted', 'Contacted', 'done', 'First outbound contact logged'),
          step(
            'qualified',
            'Qualified',
            'current',
            missingCriteria.length === 0 ? 'Checklist complete' : 'Checklist incomplete',
          ),
          step('converted', 'Converted', 'pending', 'After Qualification'),
        ],
        message:
          missingCriteria.length === 0
            ? lead.can_update
              ? 'The minimum checklist is complete. Mark the lead qualified when you are ready.'
              : 'The minimum checklist is complete. You need edit rights to qualify this lead.'
            : `Qualification opens once the checklist is complete. Still missing: ${missingCriteria.join(', ')}.`,
        missingCriteria,
        branch: null,
        actions:
          missingCriteria.length > 0
            ? [{ kind: 'checklist', label: 'Complete the checklist', variant: 'primary' }]
            : lead.can_update
              ? [
                  {
                    kind: 'status',
                    status: 'qualified',
                    label: 'Mark qualified',
                    variant: 'primary',
                  },
                ]
              : [],
      }

    case 'qualified':
      return {
        steps: [
          step('captured', 'Captured', 'done', capturedHint),
          step('contacted', 'Contacted', 'done', 'First outbound contact logged'),
          step('qualified', 'Qualified', 'done', 'Minimum criteria met'),
          step('converted', 'Converted', 'current', 'Open the conversion wizard'),
        ],
        message: lead.can_convert
          ? 'Qualified — convert it into an account, its contacts and the first deal.'
          : 'Qualified — conversion needs edit rights on this lead.',
        missingCriteria: [],
        branch: null,
        actions: lead.can_convert
          ? [
              {
                kind: 'link',
                href: `/leads/${lead.id}/convert`,
                label: 'Convert to client',
                variant: 'primary',
              },
            ]
          : [],
      }

    case 'converted':
      return {
        steps: [
          step('captured', 'Captured', 'done', capturedHint),
          step('contacted', 'Contacted', 'done', 'First outbound contact logged'),
          step('qualified', 'Qualified', 'done', 'Minimum criteria met'),
          step('converted', 'Converted', 'done', 'Client created'),
        ],
        message: 'Converted — this lead is now a client. Open the account or its first deal.',
        missingCriteria: [],
        branch: null,
        actions: [
          ...(lead.converted_company_id
            ? [
                {
                  kind: 'link' as const,
                  href: `/accounts/${lead.converted_company_id}`,
                  label: 'Open account',
                  variant: 'primary' as const,
                },
              ]
            : []),
          ...(lead.converted_opportunity_id
            ? [
                {
                  kind: 'link' as const,
                  href: `/opportunities/${lead.converted_opportunity_id}`,
                  label: 'View first deal',
                  variant: 'secondary' as const,
                },
              ]
            : []),
        ],
      }

    case 'nurture':
      return {
        steps: [
          step('captured', 'Captured', 'done', capturedHint),
          step('contacted', 'Contacted', 'done', 'Contacted before nurture'),
          step('qualified', 'Qualified', 'pending', 'Re-qualify when ready'),
          step('converted', 'Converted', 'pending', 'After Qualification'),
        ],
        message:
          missingCriteria.length === 0
            ? 'Paused in nurture — re-engage or qualify when ready.'
            : `Paused in nurture — re-engage or qualify when ready. Still missing: ${missingCriteria.join(', ')}.`,
        missingCriteria,
        branch: {
          variant: 'info',
          title: 'Nurture',
          message:
            'Paused in nurture — re-engage or qualify when ready. The timeline keeps the earlier contact history.',
        },
        actions: lead.can_update
          ? [
              {
                kind: 'status',
                status: 'contacted',
                label: 'Re-engage as contacted',
                variant: 'primary',
              },
              ...(missingCriteria.length === 0
                ? [
                    {
                      kind: 'status' as const,
                      status: 'qualified' as const,
                      label: 'Mark qualified',
                      variant: 'secondary' as const,
                    },
                  ]
                : []),
            ]
          : [],
      }

    case 'disqualified': {
      const reason = lead.disqualification_reason
        ? disqualificationReasonLabels[lead.disqualification_reason]
        : null

      return {
        steps: [
          step('captured', 'Captured', 'done', capturedHint),
          step(
            'contacted',
            'Contacted',
            contactedInTimeline ? 'done' : 'pending',
            contactedInTimeline
              ? 'Contacted before disqualification'
              : 'Never contacted on this lead',
          ),
          step('qualified', 'Qualified', 'pending', 'Not qualified'),
          step('converted', 'Converted', 'pending', 'Not converted'),
        ],
        message: 'Stopped — re-open through Contacted to start working this lead again.',
        missingCriteria: [],
        branch: {
          variant: 'warning',
          title: 'Disqualified',
          message: `${reason ? `${reason}. ` : ''}Stopped — re-open through Contacted to restart; earlier history stays on the timeline.`,
        },
        actions: lead.can_update
          ? [
              {
                kind: 'status',
                status: 'contacted',
                label: 'Re-open as contacted',
                variant: 'primary',
              },
            ]
          : [],
      }
    }

    case 'archived':
      return {
        steps: [
          step('captured', 'Captured', 'done', capturedHint),
          step(
            'contacted',
            'Contacted',
            contactedInTimeline ? 'done' : 'pending',
            contactedInTimeline ? 'Contacted before archiving' : 'Never contacted on this lead',
          ),
          step('qualified', 'Qualified', 'pending', 'Not qualified'),
          step('converted', 'Converted', 'pending', 'Not converted'),
        ],
        message: 'Archived — restore to New to restart the journey.',
        missingCriteria: [],
        branch: {
          variant: 'info',
          title: 'Archived',
          message:
            'Archived — restore to New to restart the journey. Archived leads keep their history and stay out of the default lists.',
        },
        actions: lead.can_update
          ? [{ kind: 'status', status: 'new', label: 'Restore to New', variant: 'primary' }]
          : [],
      }
  }
}
