# CRM System Flow — Lead & Client Tracking

> System-level flow for the CRM subsystem. Business context: `business-flow.md`.
> Module inventory: `system-modules.md`. Decisions: `decisions/`.
> Items marked *(assumed)* are still-open assumptions. Confirmed design choices are recorded as ADRs
> in `decisions/` and linked inline.

## Purpose & boundary

The CRM subsystem owns the **client-side relationship**: capturing leads, qualifying them, working
opportunities, converting to clients, and retaining/tracking clients (contracts, follow-ups,
satisfaction). It does **not** own recruitment, deployment, timekeeping, payroll, or billing — those
belong to other departments and are consumed as **read-only feeds/mocked integrations** (ADR-0005,
ADR-0025).

## Object model (CRM-owned records)

Adopted from standard CRM design (Salesforce-style objects) adapted to a staffing agency.

| Object | Represents | Key fields | Notes |
| --- | --- | --- | --- |
| **Lead** | An unqualified/partially qualified inquiry | company name, contact name, email, phone, source, industry, location, service interest, est. headcount, owner, status, rating | Not yet a real account |
| **Company / Account** | An organization (prospect or client) | legal/trade name, industry, size, locations/sites, parent (branches), owner, status, tier | Parent/child supports multi-branch clients |
| **Contact** | A person at a company | name, role/decision-maker type (hiring manager, HR, procurement, finance), email, phone, primary flag | A company has many contacts |
| **Opportunity (Deal)** | A potential engagement/deal | name, pipeline stage, est. value, expected close date, probability, source, owner | Can exist before/after conversion |
| **Activity** | A logged touch or task (the quick log) | type (call/email/meeting/walk-in/task), subject, notes, due date, owner, related record | A `task` with a due date is a **follow-up** (module 04); mirrors a logged communication |
| **Communication** | Detailed stored communication (module 03, spec 11) | channel, direction, participants, content/body, duration, outcome, external id, attachments, conversation links (thread/parent), scheduled send (module 04), timestamps | Detail-rich, per lead/company/contact/deal/contract |
| **CommunicationTemplate** | Reusable wording for the composer (module 03 slice S5) | name, channel, subject line, body (with `{{merge}}` fields), active flag | A prefill only — a saved message never references one |
| **Contract** | A signed agreement | reference no., commercial header, rate cards (hourly/monthly/placement), term start/end, status, document | Upload required; drives renewals, billing and delivery context |
| **Client** | An account with a signed/active contract | (state on Company) status, tier, onboarding date, health | Modeled as a lifecycle state on Company, not a separate table (ADR-0001) |

## Two pipelines (best-practice split)

Staffing CRMs separate **raw lead qualification** from the **deal/opportunity pipeline** so
unqualified names don't clog forecasting.

- **Lead qualification pipeline:** `New → Contacted → Qualified → Disqualified / Nurture / Archived`
- **Opportunity (deal) pipeline:** `New/Inquiry → Qualified → Discovery/Needs Assessment →
  Proposal Sent → Negotiation → Won → Lost, plus On Hold` (ADR-0002)

## Lead lifecycle

```
New → Contacted → Qualified → Converted ──► Company(+Contact+Opportunity) + Client(prospect)
        │                          │
        └──► Disqualified           └──► Nurture (revisit later) / Lost / Archived
```

- **Convert** creates a Company + Contact(s) + Opportunity together (Salesforce-style) (ADR-0001).
- Conversion is the handoff point where the CRM record becomes reusable for deals, contracts,
  and client tracking.

## Client lifecycle

```
Prospect → Onboarding → Active → At-risk/Dormant → Churned → (Win-back)
```

Tiers (A/B/C) and health/status are used for prioritization and reporting *(assumed)*.

## "Lead & Client Tracking" module → submodules

Module 01 splits into the following submodules. All of them except duplicate detection/merge (5) and
advanced tiers/health are **built** (ADR-0006). Detailed implementation specs live in `specs/`
(numbered to match).

1. **Lead Capture & Intake** — intake from channels (walk-in, email, phone, referral, website,
   outbound); manual entry, bulk import; capture source & campaign; duplicate check on create.
2. **Lead Qualification & Scoring** — staffing checklist (headcount, roles/skills, start date,
   budget/rate, authority, site), lead rating (hot/warm/cold) / score, disqualification reason,
   nurture flag (ADR-0003).
