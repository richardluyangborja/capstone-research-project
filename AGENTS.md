# AGENTS.md

## Repository state

Scaffolded monorepo (**no commits yet**): three separate apps — `backend/`, `frontend/`, `ai/` — plus
spec/decision docs. There is no shared package; `frontend/` talks to `backend/` only over the
`/api/v1` REST API. `resources/*.md` is the source of truth for requirements; update it when
decisions change.

`decisions/` holds decisions made as development progresses (see `decisions/README.md` for the
`NNNN-title.md` + Proposed/Accepted convention). Read existing ADRs before proposing design changes,
record new architectural/technical decisions there, and mirror anything that changes agent workflow
into this file.

## Stack & layout (from `resources/system-tech.md`)

- `frontend/` — React 19 + Vite + TypeScript SPA (Tailwind, `react-router-dom`, `axios`, `lucide-react`)
- `backend/` — Laravel 13 API; versioned REST under `/api/v1` (`routes/api.php`)
- `ai/` — Python FastAPI service (lightweight) for AI/ML reports (`resources/system-ai-feature.md`)
- `docker-compose.yml` at the root — local Postgres only (`db` service); the apps run on the host
- Single git repo at the root tracks all three; remote `origin` is set

## Commands

Run from the repo root unless a directory is noted; start the DB first.

