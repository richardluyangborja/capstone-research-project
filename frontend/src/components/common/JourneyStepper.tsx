import { Check, Circle, Minus } from 'lucide-react'

export type JourneyStepState = 'done' | 'current' | 'pending' | 'skipped'

export interface JourneyStep {
  key: string
  label: string
  state: JourneyStepState
  hint?: string
}

const markerClass: Record<JourneyStepState, string> = {
  done: 'bg-people text-primary-foreground',
  current: 'bg-primary text-primary-foreground',
  pending: 'border border-border bg-card text-muted-foreground',
  skipped: 'border border-border bg-muted text-muted-foreground',
}

const labelClass: Record<JourneyStepState, string> = {
  done: 'text-foreground',
  current: 'text-primary',
  pending: 'text-muted-foreground',
  skipped: 'text-muted-foreground',
}

/**
 * A read-only account journey: what has happened, what is next, and what was skipped.
 * The state is derived by the caller (spec 16 §Account 360) — the stepper never moves a record.
 */
export default function JourneyStepper({
  steps,
  label = 'Account journey',
}: {
  steps: JourneyStep[]
  label?: string
}) {
  return (
    <ol aria-label={label} className="flex flex-col gap-3 sm:flex-row sm:flex-wrap sm:items-start">
      {steps.map((step, index) => {
        const Icon = step.state === 'done' ? Check : step.state === 'skipped' ? Minus : Circle
        return (
          <li
            key={step.key}
            aria-current={step.state === 'current' ? 'step' : undefined}
            className="flex min-w-0 flex-1 items-start gap-2"
          >
            <span
              aria-hidden="true"
              className={`mt-0.5 flex h-7 w-7 shrink-0 items-center justify-center rounded-full ${markerClass[step.state]}`}
            >
              <Icon className="h-4 w-4" />
            </span>
            <span className="min-w-0">
              <span className={`block text-sm font-medium ${labelClass[step.state]}`}>
                {step.label}
              </span>
              {step.hint && (
                <span className="mt-0.5 block text-xs text-muted-foreground">{step.hint}</span>
              )}
            </span>
            {index < steps.length - 1 && (
              <span
                aria-hidden="true"
                className="ml-1 mt-3.5 hidden h-px flex-1 bg-border sm:block"
              />
            )}
          </li>
        )
      })}
    </ol>
  )
}
