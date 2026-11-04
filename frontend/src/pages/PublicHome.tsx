import type { LucideIcon } from 'lucide-react'
import {
  Activity,
  ArrowRight,
  BadgeCheck,
  Building2,
  CalendarClock,
  Check,
  Inbox,
  MessagesSquare,
} from 'lucide-react'
import {
  AccountHealthPreview,
  LeadWorkspacePreview,
  PipelinePreview,
} from '../components/public/ProductPreview'

interface Feature {
  icon: LucideIcon
  title: string
  body: string
  tone: string
}

const features: Feature[] = [
  {
    icon: Inbox,
    title: 'Capture every request',
    body: 'Website, referral or a call from the road — every lead lands in one intake queue instead of a spreadsheet.',
    tone: 'bg-counts/15 text-counts',
  },
  {
    icon: BadgeCheck,
    title: 'Qualify with a shared score',
    body: 'Headcount, roles and decision-makers become a consistent hot, warm or cold call the whole team can trust.',
    tone: 'bg-people/15 text-people',
  },
  {
    icon: Building2,
    title: 'See the whole account',
    body: 'Contacts, sites, contracts, rate cards and renewals live together in one Account 360 view.',
    tone: 'bg-cost/15 text-cost',
  },
  {
    icon: MessagesSquare,
    title: 'Keep every conversation',
    body: 'Email, calls, meetings and replies stay on the record they belong to, with a clean history for handovers.',
    tone: 'bg-activity/15 text-activity',
  },
  {
    icon: CalendarClock,
    title: 'Never miss the next move',
    body: 'Tasks, scheduled messages and reminders surface what is owed today — and who owns it.',
    tone: 'bg-counts/15 text-counts',
  },
  {
    icon: Activity,
    title: 'Know where to lean in',
    body: 'Account health, deployment fill and overdue AR give managers one honest view of the work that needs attention.',
    tone: 'bg-people/15 text-people',
  },
]

const steps = [
  {
    title: 'Tell us what you need',
    body: 'Share the roles, headcount and target start date. A short brief is enough to begin.',
  },
  {
    title: 'We shortlist and mobilise',
    body: 'Recruitment and deployment work from the same record, so the handoff is visible from day one.',
  },
  {
    title: 'You see the whole engagement',
    body: 'Contracts, deployments, timesheets and follow-ups stay in one place — no chasing separate updates.',
  },
]