3. **Lead Assignment & Ownership** — assign to a salesrep (manual/round-robin), reassign,
   ownership transfer rules.
4. **Lead Lifecycle & Status** — status/state machine, stale/aging detection, archive, activity-
   driven status updates.
5. **Duplicate Detection & Merge** — match rules (company name, email/domain, phone), warn vs
   block on create, merge records with audit trail.
6. **Company / Account Management** — company profiles, industry/size, sites/locations,
   parent/child (branches), account status & tier.
7. **Contact Management** — contact persons per company, decision-maker roles, primary contact,
   multiple channel handles.
8. **Lead-to-Client Conversion** — conversion wizard, created records, prospect → client state,
   service-department handoff trigger.
9. **Client Account Lifecycle** — client status/tier/health, owner, renewal linkage, dormancy/
   churn, win-back.

### Module 01 refresh (S0–S2 built — spec 16, ADR-0023–0026)

The refresh keeps the records above but consolidates the working surface:

- **Grouped sidebar + canonical routes** — Leads (Lead Workspace, Capture, Import), Accounts (Account
  Book, Contracts, Deployments, Billing) and Contacts. Qualification, assignment, lifecycle and
  conversion become saved views/actions inside the Lead Workspace; `/clients` is an Account Book view;
  `/companies` redirects to `/accounts`.
- **Lead Workspace** — URL-backed All / My Leads / Unassigned / Team / Needs qualification / Aging /
  Ready to convert / Archived views over the existing scoped lead queries, with rating and urgency
  filters, a manager workload panel (open leads, overdue follow-ups, queue, per-rep load and bulk
  transfer) and links from a new lead's duplicate advisory to the saved and existing records.
- **Account 360** — five grouped tabs: Overview (journey, health, lifecycle), People & Sites,
  Commercial (opportunities, contracts/rate cards, billing), Delivery (read-only feeds), Engagement
  (communications, satisfaction, activities).
- **Commercial contracts** — commercial header + `contract_rate_cards` (hourly/monthly/placement),
  computed client value/cost/margin; bill rates visible to salesreps, pay/margin manager+ (ADR-0024).
- **Read-only department feeds** — Recruitment job orders, Deployment assignment, Timekeeping
  summaries and Finance AR/invoices; no CRM tables, no mutation UI, source-labelled (ADR-0025).
- **Health & manager insight** — computed, explainable account flags and a manager dashboard strip;
  no automatic lifecycle transition (ADR-0023).
- **Public surface** — simple landing refresh, embedded request-staffing intake, modal sign-in and
  all-role CSV import with ownership rules (ADR-0026).


## CRM module roadmap (ADR-0014)

The CRM's five data modules (`system-modules.md`) are ordered so each builds on the one before; the
dashboard and AI reports are cross-cutting presentation layers, not data modules:

| Module | Name | Depends on | Spec |
| --- | --- | --- | --- |
| 01 | Lead & Client Tracking | — | `01`–`09` **(baseline built)** + `16` refresh (S0–S2 built; S3–S19 planned) |
| 02 | Opportunity Pipeline Visualization | `Opportunity` record (08) | `10` **(built)** |
| 03 | Communication History Management | objects from 01–02 | `11` **(built)** |
| 04 | Follow-up Reminder System | 03 + notifications | `12` **(built)** |
| 05 | Client Satisfaction & Survey | clients + contacts | `13` **(built)** |
| 06 | Dashboard (cross-cutting read layer) | 01–05 | `14` (D1 + D2 built) |
| 07 | AI Management Reports | 01–06 | `15` **(S1 + S2 built)** |

The dashboard layer shipped **D1 with simulated data** (spec `14`, ADR-0020) before module 05 S4,
for the presentation, and **D2** now reads the real endpoints. Its former **D3** is now **Module 07 —
AI Management Reports** (spec `15`, ADR-0021): a read-only presentation module with its own page,
three report variants, and the free Groq layer with a rules fallback. Module 07 owns no table
and is not a data module.
Duplicate detection/merge and **advanced/stored** tier scoring remain module-01 deferrals (ADR-0006).
The refresh adds computed, explainable health flags, not automatic lifecycle moves (ADR-0023).

## Module 02 → submodules (built, ADR-0015)

