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
| **Activity** | A logged touch or task | type (call/email/meeting/walk-in/task), subject, notes, due date, owner, related record | Drives follow-ups |
| **Communication history** | Detailed stored communication | channel, direction, participants, content/body, attachments, timestamps | Detail-rich, per client/lead |
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

The module splits into the following submodules. **Phase 1** covers the full tracking loop —
submodules 1–4 and 6–8; duplicate detection/merge (5) and advanced tiers are deferred (ADR-0006).
Detailed implementation specs live in `specs/` (numbered to match).

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

Related but **separate** modules (per `system-modules.md`): opportunity pipeline visualization,
communication history, follow-up reminders, client satisfaction & survey.

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

Each integration sits behind an **interface + a fake/mock driver** returning seeded data, toggled
by a config flag (e.g. `INTEGRATIONS_MODE=mock`) (ADR-0005). Contract shapes are defined with
OpenAPI (per the API convention).

| Integration | Direction | Trigger | Payload (summary) |
| --- | --- | --- | --- |
| Service / operations | CRM → Service | Opportunity won / conversion | client + contract + manpower requirement |
| Recruitment | Service/CRM → Recruitment | Job order created | positions, skills, headcount, site |
| Finance | CRM ← Finance | Contract active | billing status, invoices, AR state (read-only in CRM) |
| Legal / documents | CRM → Legal | Contract uploaded | document + metadata; validation/ack |
| HR | CRM ← HR | Client view | deployed employees, performance summary (read-only) |

## Decisions

Recorded as ADRs in `decisions/` (read them before changing the design):

- `decisions/0001-lead-client-object-model.md` — object model & conversion
- `decisions/0002-opportunity-pipeline-stages.md` — pipeline stages
- `decisions/0003-lead-qualification-scoring.md` — qualification & rating
- `decisions/0004-role-data-visibility.md` — role visibility/ownership
- `decisions/0005-mocked-integrations.md` — integration mock strategy
- `decisions/0006-phase-1-scope.md` — Phase-1 submodule scope

Still open / to be decided later: contract & billing data model, follow-up/reminder rules,
satisfaction survey structure, and dedup/merge rules (deferred to Phase 2).
