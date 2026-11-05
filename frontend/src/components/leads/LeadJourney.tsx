import { Link } from 'react-router-dom'
import AlertItem from '../common/AlertItem'
import FormCard from '../common/FormCard'
import JourneyStepper from '../common/JourneyStepper'
import {
  buildLeadJourney,
  type LeadJourneyAction as LeadJourneyActionModel,
} from '../../lib/leadJourney'
import type { Lead, LeadStatus, LeadTimeline } from '../../types'

interface LeadJourneyProps {
  lead: Lead
  timeline: LeadTimeline | null
  saving?: boolean
  onStatus: (status: LeadStatus) => void
  onCompleteChecklist: () => void
}

const primaryClass =
  'inline-flex items-center rounded-md bg-primary px-3 py-2 text-sm font-medium text-primary-foreground disabled:opacity-60'
const secondaryClass =
  'inline-flex items-center rounded-md border border-border bg-card px-3 py-2 text-sm font-medium text-foreground hover:bg-muted disabled:opacity-60'

/**
 * The derived lead journey (spec 17 T3, ADR-0028). It is read-only: every action calls an
 * existing status endpoint or opens the existing conversion wizard, and the stepper itself never
 * moves the record.
 */
export default function LeadJourney({
  lead,
  timeline,
  saving = false,
  onStatus,
  onCompleteChecklist,
}: LeadJourneyProps) {
  const journey = buildLeadJourney(lead, timeline)

  return (
    <FormCard
      title="Journey"
      description="Derived from the lead's status and timeline. Read-only — the Lifecycle panel below keeps the complete set of legal transitions."
    >
      <JourneyStepper steps={journey.steps} label="Lead journey" />

      <div className="mt-4 flex flex-col gap-3 border-t border-border pt-4">
        {journey.branch ? (
          <AlertItem variant={journey.branch.variant} title={journey.branch.title}>
            <p>{journey.branch.message}</p>
          </AlertItem>
        ) : (
          <p className="text-sm text-foreground">{journey.message}</p>
        )}

        {journey.missingCriteria.length > 0 && (
          <div>
            <p className="text-xs font-medium uppercase tracking-wide text-muted-foreground">
              Missing criteria
            </p>
            <ul
              aria-label="Missing qualification criteria"
              className="mt-1.5 flex flex-wrap gap-1.5"
            >
              {journey.missingCriteria.map((criterion) => (
                <li
                  key={criterion}
                  className="rounded-full bg-muted px-2 py-0.5 text-xs font-medium text-muted-foreground"
                >
                  {criterion}
                </li>
              ))}
            </ul>
          </div>
        )}

        {journey.actions.length > 0 && (
          <div className="flex flex-wrap items-center gap-2">
            {journey.actions.map((action) => (
              <JourneyAction
                key={`${action.kind}-${action.label}`}
                action={action}
                saving={saving}
                onStatus={onStatus}
                onCompleteChecklist={onCompleteChecklist}
              />
            ))}
          </div>
        )}
      </div>
    </FormCard>
  )
}

function JourneyAction({
  action,
  saving,
  onStatus,
  onCompleteChecklist,
}: {
  action: LeadJourneyActionModel
  saving: boolean
  onStatus: (status: LeadStatus) => void
  onCompleteChecklist: () => void
}) {
  const className = action.variant === 'primary' ? primaryClass : secondaryClass

  if (action.kind === 'link') {
    return (
      <Link to={action.href} className={className}>
        {action.label}
      </Link>
    )
  }

  if (action.kind === 'status') {
    return (
      <button
        type="button"
        disabled={saving}
        onClick={() => onStatus(action.status)}
        className={className}
      >
        {saving ? 'Applying…' : action.label}
      </button>
    )
  }

  return (
    <button type="button" onClick={onCompleteChecklist} className={className}>
      {action.label}
    </button>
  )
}