export default function PublicHome() {
  return (
    <>
      <section className="relative isolate overflow-hidden border-b border-border">
        <div aria-hidden="true" className="pointer-events-none absolute inset-0">
          <div className="public-grid absolute inset-0 opacity-60" />
          <div className="absolute -left-20 top-10 h-72 w-72 rounded-full bg-primary/20 blur-3xl motion-safe:animate-public-float" />
          <div className="absolute right-0 top-0 h-96 w-96 rounded-full bg-counts/15 blur-3xl motion-safe:animate-public-drift" />
          <div className="absolute bottom-0 left-1/3 h-72 w-72 rounded-full bg-people/10 blur-3xl motion-safe:animate-public-float" />
        </div>

        <div className="relative mx-auto grid max-w-6xl items-center gap-12 px-4 pb-20 pt-16 lg:grid-cols-[1.05fr_0.95fr] lg:pb-24 lg:pt-24">
          <div>
            <p className="inline-flex items-center gap-2 rounded-full border border-border bg-card/80 px-3 py-1 text-xs font-medium text-muted-foreground">
              <span className="h-1.5 w-1.5 rounded-full bg-people" aria-hidden="true" />
              Staffing operations, on one record
            </p>

            <h1 className="mt-6 max-w-2xl text-4xl font-semibold tracking-tight text-foreground sm:text-5xl">
              The right people, and the follow-through to match.
            </h1>
            <p className="mt-5 max-w-xl text-base text-muted-foreground sm:text-lg">
              Primepower brings recruitment, deployment and client follow-up into one accountable
              workspace — from the first staffing request to the last timesheet.
            </p>

            <div className="mt-8 flex flex-wrap items-center gap-3">
              <a
                href="#request-staffing"
                className="inline-flex items-center gap-2 rounded-md bg-primary px-4 py-2.5 text-sm font-medium text-primary-foreground hover:bg-primary/90 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background"
              >
                Request staffing
                <ArrowRight className="h-4 w-4" aria-hidden="true" />
              </a>
              <a
                href="#product"
                className="inline-flex items-center gap-2 rounded-md border border-border bg-card px-4 py-2.5 text-sm font-medium text-foreground hover:bg-secondary focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background"
              >
                See the workspace
              </a>
            </div>

            <ul className="mt-8 grid gap-2 text-sm text-muted-foreground sm:grid-cols-2">
              {[
                'One accountable owner per account',
                'Recruitment → deployment → timekeeping',
                'Manager-ready pipeline and health views',
                'Built for Philippine business hours',
              ].map((item) => (
                <li key={item} className="flex items-start gap-2">
                  <Check className="mt-0.5 h-4 w-4 shrink-0 text-people" aria-hidden="true" />
                  {item}
                </li>
              ))}
            </ul>
          </div>

          <div className="relative">
            <div
              aria-hidden="true"
              className="absolute -inset-4 rounded-3xl bg-primary/5 blur-2xl"
            />
            <LeadWorkspacePreview className="relative" />
          </div>
        </div>
      </section>

      <section aria-label="What the workspace covers" className="border-b border-border bg-card">
        <div className="mx-auto grid max-w-6xl gap-6 px-4 py-8 sm:grid-cols-3">
          {[
            ['Capture & qualify', 'One intake queue with a consistent rating'],
            ['Contract & delivery', 'Rate cards, renewals and deployment fill in view'],
            ['Follow-up', 'The next owed action, not just the last note'],
          ].map(([title, body]) => (
            <div key={title}>
              <p className="text-sm font-semibold text-foreground">{title}</p>
              <p className="mt-1 text-sm text-muted-foreground">{body}</p>
            </div>
          ))}
        </div>
      </section>

      <section id="product" className="mx-auto max-w-6xl scroll-mt-20 px-4 py-20">
        <div className="max-w-2xl">
          <p className="text-xs font-semibold uppercase tracking-wide text-primary">
            The workspace
          </p>
          <h2 className="mt-2 text-3xl font-semibold tracking-tight text-foreground">
            One view from the first lead to the live engagement.
          </h2>
          <p className="mt-3 text-muted-foreground">
            The CRM keeps the commercial and delivery picture together, so a client handover does
            not become a hunt through inboxes.
          </p>
        </div>

        <div className="mt-10 grid gap-6 lg:grid-cols-3">
          <LeadWorkspacePreview className="lg:col-span-2" />
          <div className="grid gap-6">
            <AccountHealthPreview />
            <PipelinePreview />
          </div>
        </div>
      </section>

      <section id="features" className="border-y border-border bg-card">
        <div className="mx-auto max-w-6xl px-4 py-20">
          <div className="max-w-2xl">
            <p className="text-xs font-semibold uppercase tracking-wide text-primary">
              Built around the work
            </p>
            <h2 className="mt-2 text-3xl font-semibold tracking-tight text-foreground">
              Less chasing. More follow-through.
            </h2>
            <p className="mt-3 text-muted-foreground">
              Every feature answers a real operating question — what came in, who owns it, and what
              happens next.
            </p>
          </div>

          <div className="mt-10 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            {features.map((feature) => {
              const Icon = feature.icon

              return (
                <article
                  key={feature.title}
                  className="rounded-xl border border-border bg-background p-5"
                >
                  <span
                    className={`inline-flex h-10 w-10 items-center justify-center rounded-lg ${feature.tone}`}
                  >
                    <Icon className="h-5 w-5" aria-hidden="true" />
                  </span>
                  <h3 className="mt-4 text-sm font-semibold text-foreground">{feature.title}</h3>
                  <p className="mt-2 text-sm text-muted-foreground">{feature.body}</p>
                </article>
              )
            })}
          </div>
        </div>
      </section>

      <section id="how-it-works" className="mx-auto max-w-6xl scroll-mt-20 px-4 py-20">
        <div className="max-w-2xl">
          <p className="text-xs font-semibold uppercase tracking-wide text-primary">How it works</p>
          <h2 className="mt-2 text-3xl font-semibold tracking-tight text-foreground">
            A short brief. A clear handoff.
          </h2>
          <p className="mt-3 text-muted-foreground">
            We keep the process simple for you and visible for the team doing the work.
          </p>
        </div>

        <ol className="mt-10 grid gap-6 md:grid-cols-3">
          {steps.map((step, index) => (
            <li key={step.title} className="rounded-xl border border-border bg-card p-6">
              <span className="inline-flex h-9 w-9 items-center justify-center rounded-full bg-primary/10 text-sm font-semibold text-primary">
                {index + 1}
              </span>
              <h3 className="mt-4 text-sm font-semibold text-foreground">{step.title}</h3>
              <p className="mt-2 text-sm text-muted-foreground">{step.body}</p>
            </li>
          ))}
        </ol>
      </section>

      <section id="request-staffing" className="mx-auto max-w-6xl scroll-mt-20 px-4 pb-20">
        <div className="grid gap-8 rounded-2xl border border-border bg-card p-8 lg:grid-cols-[1.2fr_0.8fr] lg:p-10">
          <div>
            <p className="text-xs font-semibold uppercase tracking-wide text-primary">
              Request staffing
            </p>
            <h2 className="mt-2 text-3xl font-semibold tracking-tight text-foreground">
              Tell us what you need.
            </h2>
            <p className="mt-3 max-w-xl text-muted-foreground">
              Roles, headcount and a target start date are enough to begin. Our team will come back
              with a shortlist and a clear next step.
            </p>

            <div className="mt-6 flex flex-wrap items-center gap-3">
              <a
                href="mailto:hello@primepower.com.ph?subject=Staffing%20request"
                className="inline-flex items-center gap-2 rounded-md bg-primary px-4 py-2.5 text-sm font-medium text-primary-foreground hover:bg-primary/90 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-card"
              >
                Email the team
                <ArrowRight className="h-4 w-4" aria-hidden="true" />
              </a>
              <a
                href="tel:+63288881000"
                className="inline-flex items-center gap-2 rounded-md border border-border bg-background px-4 py-2.5 text-sm font-medium text-foreground hover:bg-secondary focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-card"
              >
                Call +63 2 8888 1000
              </a>
            </div>

            <p className="mt-4 text-xs text-muted-foreground">
              We reply within one business day. Your details are used only to respond to this
              request.
            </p>
          </div>

          <div className="rounded-xl bg-secondary/60 p-6">
            <h3 className="text-sm font-semibold text-foreground">What happens next</h3>
            <ol className="mt-4 flex flex-col gap-3 text-sm text-muted-foreground">
              <li className="flex gap-2">
                <span className="font-semibold text-people">1.</span>
                We read the brief and check the roles against current demand.
              </li>
              <li className="flex gap-2">
                <span className="font-semibold text-people">2.</span>
                We come back with a shortlist, a timeline and the next decision.
              </li>
              <li className="flex gap-2">
                <span className="font-semibold text-people">3.</span>
                Once you are ready, the engagement moves into one tracked record.
              </li>
            </ol>
          </div>
        </div>
      </section>
    </>
  )
}