1. **Pipeline Board** — Kanban of open deals, drag-and-drop + accessible move menu, column totals.
2. **Deal Management** — flat `/opportunities` CRUD, deal list and detail page.
3. **Deal Stage & Outcome** — any-to-any stage moves with rules, won/lost, reopen, stage history.
4. **Deal Activity & Timeline** — log touches/next actions; the deal timeline.
5. **Pipeline Forecast** — board-header/list `meta` (weighted value, per-stage, win rate).

A won deal **prompts contract creation** (reusing the contract upload with `opportunity_id`); the
mocked Service handoff still fires only on `mark-signed` (ADR-0015 §4).

## Module 03 → delivery slices (built, ADR-0017)

1. **S1 Communication log & history** *(built)* — the `communications` table, log a channel-aware entry
   against any subject, the history book and detail screen, permission/scoping inheritance. A logged
   entry also writes an `Activity` mirror, so the existing timelines stay the merged feed.
2. **S2 Email compose, send & retry** *(built)* — email is a **mocked** gateway (`EmailGateway` + `MockEmailGateway`,
   `CRM_EMAIL_SIMULATE_FAILURE` forces failure); inbound is pulled by a sync command, never live.
3. **S3 Attachments** *(built)* — implements the polymorphic `attachments` table deferred in
   `00-data-model.md`: up to 5 files of 10 MB (pdf/png/jpeg) per message on a private disk, downloaded
   only through an authorized endpoint. Files obey the message's state — changeable while it is still
   editable or never delivered, fixed once `sent`/`received`.
4. **S4 Threading** *(built)* — a reply joins the message it answers (`thread_id` = the conversation's
   root, `in_reply_to_id` = the message answered); the conversation list and a thread view read an
   exchange back in order, and a pulled inbound reply is filed into the conversation it answers.
5. **S5 Templates** *(built)* — reusable wording with `{{merge}}` fields rendered against the record;
   manager+ author them, every role uses them from the composer, and a saved message never references one.

`Activity` stays the lightweight quick log; `Communication` is the detailed record (ADR-0017 §1). Each
slice ships as its own PR (ADR-0016). Nothing scheduled runs — due work is dispatched on demand in
module 04.

## Module 04 → delivery slices (built — S1–S4, ADR-0018)

1. **S1 Follow-up tasks & the worklist** *(built)* — a to-do you owe (`Activity`, `type = task`, with the
   `due_at` and `open`/`done`/`cancelled` status the model already reserved), the one worklist that
   lists both shapes by due time, and `Lead.next_follow_up_at` finally maintained.
2. **S2 Scheduled outbound messages** *(built)* — the reserved `CommunicationStatus.scheduled`: an outbound
   email with a `scheduled_for`, writable and readable but not yet happened (no mirror activity, the
   clock untouched); **Send now** sends it early, and an admin **Dispatch due sends** (or
   `communication:send-due`) sends what has fallen due through the mocked gateway, once. The worklist
   carries both shapes (`filter[kind]`), and the book gains a `due` filter.
3. **S3 Notifications & the due run** *(built)* — assignment and due notifications on the existing bell,
   each naming the item it is about; `reminded_at` keeps repeated runs
   idempotent, a reschedule re-arms the reminder, and an admin **Dispatch due reminders** (or
   `follow-ups:notify-due`) flags what has come due. A send that fails with nobody watching tells the
   author.
4. **S4 Generated renewal & check-in reminders** *(built)* — the renewals deferral ADR-0013 §3 parked
   here: tasks generated from contracts expiring inside `crm.contracts.expiring_soon_days`, clients
   with no communication since they came aboard (older than `crm.follow_ups.check_in_days`, churned
   ones included) and stale leads, keyed by `source_key` so a run is idempotent, with every skipped
   record reported and its reason (no owner, already standing, somebody already has work open on it).

Automatic client aging is **not** here — ADR-0018 §6 keeps it a standalone deferral.

## Module 05 → delivery slices (built — S1–S4, ADR-0019)

The last CRM module (spec `13`): close the loop after the sale.

1. **S1 Survey library** *(built)* — reusable templates (`Survey` + ordered `SurveyQuestion`, five question
   types) with activate/retire/restore/duplicate; the question set freezes once a survey has been
   sent.
