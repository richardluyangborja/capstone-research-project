# CRM System Flow — Lead & Client Tracking

> System-level flow for the CRM subsystem. Business context: `business-flow.md`.
> Module inventory: `system-modules.md`. Decisions: `decisions/`.
> Items marked *(assumed)* are still-open assumptions. Confirmed design choices are recorded as ADRs
> in `decisions/` and linked inline.

## Purpose & boundary

The CRM subsystem owns the **client-side relationship**: capturing leads, qualifying them, working
opportunities, converting to clients, and retaining/tracking clients (contracts, follow-ups,
satisfaction). It does **not** own recruitment, deployment, payroll, or billing — those belong to
other departments and are consumed through **mocked integrations**.

## Object model (CRM-owned records)

Adopted from standard CRM design (Salesforce-style objects) adapted to a staffing agency.

| Object | Represents | Key fields | Notes |
| --- | --- | --- | --- |
| **Lead** | An unqualified/partially qualified inquiry | company name, contact name, email, phone, source, industry, location, service interest, est. headcount, owner, status, rating | Not yet a real account |
| **Company / Account** | An organization (prospect or client) | legal/trade name, industry, size, locations/sites, parent (branches), owner, status, tier | Parent/child supports multi-branch clients |
| **Contact** | A person at a company | name, role/decision-maker type (hiring manager, HR, procurement, finance), email, phone, primary flag | A company has many contacts |
| **Opportunity (Deal)** | A potential engagement/deal | name, pipeline stage, est. value, expected close date, probability, source, owner | Can exist before/after conversion |
| **Activity** | A logged touch or task (the quick log) | type (call/email/meeting/walk-in/task), subject, notes, due date, owner, related record | Drives follow-ups; mirrors a logged communication |
| **Communication** | Detailed stored communication (module 03, spec 11) | channel, direction, participants, content/body, duration, outcome, external id, attachments, timestamps | Detail-rich, per lead/company/contact/deal/contract |
| **Contract** | A signed agreement | reference no., term start/end, rate/terms summary, status, attachments (pdf/png/jpeg) | Upload required; drives renewals & billing handoff |
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

## CRM module roadmap (ADR-0014)

The CRM is five modules (`system-modules.md`). Module 01 is done; the rest are ordered so each builds
on the one before:

| Module | Name | Depends on | Spec |
| --- | --- | --- | --- |
| 01 | Lead & Client Tracking | — | `01`–`09` **(built)** |
| 02 | Opportunity Pipeline Visualization | `Opportunity` record (08) | `10` **(built)** |
| 03 | Communication History Management | objects from 01–02 | `11` *(S1–S3 built — 5 slices)* |
| 04 | Follow-up Reminder System | 03 + notifications | `12` |
| 05 | Client Satisfaction & Survey | clients + contacts | `13` |

The AI service (dashboard + management reports + suggestions) is cross-cutting and built after 01–05.
Duplicate detection/merge and advanced tiers/health are still-open **module-01** deferrals (ADR-0006).

## Module 02 → submodules (built, ADR-0015)

1. **Pipeline Board** — Kanban of open deals, drag-and-drop + accessible move menu, column totals.
2. **Deal Management** — flat `/opportunities` CRUD, deal list and detail page.
3. **Deal Stage & Outcome** — any-to-any stage moves with rules, won/lost, reopen, stage history.
4. **Deal Activity & Timeline** — log touches/next actions; the deal timeline.
5. **Pipeline Forecast** — board-header/list `meta` (weighted value, per-stage, win rate).

A won deal **prompts contract creation** (reusing the contract upload with `opportunity_id`); the
mocked Service handoff still fires only on `mark-signed` (ADR-0015 §4).

## Module 03 → delivery slices (spec written, ADR-0017)

1. **S1 Communication log & history** *(built)* — the `communications` table, log a channel-aware entry
   against any subject, the history book and detail screen, permission/scoping inheritance. A logged
   entry also writes an `Activity` mirror, so the existing timelines stay the merged feed.
2. **S2 Email compose, send & retry** *(built)* — email is a **mocked** gateway (`EmailGateway` + `MockEmailGateway`,
   `CRM_EMAIL_SIMULATE_FAILURE` forces failure); inbound is pulled by a sync command, never live.
3. **S3 Attachments** *(built)* — implements the polymorphic `attachments` table deferred in
   `00-data-model.md`: up to 5 files of 10 MB (pdf/png/jpeg) per message on a private disk, downloaded
   only through an authorized endpoint. Files obey the message's state — changeable while it is still
   editable or never delivered, fixed once `sent`/`received`.
4. **S4 Threading** *(next)* — `thread_id`/`in_reply_to`; conversations and replies.
5. **S5 Templates** *(candidate)* — reusable templates with merge fields.

`Activity` stays the lightweight quick log; `Communication` is the detailed record (ADR-0017 §1). Each
slice ships as its own PR (ADR-0016). Nothing scheduled runs — automatic reminders are module 04.

## Role data visibility (ADR-0004)

Enforced server-side.

| Capability | salesrep | manager | admin | superadmin |
| --- | --- | --- | --- | --- |
| Leads / clients | Own only | Team only | All | All |
| Sensitive data (rate cards, margins, billing) | Hidden | View | Manage | Manage |
| Reports / analytics | Own | Team | All | All |
| App/system settings | — | — | Manage | Manage |
| Low-level DB access | — | — | — | Yes |

## Department integration contracts (mocked)

Each integration sits behind an **interface + a mock driver** returning deterministic seeded data
(ADR-0005, ADR-0013 §4). In Phase 1 they live in `backend/app/Integrations/` (interface, `Mock*`
driver, readonly DTO), bound in `AppServiceProvider`, and the failure path is forced with a config
flag (`CRM_HANDOFF_SIMULATE_FAILURE=true` for the Service handoff) rather than random failure. Contract
shapes are defined with OpenAPI (per the API convention).

| Integration | Direction | Trigger | Payload (summary) |
| --- | --- | --- | --- |
| Service / operations | CRM → Service | **Contract signed** (`mark-signed`) | client + contract + manpower requirement |
| Recruitment | Service/CRM → Recruitment | Job order created | positions, skills, headcount, site |
| Finance | CRM ← Finance | Contract active | billing status, invoices, AR state (read-only in CRM) |
| Legal / documents | CRM → Legal | Contract uploaded | document + metadata; validation/ack |
| HR | CRM ← HR | Client view | deployed employees, performance summary (read-only) |

**Shipped in Phase 1:** Service (handoff on signing + retry) and Finance (read-only billing
snapshot). Recruitment, Legal and HR remain interface-only mocks to be added with their consuming
features.

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

Still open / to be decided later: follow-up/reminder rules, satisfaction survey structure, and
dedup/merge rules (module-01 deferrals). The contract and billing data model is settled — see
`specs/00-data-model.md` (Contract), `specs/09-client-account-lifecycle.md` and ADR-0013; the deal
pipeline is specified in `specs/10-opportunity-pipeline-visualization.md` (ADR-0015).