- **DB:** `docker compose up -d --wait db` (Postgres :5432; credentials in `backend/.env`)
- **Backend (`backend/`):** `composer install` · `php artisan migrate` · `php artisan serve`
  (API at http://localhost:8000; health `/api/v1/health`)
  - Test: `php artisan test` (single: `php artisan test --filter=Name`); format: `vendor/bin/pint`
- **Frontend (`frontend/`):** `npm install` · `npm run dev` (http://localhost:5173)
  - Verify: `npm run lint` · `npm run test` · `npm run build`; format: `npm run format`
- **AI (`ai/`):** `pip install -r requirements.txt` · `uvicorn app.main:app --reload --port 8001`

`backend/` has its own `AGENTS.md` (Laravel Boost guidelines) and `opencode.json` MCP config — read
it before backend work. Backend asset tooling (`backend/package.json`, `backend/vite.config.js`) is
Laravel's own and is separate from `frontend/`.

**Implemented:** JWT auth (`php-open-source-saver/jwt-auth`, `api` guard — ADR-0008) with
`POST /api/v1/auth/login`, `GET /api/v1/auth/me`, `POST /api/v1/auth/logout`; teams + roles on
`users` (seeded, no faker).

- **Submodule 01 — Lead Capture & Intake:** `/api/v1/leads` (index/store/show/destroy +
  `POST /leads/{id}/restore`), advisory duplicate check, role/ownership scoping (ADR-0004).
- **Submodule 02 — Lead Qualification & Scoring:** `PATCH /leads/{id}/qualification`,
  `POST /leads/{id}/rate|qualify|disqualify|nurture`. Minimum criteria (headcount + roles + a
  contact channel) are enforced on `/qualify` (422 lists what's missing); a weighted checklist
  score (0–100) and derived hot/warm/cold rating are stored; edits are blocked once
  disqualified/converted. `filter[status|rating|urgency]` accept comma-separated values.
- **Submodule 03 — Lead Assignment & Ownership:** `POST /leads/{id}/assign|claim`,
  `POST /leads/bulk-assign|bulk-transfer`, `GET /leads/summary`, `GET /users/assignable`; database
  notifications + `GET /notifications`, `POST /notifications/{id}/read|read-all`. Salesrep → self
  only, manager → their team, admin → any (ADR-0004). The unassigned queue is a **shared, claimable
  pool** visible to all roles; a salesrep must claim before editing. Reassignment writes an
  `Activity` (type `system`) and notifies the new owner. Round-robin distribution is **deferred**
  (spec 03 §3).
- **Submodule 04 — Lead Lifecycle & Status:** `PATCH /leads/{id}/status`,
  `POST /leads/{id}/archive`, `POST /leads/{id}/activities`, `GET /leads/{id}/timeline`,
  `GET /leads?filter[stale]=true|at_risk|archive`. A state machine (`LeadStatus::allowedTransitions`)
  rejects illegal moves with 422; every change stamps `status_changed_at`, refreshes
  `last_activity_at`, and records a `system` activity via the `LeadStatusChanged` event. The status
  endpoint **cannot bypass submodule 02** (qualifying still needs the minimum criteria,
  disqualifying a reason); `qualify|disqualify|nurture` are wrappers over `Lead::transitionTo()`.
  `converted` is terminal and is only reached by the submodule-08 conversion flow (ADR-0009).
  Aging (stale/at-risk/archive-due) is **computed on read** from `config/crm.php`
  (`CRM_STALE_DAYS` 14 / `CRM_AT_RISK_DAYS` 30 / `CRM_ARCHIVE_DAYS` 60). `archived` is a status, not a
  delete: archived leads are hidden from default lists and counts unless
  `filter[status]=archived` (or `filter[archived]=true`). The old
  `GET /leads/{id}/activities` was replaced by `/timeline`.
- **Submodule 06 — Company / Account Management:** `/api/v1/companies` (index/store/show/update/
  destroy + `POST /companies/{id}/restore`), nested `companies/{id}/sites` (list/store/update/delete)
  and `companies/{id}/activities` (list + log). `reference` is `CO-YYYY-NNNN`; `name` is soft-unique
  (a name collision returns 201 with a `duplicates` advisory array). Parent/child links form
  branches — cycles are rejected (422), a branch inherits nothing, and the parent exposes a branch
  *count* only (no roll-up). One site per company is `is_primary`; the first site added becomes
  primary and deleting the primary promotes the next. `counts` reports
  `{ sites, branches, contacts, opportunities, activities }`; the contract count arrives with 09.
  **Permissions (ADR-0010):** every role may create manually (a salesrep's company is theirs and
  starts as `prospect`); profile updates are own/team/all; **delete/restore, sites and branch links
  are manager+**; `status` may be set at creation by manager+ and changed later only by an admin.
  Activity payloads are shared with leads via `ActivityResource`.
- **Submodule 07 — Contact Management:** nested `GET/POST /companies/{id}/contacts` plus the flat
  book — `GET /contacts` (paginated, `filter[company_id|role_type|status]` + `search`),
  `GET/PATCH/DELETE /contacts/{id}`, `POST /contacts/{id}/restore` and
  `POST /contacts/{id}/make-primary`. Contacts carry `role_type` (hiring manager / HR / procurement /
  finance / executive / other), a single `email`/`phone`/`mobile`, `status` and `is_primary` (ADR-0011
  §6). **Exactly one primary per company among active contacts**: the first contact added is primary,
  `make-primary` moves the flag, deleting or deactivating the primary promotes the oldest remaining
  active contact, and an inactive contact can never lead. A repeated email on the same company is an
  advisory (`duplicates` on the 201/200, never a block); `company_id` is not updatable, and contacts
  are never hard deleted (`status=inactive` for pickers, soft delete + restore for retired rows).
  **Permissions (ADR-0011 §2):** a contact inherits its company's `update` rights, so a salesrep
  maintains the people on their own accounts — the manager+ gate that covers sites/branches does
  **not** apply. `ContactPolicy` also gates `makePrimary`/`restore` and reserves force-delete for
  admins. Events: `ContactCreated`, `ContactPrimaryChanged`.
- **Submodule 08 — Lead-to-Client Conversion:** `GET /leads/{id}/conversion-preview` (suggested
  company/contact/opportunity mapping + duplicate candidates), `POST /leads/{id}/convert` (atomic:
  company + contact(s) + first deal, then `lead.status = converted` with `converted_company_id` /
  `converted_opportunity_id`) and the read-only `GET /companies/{id}/opportunities`
  (`filter[stage|open]`, `meta { total, open }`). The `Opportunity` model uses `OPP-YYYY-NNNN`
  references, `stage`/`probability` defaults from `OpportunityStage` (`qualified` = 20) and a
  `scopeVisibleTo` (owner/team/all). Only `qualified` leads convert (422 otherwise); a second
  attempt is 409; `link_company_id` must be a visible company (422) — a company in the caller's
  scope is reused instead of creating one, and its status is left alone. A salesrep-opened deal has
  `amount = null` (the wizard's amount is ignored); a manager+ conversion defaults the amount to the
  lead's sensitive `budget_rate`. `LeadPolicy::convert` delegates to `update`, so a salesrep must own
  the lead. A converted lead leaves the qualified worklist and gains a `system` `Converted into …`
  activity. Events: `LeadConverted`, `OpportunityCreated`. **Not built here:** the deal pipeline
  (stage moves, won/lost, Kanban) and contracts — a deal is only created and viewed.
- **Submodule 09 — Client Account Lifecycle:** `/companies/{id}/contracts` (list + multipart
  upload), `/contracts/{id}` (show/update/delete/restore), `POST /contracts/{id}/mark-signed`,
  `GET /contracts/{id}/file` (authorized download), `PATCH /companies/{id}/lifecycle`,
  `POST /companies/{id}/reactivate`, `POST /companies/{id}/handoff/retry`,
  `GET /companies/{id}/billing`. `Contract` is a `CON-YYYY-NNNN` record with a private-disk document,
  a renewal chain (`renewal_of_contract_id`) and a state machine (`draft → sent → signed →
  expired|terminated`); **`signed` is only reachable via `mark-signed`** (a `PATCH` cannot set it) and
  termination is manager+. Signing commits first and then activates the client + runs the mocked
  Service handoff (`ContractSigned` → `HandoffContractToService` → `Company::handoffToService()`, the
  same path the retry endpoint uses); a failed handoff leaves the contract signed and the client in
  `onboarding` with `handoff_status = failed` + a retry, and repeat business never resets an
  established client. `CompanyStatus::allowedTransitions()` drives the lifecycle (manager+,
  churn requires a reason, `reactivate` only from `churned`, `client_since` never reset); aging is
  **manual** in Phase 1. A `signed` contract blocks deleting the company. `ContractPolicy` inherits
  the account's rights; billing is manager-only. Events: `CompanyStatusChanged`, `ContractSigned`,
  `CompanyActivated`, `CompanyChurned`. **Not built here:** the deal pipeline, contract
  versioning/e-signature, renewal reminders and automatic aging (ADR-0013).
- **Module 02 — Opportunity Pipeline Visualization:** the deal pipeline on the `Opportunity` record
  (ADR-0014, ADR-0015). Flat `/api/v1/opportunities` (index/store/show/update/destroy +
  `POST /{id}/restore`) with `filter[stage|owner|company|open|source|close_window]` + `search` and a
  `summary` block; a deal is created directly on a visible company (owner = the creator) or by
  conversion, and the book is role-scoped (salesrep own, manager team, admin all). `POST
  /opportunities/{id}/stage` moves **any-to-any with rules** from `OpportunityStage::allowedTransitions()`
  — a self-move and terminal→terminal are 422, `lost` requires a reason, entering `won`/`lost` stamps
  `actual_close_date` (reopening clears it + the lost reason), and every move stamps `stage_changed_at`,
  refreshes `last_activity_at` and writes a `system` activity (`OpportunityStageChanged`). `amount` and
  the owner stay manager+ (`can_set_amount`/`can_assign`). `GET /pipeline` returns the open stages as
  columns (count/value/weighted) plus the closed deals and a forecast `meta` (open & weighted value,
  win rate, average deal, stalled count from `crm.pipeline.stuck_days`, default 21).
  `GET /opportunities/{id}/timeline` + `POST /opportunities/{id}/activities` are the deal timeline
  (logging a touch refreshes `last_activity_at`, so staleness tracks real work). **`won` does not hand
  off to Service** — it surfaces **Create contract from deal**, which deep-links to the company contract
  upload prefilled with `opportunity_id`; the mocked Service handoff still fires only on `mark-signed`
  (ADR-0013 §5). Events: `OpportunityStageChanged`, `OpportunityWon`, `OpportunityLost`
  (`OpportunityCreated` now also fires on manual creation). **Not built here:** the
  dashboard/management reports (the AI module).

Frontend routes: `/login`, `/leads` (views: All / My Leads / Unassigned / Team / Archived, inline
owner control, bulk assign, status/rating/aging columns, "Stale only" filter), `/leads/new`,
`/leads/:id` (qualification panel + lifecycle control + ownership + activity form + timeline +
"Convert to client" when convertible, converted banner), `/leads/:id/convert` (3-step conversion
wizard), `/qualification` (worklist), `/assignment` (unassigned queue, team workload, bulk transfer),
`/lifecycle` (aging / stale triage worklist), `/conversion` (qualified leads awaiting conversion),
`/clients` (client book: accounts past prospect, lifecycle/tier filters, contract counts, links to
the account), `/companies` (account book: status/tier filters and
pagination), `/companies/new` (manual creation), `/companies/:id` (Overview / Contacts / Sites /
Opportunities (row links + "Add deal", engagements summary links to the board) / Contracts /
Activities tabs; sites and branch links are manager-only,
contacts are editable by whoever owns the account, the Overview carries the client-lifecycle panel,
the failed-handoff retry banner and the manager-only billing snapshot), `/contacts` (global contact
book: search + role/status/
company filters, rows link back
to their account), `/pipeline` (Kanban board: open-stage columns with count/value/weighted totals,
native drag-and-drop + a keyboard "Move to…" menu per card, a Lost-reason dialog, a Closed column for
won/lost, owner/close-window/search filters and a forecast header), `/opportunities` (deal book:
filters, pagination, summary), `/opportunities/new` (deal created directly on an account),
`/opportunities/:id` (edit form, stage control, deal timeline + log-activity, linked contracts and
"Create contract from deal" when won). `/companies/:id?tab=contracts&opportunity_id=…` opens the
contract upload prefilled from a won deal. The topbar bell shows assignment notifications. The sidebar
is **grouped by module** — module 01's submodules under `Lead & Client Tracking`, module 02's screens
under `Opportunity Pipeline Visualization`. Account + logout live in the sidebar footer; the brand is
"Primepower Manpower Services / Customer Relationship Management".

**Not implemented yet — next up is module 03, slice S1 (communication log & history)** (spec
`resources/specs/11-communication-history.md`, ADR-0017). Module 03 declares five slices: S1 log &
history, S2 email compose/send/retry through a **mocked** gateway, S3 attachments (implements the
deferred polymorphic `attachments` table), S4 threading, S5 templates (candidate). `Activity` stays the
lightweight quick log; `Communication` is the detailed record and every logged communication also writes
an activity, so the existing timelines stay the merged feed. Still deferred: contract
versioning/e-signature, renewal reminders/automatic client aging, duplicate detection & merge (a
module-01 deferral, ADR-0006). `/leads/{id}/status` cannot reach `converted`; conversion is the only
path (ADR-0009). `Company::deletionBlockers()` carries both guards (open deal, signed contract) —
ADR-0010 §6.

**Demo accounts** (password `password`, via `php artisan migrate:fresh --seed`):
`salesrep@primepower.test`, `salesrep2@primepower.test` (same team), `manager@primepower.test`,
`admin@primepower.test`, `superadmin@primepower.test`. Seeded client data covers every lifecycle
state: Harbor Logistics (signed renewal chain, handoff accepted), BPO Solutions PH (signed, handoff
failed → retry banner), Sunrise Foods Inc (contract out for signature), Greenfield Hotels
(terminated/churned) and Greenfield Hotels Cebu (draft). The seeded pipeline places deals in **every**
stage (e.g. Sunrise `discovery`/`proposal_sent`, BPO `negotiation`/`on_hold`, Harbor `negotiation` and
`won`, Greenfield `lost`, Greenfield Cebu `new_inquiry`/`qualified`) owned across Sam/Liza/Maria, so
the board, the forecast header and the win rate demo without faking.

## Conventions that differ from framework defaults

- **Auth is JWT, not cookies.** Required because frontend and backend are on different origins —
  do not fall back to Laravel Sanctum's cookie/session auth.
- **Git:** branches are named `feature/<name>` and merge into `main` (`feature -> main`); there is
  no `develop`. Commit messages follow **Conventional Commits** (`feat:`, `fix:`, etc.).
- **Delivery unit is the slice, not the spec file** (ADR-0016). From module 02 on, one spec file covers a
  module and declares its **delivery slices** (each demoable end to end, with its own acceptance
  criteria). Planning docs (spec + ADRs) land first; then **one PR per slice**, merged and the branch
  deleted. Module 01 predates this: one file and one PR per submodule. A module whose work is genuinely
  one increment says so in the spec instead of splitting (module 02 = one slice; module 03 = five).
- **Seeds:** do **not** use faker. Data is seeded for a production/demo proposal, not real business.
- **Other-department integrations are mocked in `backend/app/Integrations/`** (ADR-0005, ADR-0013 §4):
  an interface + a `Mock*` driver (bound in `AppServiceProvider`) + `final readonly` DTOs. This is the
  one deliberate new base folder under `backend/app/` — do not add others without approval. Driver
  behaviour is deterministic, never faker: `CRM_HANDOFF_SIMULATE_FAILURE=true` makes the Service
  handoff fail (that is how the retry path is demoed and tested), and the Finance billing snapshot is
  derived from the company reference.
- **Icons:** use `lucide` (lucide-react) only.
- **Locale / currency is PH-based:** `en-PH`, `Asia/Manila` (store UTC), dates `DD MMM YYYY`, PH
  address/phone formats, and **single currency PHP (₱)** — no multi-currency.
- **Ownership scoping** is enforced server-side on every lead query and policy check; the shared
  unassigned queue is the one documented exception to salesrep "own records only" (ADR-0004).
- **IDs are non-sequential ULIDs** on every URL/list-exposed entity — never expose
  auto-incrementing IDs in the API/URL (ADR-0007). (`users.id` is the one exception: the seeded
  staff table uses bigint ids, never routed as URL-exposed resources.)

## Mandatory design system

Announced in `resources/departamental-design-instruction.md` (agreed default, pending final client
sign-off). Full color values: `resources/design-tokens.md`.

- Tokens live in `tailwind.config.js` and `index.css`. **No hardcoded hex or arbitrary px** —
  tokens/utility classes only. Light + dark mode values are both defined. Tokens are hex `var()`s,
  so opacity modifiers (`bg-counts/15`) resolve through `color-mix` — see the `withAlpha` helper in
  `tailwind.config.js` (Tailwind v3 cannot alpha a plain `var()` colour).
- Fixed color mapping per data domain: **blue = counts, green = people, purple = activity,
  orange = cost.** Brand logo text uses `logo-brand`; lead rating uses `rating-hot|warm|cold` — all
  documented in `resources/design-tokens.md`.
- Shared components live in `frontend/src/components/common/` (required list in its `README.md`)
  and `frontend/src/components/layout/`. Reuse before creating; anything used by 2+ subsystems/roles
  belongs in `common/` — no duplicate implementations. Built so far: DashboardShell, Sidebar, Topbar,
  PublicLayout, PageHeader, StatCard, StatusBadge, AlertItem, FormCard, EmptyState; the rest are
  added as features need them.
- Layouts: public site uses `PublicLayout` (no sidebar); **all** dashboards (User/Admin/Superadmin)
  share one `DashboardShell` differentiated only by a `role` prop. Per-role layouts are not permitted.
- Standard dashboard home rows: 4 primary StatCards, then 4 secondary StatCards, then a chart
  section (line + donut), then activity list + alerts list.
- When generating/editing UI, supply the design-system context so output stays aligned.

## Roles (`resources/system-roles.md`)

`superadmin` (low-level/system access) > `admin` (app settings) > `manager` (oversees salesreps,
view-heavy) > `salesrep` (main user, heavy operations; sensitive data hidden and must escalate).

## Scope

This repo implements the **Customer Relationship Management** subsystem (see
`resources/system-modules.md`): lead/client tracking, communication history, client satisfaction &
survey, follow-up reminders, opportunity pipeline visualization. `resources/list-of-departments.md`
lists the wider org systems for context — they are **not** in scope here.

Design docs an agent should read before touching CRM features:

- `resources/business-flow.md` — business context and end-to-end flow
- `resources/crm-flow.md` — object model, pipelines, submodules, integrations, role visibility
- `resources/specs/` — per-submodule implementation specs (data model, API, UI, acceptance criteria)
- `decisions/` — accepted design decisions (ADRs)

Before implementing a submodule, read `resources/specs/README.md` (conventions) and
`resources/specs/00-data-model.md` first; each submodule spec builds on them.

**Modules 01 and 02** are built: module 01 (Lead & Client Tracking) covers lead capture,
qualification, assignment, lifecycle, company/contact, lead→client conversion and client lifecycle;
module 02 (Opportunity Pipeline Visualization) covers the deal book, the Kanban board, stage
moves/won-lost and the board forecast. **Module 03** (Communication History) is specified with five
declared slices — S1 is next. The remaining CRM modules — 04 follow-up reminders, 05 client satisfaction
— are in **ADR-0014** (specs `12`–`13`). Dedup/merge and advanced tiers are module-01 deferrals
(ADR-0006). Other departments are consumed via **mocked integrations** (ADR-0005), not built here.