2. **S2 Invitations & the public form** *(built)* — sending a survey to a company needs that
   account's `update` right, goes through the mocked email gateway and writes a `Communication`
   (module 03), so an invitation is cancelled or resent, never deleted; the client answers through a
   tokenized public link built from `CRM_SURVEY_URL` (48-char single-use token, `expired` derived on
   read), and the submission is validated by question type, stored once, scored (CSAT/NPS/band) and
   flagged for attention.
3. **S3 Responses & the satisfaction view** *(built)* — a response is immutable and read back in the
   order it was asked; the book filters by survey/company/band/attention/date and searches, the
   account's Satisfaction tab carries its own latest score, split and trend, and the summary derives
   CSAT, NPS, the response rate (answers over answerable sends — not failed or withdrawn ones) and
   the attention count for the caller's scope. Nothing is stored twice: the numbers are aggregates
   over the responses on read.
4. **S4 Closed-loop follow-through** *(built)* — an unhappy answer
   notifies the account owner and raises a module-04 follow-up task on the company
   (`source_key satisfaction:{response}`); the owner resolves it with a note (the answer keeps its
   flag, the resolution records who closed the loop and how, and the task stays open until the call
   actually happens). Unresolved answers drive the summary's count and the attention filter.

Automatic sends (on signing/renewal/churn) and reminder emails are deliberately unbuilt (ADR-0019).

## Dashboard (D1 + D2 built — ADR-0020)

The cross-cutting read layer over modules 01–05 (`specs/14`, ADR-0020), shipped as D1 after module 05
S3 and before S4 for the presentation. **D1** shipped `/dashboard` with simulated data from one
deterministic fixture module, recorded in spec 14 / ADR-0020 rather than shown on the page. **D2**
replaced it with the real read endpoints:
`/api/v1/dashboard` (scope, KPIs, charts, alerts, activity, rules-based `ai`),
`/dashboard/worklist` (the merged needs-attention rows) and `/dashboard/staff-performance` (CRM
scorecards + core2 HR through the `HrGateway` mock). The frontend calls all three in parallel and
formats raw values in one adapter; the fixture file is deleted, and the page handles loading, empty,
error/retry and `hr_available: false` (`—`, never `0`). It is role-aware — a salesrep reads their
book, a manager the team, an admin the org — and reuses `DashboardShell`; four primary + four
secondary KPI cards, a line chart and a donut, a needs-attention table, alerts, recent activity, a
staff scorecard and the rules-based AI summary all link to existing module pages. The dashboard's
`ai` block stays rules-based; it links to `/reports` for generated reports. No table and no module
rule change.

## Module 07 → AI Management Reports (S1 + S2 built — ADR-0021)

A read-only presentation module over the dashboard data (`specs/15`). It owns the `/reports` page and
an **AI Reports** sidebar group, and adds no table. **S1** ships `GET /reports/management` and the
standard rules reports for three variants — Executive, Pipeline and Client Health — scoped like the
dashboard. The report always carries its source, model, timestamp, cached flag and fallback, with
exactly three insight cards and four action links to existing pages. **S2** adds
`POST /reports/management/generate`: Laravel sends an aggregated, PII-free context to the `ai/`
service, which calls Groq's free tier (`openai/gpt-oss-20b`) behind a 2.5-second provider timeout
inside Laravel's 10-second call (one retry on a connection timeout, for a cold public service). A
successful report is cached by user + range + variant + context
hash for 15 minutes; a 5-minute per-user/variant cooldown, 30/user/day and 800/org/day cache counters
protect the free quota. A disabled switch, missing key, 429, timeout, provider error or invalid
JSON/IDs returns the standard report with a `fallback.reason` and an inline info alert; `GET` returns
cached model prose when it exists and never calls the provider. The model receives counts and stage
labels only and returns fixed insight/action IDs; Laravel maps those IDs to real routes. Record
catch-up summaries (S3) and message drafting (S4) are future submodules, not part of S1/S2.

## Role data visibility (ADR-0004)

Enforced server-side.

| Capability | salesrep | manager | admin | superadmin |
| --- | --- | --- | --- | --- |
| Leads / clients | Own only | Team only | All | All |
| Sensitive data (pay rates, cost, margin, full billing) | Hidden; client-facing bill rates and contract values visible | View | Manage | Manage |
| Reports / analytics | Own | Team | All | All |
| App/system settings | — | — | Manage | Manage |
| Low-level DB access | — | — | — | Yes |

## Department integration contracts (read-only feeds, mocked)

Each integration sits behind an **interface + a mock driver** returning deterministic assumed data
(ADR-0005, ADR-0013 §4, ADR-0025). They live in `backend/app/Integrations/` (interface, `Mock*`
driver, readonly DTO), bound in `AppServiceProvider`; the failure path is forced with a config flag
(`CRM_HANDOFF_SIMULATE_FAILURE=true` for the Service handoff) rather than random failure. Contract
shapes are defined with OpenAPI (per the API convention).

**Read-only feed boundary:** the CRM consumes the external department's **read side only**. It does
not create job orders, deployments, timesheets or invoices, and it stores none of them as CRM tables.
The only outbound write remains the Service handoff on signing/retry.

| Integration | Direction | Trigger | Payload (summary) |
| --- | --- | --- | --- |
| Service / operations | CRM → Service | **Contract signed** (`mark-signed`) or handoff retry | client + contract + manpower requirement; returns `sent`/`failed` |
| Recruitment | Recruitment → CRM | Company delivery view | job order reference, role, requested/filled headcount, status, priority, target start, recruiter, last update |
| Deployment / assignment | Deployment → CRM | Company delivery view / `/deployments` | deployment reference, contract/job-order, role, site, status, active/filled headcount, start/end, fill rate |
| Timekeeping | Timekeeping → CRM | Company delivery view | per-deployment period, approved/pending hours, last submission, status |
| Finance | Finance → CRM | Company billing view / `/billing` | AR summary, invoices, due dates, balances, aging, payment status, credit terms |
| Legal / documents | — | — | **Deferred**; contract upload is CRM-owned in Phase 1 |
| HR / employee info | — | — | **Deferred**; no employee-level data in this iteration |

**Shipped before the refresh:** Service (handoff on signing + retry) and a small Finance billing
snapshot. **Spec 16 consumes:** Recruitment job orders, Deployment status, Timekeeping summaries and an
expanded Finance AR feed as read-only mocks. Legal and HR remain deferred.

## Decisions

Recorded as ADRs in `decisions/` (read them before changing the design):

- `decisions/0001-lead-client-object-model.md` — object model & conversion
- `decisions/0002-opportunity-pipeline-stages.md` — pipeline stages
- `decisions/0003-lead-qualification-scoring.md` — qualification & rating
- `decisions/0004-role-data-visibility.md` — role visibility/ownership
- `decisions/0005-mocked-integrations.md` — integration mock strategy
- `decisions/0006-phase-1-scope.md` — Phase-1 submodule scope
- `decisions/0014-crm-module-roadmap.md` — module order & spec numbering
- `decisions/0015-opportunity-pipeline-conventions.md` — pipeline stage moves, board, won→contract
- `decisions/0016-delivery-cadence-and-slices.md` — module specs ship as declared delivery slices
- `decisions/0017-communication-history-model.md` — `Communication` vs `Activity`, mocked email, scoping
- `decisions/0018-follow-up-reminder-model.md` — follow-ups as tasks + scheduled messages, on-demand dispatch
- `decisions/0019-client-satisfaction-conventions.md` — surveys as templates, tokenized public form, CSAT/NPS, closed loop
- `decisions/0023-module-01-workspace-consolidation.md` — grouped sidebar, Lead Workspace, Account 360, computed health
- `decisions/0024-contract-rate-cards.md` — commercial header, rate cards, billing units, margin visibility
- `decisions/0025-read-only-external-feeds.md` — job orders, deployments, timekeeping and Finance as read-only feeds
- `decisions/0026-public-acquisition-and-sign-in.md` — landing refresh, public intake, modal sign-in, all-role CSV import

Still open / to be decided later: dedup/merge rules (module-01 deferrals). The satisfaction survey
structure is settled by `specs/13-client-satisfaction-survey.md` (ADR-0019). Follow-up/reminder rules
are settled by `specs/12-follow-up-reminders.md` (ADR-0018), except automatic client aging (still
deferred). The contract and rate-card data model is settled by `specs/16-module-01-refresh.md`
(ADR-0024), extending `specs/00-data-model.md` and `specs/09-client-account-lifecycle.md`; the deal
pipeline is specified in `specs/10-opportunity-pipeline-visualization.md` (ADR-0015). The external
feeds are read-only and add no tables (ADR-0025).
