# 16 — Module 01 refresh — Lead & Client Tracking

Module 01 (Lead & Client Tracking) refresh. Extends `00-data-model.md`, `01-lead-capture-intake.md`,
`06-company-account-management.md` and `09-client-account-lifecycle.md`. ADRs: **0023** (workspace &
health), **0024** (contract rate cards), **0025** (read-only feeds), **0026** (public acquisition &
sign-in), plus 0004, 0005, 0007, 0010, 0013, 0014, 0016.

> **S0 — planning contract (merged). S1 — shell & routes (built). S2 — Lead Workspace (built).
> S3 — Account Book (built).** S0 is this contract plus its ADRs; S1 shipped the grouped sidebar,
> canonical routes, redirects and canonical dashboard/AI hrefs; S2 shipped the saved views, manager
> workload panel and duplicate links; S3 shipped the scoped accounts+clients book, its filters,
> derived next-follow-up/last-activity columns and the open-deal count. S4–S19 each ship on their own
> `feature/module-01-*` branch and merge to `main` only after review. The branch is deleted after
> merge, and the next slice starts from the updated `main`.

## Purpose

Module 01 is functionally complete but not yet **one coherent workspace**. Eight flat sidebar entries
present submodule facets as destinations, `/clients` duplicates `/companies`, the account page has a
long tab strip, and contracts carry only a headline value. The CRM also shows almost nothing of what
Recruitment, Deployment, Timekeeping and Finance would provide, so a manager cannot answer “is this
account healthy?” without opening several screens.

This refresh keeps the existing records and rules, consolidates the UI into a Lead Workspace and an
Account 360, adds the commercial detail contracts actually need, consumes the external departments as
**read-only feeds**, and gives public prospects a simple request-staffing surface. It adds no
operational tables for other departments.

## Scope

- **In:** grouped sidebar and route cleanup; Lead Workspace saved views; Account Book; Account 360
  (five tabs); account assignment/delegation; contract commercial header and rate cards; global
  Contracts, Deployments and Billing pages; read-only Recruitment, Deployment, Timekeeping and Finance
  feeds; computed account health; manager dashboard strip; public landing refresh; public intake;
  login modal; all-role CSV import.
- **Out:** recreating Recruitment/Deployment/Finance/HR/Legal operations; candidate, worker, shift,
  timesheet or invoice records; timesheet approval; invoice posting; automatic lifecycle moves;
  duplicate merge; advanced lead/account scoring; e-signature; contract versioning; automatic renewal;
  automatic aging; admin/settings modules; AI report changes; a full marketing-site redesign.

## Decisions in force

| ADR | Decision |
| --- | --- |
| **0023** | Grouped sidebar; Lead Workspace; Account Book; Account 360 five tabs; global commercial pages; computed health; manager delegation |
| **0024** | Contract commercial header; `contract_rate_cards`; hourly/monthly/placement units; computed margin; bill visible, pay/margin manager+ |
| **0025** | Read-only job-order, deployment, timekeeping and finance feeds; no tables; no writes except the existing Service handoff |
| **0026** | Simple public landing refresh; embedded intake; modal sign-in; all-role CSV import with ownership rules |

## Navigation and routes

The sidebar is grouped by module. Module 01 shows:

```text
Dashboard

Lead & Client Tracking
  Leads
    Lead Workspace        /leads
    Capture Lead          /leads/new
    Import Leads          /leads/import
  Accounts
    Account Book          /accounts
    Contracts             /contracts
    Deployments           /deployments
    Billing               /billing          (manager+)
  Contacts
    Contact Book          /contacts

Opportunity Pipeline Visualization
  Pipeline
    Pipeline Board
    Deal Book

Communication History Management
  History
    Log & History
    Conversations
  Templates
    Template Library

Follow-up Reminder System
  Worklist
    Follow-up Worklist

Client Satisfaction & Survey
  Surveys
    Survey Library
    Invitations
  Feedback
    Satisfaction
    Responses

AI Reports
  Management Reports
```

Only destinations whose page exists are listed. **Import Leads** joins with S18; **Contracts**,
**Deployments** and **Billing** join with S8, S10 and S12.

**Canonical routes:** `/leads`, `/leads/new`, `/leads/import`, `/leads/:id`, `/leads/:id/convert`,
`/accounts`, `/accounts/new`, `/accounts/:id`, `/contracts`, `/contracts/:id`, `/deployments`,
`/billing`, `/contacts`.

**Redirects (preserve query strings):**

| Old | New |
| --- | --- |
| `/companies` | `/accounts` |
| `/companies/new` | `/accounts/new` |
| `/companies/:id` | `/accounts/:id` |
| `/clients` | `/accounts?view=clients` |
| `/qualification` | `/leads?view=qualification` |
| `/assignment` | `/leads?view=unassigned` |
| `/lifecycle` | `/leads?view=aging` |
| `/conversion` | `/leads?view=conversion` |

Account 360 tab mapping: old `?tab=contacts|sites` → `?tab=people`; `?tab=opportunities|contracts` →
`?tab=commercial&section=…`; `?tab=communications|satisfaction|activities` → `?tab=engagement&section=…`.
The existing `?tab=contracts&opportunity_id=…` contract-upload deep link must keep working through the
mapping.

The API keeps `/companies`; only the frontend route changes. No route below is removed until its
redirect exists.

## Lead Workspace

One screen replaces the qualification, assignment, lifecycle and conversion entries. Query parameter
`?view=` selects a saved view:

| View | Purpose | Notes |
| --- | --- | --- |
| `all` | Default scoped book | salesrep own, manager team, admin all |
| `mine` | My work | current owner = caller |
| `unassigned` | Shared claimable pool | all roles may view; claim/edit per ADR-0004 |
| `team` | Manager+ | team workload and assignment |
| `qualification` | Needs qualification / ready to qualify | reuses `GET /leads?filter[status]=new,contacted` |
| `aging` | Stale / at-risk / archive-due | reuses the computed aging filters |
| `conversion` | Qualified, not yet converted | reuses the conversion worklist |
| `archived` | Archived leads | existing archived filter |

The toolbar keeps the existing search, status/rating/urgency filters, owner control and bulk assign.
The manager view adds a workload panel: open leads, overdue follow-ups, unassigned queue and workload
by rep, using existing scoped queries. Row actions keep the current qualification, assignment,
lifecycle, timeline and conversion flows. Conversion stays the three-step wizard at
`/leads/:id/convert`; it is not embedded in the list. The existing `GET /leads/summary` carries the
saved-view counters (`qualification`, `aging`, `conversion`) alongside the all/mine/team/archived
totals.

## Account Book

`/accounts` is one book for prospects and clients. The `?view=clients` filter is the old `/clients`
book. Columns: account, status, tier, owner, contracts, open deals, next follow-up, last activity.
Filters: search, status, tier, owner, industry and `view=all|clients`. A row opens Account 360.

The Account Book reuses `GET /companies`; no new list endpoint is required beyond the health filter
added in S13. The list resource carries `counts.open_opportunities`, `next_follow_up_at` /
`next_follow_up` (the earliest open task or scheduled message on the account, its contacts, deals or
contracts) and `last_activity_at` (the newest logged activity or communication across the same
scope). Both are read-time values. `filter[industry]` is a case-insensitive substring match, so the
industry field in the toolbar is a useful type-ahead rather than an exact-match trap.

## Account 360

`/accounts/:id` uses five grouped tabs. Each tab fetches independently so the overview is never
blocked by a slow feed.

| Tab | Contents |
| --- | --- |
| **Overview** | Header (name, reference, status, tier, owner, client since, primary contact), journey stepper, health flags, next actions, lifecycle panel, quick actions |
| **People & Sites** | Contacts (primary, role type, status), sites (primary flag), branches (manager+), existing contact/site actions |
| **Commercial** | Linked opportunities, contracts with rate-card summary, billing snapshot (manager+) / owner risk flag, renewal actions |
| **Delivery** | Service handoff, job orders, deployments and timesheet summaries — all read-only with source labels |
| **Engagement** | Communications (merged history), satisfaction cards/invitations, activities timeline |

The **journey stepper** is read-only and shows: Lead captured → Qualified → Converted → Contract
signed → Delivery active → Client. It is derived from the source lead, contracts, handoff and feed
status; it is not a stored field.

**Lifecycle** moves stay on the Overview: current badge, `client_since`, churn reason/date, handoff
state and one button per legal transition. The old profile form no longer carries `status`; the
existing `PATCH /companies/{id}/lifecycle` and `reactivate` endpoints are reused.

The old tabs are not deleted, only regrouped. Contacts, sites, opportunities, contracts,
communications, satisfaction and activities keep their current endpoints and permissions.

## Contract commercial model

ADR-0024. The contract header gains `payment_terms`, `po_reference`, `auto_renew`, `notice_days`,
`signatory_name` and `signatory_position`. `value` remains the negotiated headline and is never
recomputed.

### Rate-card rows

`contract_rate_cards` (ULID, soft deletes, common columns):

| Field | Type | Req | Notes |
| --- | --- | --- | --- |
| `contract_id` | FK contracts | ✓ | cascade with the contract |
| `site_id` | FK company_sites | | optional delivery site |
| `role_title` | string | ✓ | e.g. “Warehouse associate” |
| `headcount` | int | ✓ | ≥ 1 |
| `billing_unit` | enum | ✓ | `hourly`, `monthly`, `placement` |
| `bill_rate` | decimal(15,2) | ✓ | client-facing rate |
| `pay_rate` | decimal(15,2) | | internal cost; manager+ only |
| `units_per_period` | decimal(8,2) | | hours/month for hourly (default 176), 1 for monthly/placement |
| `notes` | text | | |
| `position` | int | ✓ | display order |

The set is replaced wholesale by `PUT /contracts/{id}/rate-cards`; a contract may have no rate cards
for backward compatibility. A `site_id` must belong to the contract's company (422 otherwise).

### Computed values (read time)

- `line_client_monthly = headcount × bill_rate × (billing_unit = hourly ? units_per_period : 1)`;
  placement lines contribute `0` to the monthly run rate.
- `line_internal_monthly = headcount × pay_rate × (billing_unit = hourly ? units_per_period : 1)`.
- `client_monthly_value` = sum of non-placement lines.
- `internal_monthly_cost` = sum of non-placement lines with a pay rate.
- `gross_margin` = client monthly − internal monthly; `gross_margin_percent` = margin ÷ client monthly.
  Missing pay rates leave both margin fields `null`, never `0`.
- `estimated_total_value` = monthly value × whole months in the term (minimum 1) + placement fees.
- `contract.value` remains the negotiated headline and is displayed beside the computed estimate.

### Visibility and editing

- Salesrep: bill rates and client values visible; pay rates, internal cost and margin hidden.
- Manager+: full commercial detail and write access to rate cards.
- Contract header upload/edit: the account's existing `update` right.
- Renewal: “Renew” prefills the previous header and rate cards and links `renewal_of_contract_id`;
  the old contract is untouched.
- A signed contract's rate cards may be corrected by manager+; every change writes a system activity
  to the contract and account timelines. There is no e-signature or clause versioning.

### Global contracts

`GET /contracts` is the scoped cross-account book: filters `status`, `expiring`, `owner`, `company`,
`renewal`, `search`; columns reference, account, title, status, term/expiry, value, rate-card count,
owner. `/contracts/:id` shows the header, rate cards, document, renewal chain and allowed actions.

## Read-only external feeds

ADR-0025. Every feed is an interface + `Mock*` driver + readonly DTOs under `app/Integrations/`; no
feed table and no mutation route. The only outbound integration remains the Service handoff.

| Feed | Read-only payload | UI |
| --- | --- | --- |
| **Recruitment / job orders** | reference, role, requested/filled headcount, status, priority, target start, recruiter, last update | Delivery tab |
| **Deployment / assignment** | reference, job-order/contract reference, role, site, status, active/filled headcount, start/end, operations owner, fill rate, last update | Delivery tab; `/deployments` |
| **Timekeeping** | per deployment: period bounds, approved hours, pending hours, last submission, status (`current`, `pending`, `missing`) | Delivery card; billing context; manager strip |
| **Finance / AR** | existing billing summary + invoices (reference, period, issued, due, amount, balance, status), aging, credit terms | Commercial > Billing (manager+); `/billing` |
| **Service / operations** | handoff `sent`/`failed`, reference, requested/updated time | Delivery tab |

**Endpoints:**

| Method | Path | Description |
| --- | --- | --- |
| `GET` | `/companies/{id}/delivery` | handoff, job orders, deployments, timesheet summaries |
| `GET` | `/deployments` | cross-account deployment book (read-only) |
| `GET` | `/companies/{id}/billing` | expanded Finance snapshot (manager+) |
| `GET` | `/billing` | cross-account AR book (manager+) |

Every response includes `meta: { source, read_only: true, as_of }`. The UI prints the source and
“as of” timestamp and never shows a create/edit/delete action for feed data. A salesrep who can view
the account sees only the redacted `ar_overdue` health flag (a billing-risk note without amounts) on
the Commercial tab; the full feed is manager+. The manager endpoint is not relaxed for owners.

### Delivery payload sketch

```json
{
  "data": {
    "handoff": { "status": "sent", "reference": "SVC-CON-2026-0001", "requested_at": "…", "can_retry": false },
    "job_orders": [
      { "reference": "JO-2026-0014", "role": "Warehouse associate", "requested": 12, "filled": 12,
        "status": "filled", "priority": "high", "target_start_date": "2026-11-03",
        "recruiter": "Recruitment (mocked)", "last_updated_at": "…" }
    ],
    "deployments": [
      { "reference": "DEP-2026-0021", "contract_reference": "CON-2026-0001",
        "role": "Warehouse associate", "site": "Harbor Logistics — Manila",
        "status": "active", "active_headcount": 12, "requested_headcount": 12,
        "start_date": "2026-11-03", "end_date": "2027-11-02", "fill_rate": 100,
        "timesheet": { "period_start": "…", "period_end": "…", "approved_hours": 2112,
          "pending_hours": 0, "last_submitted_at": "…", "status": "current" },
        "last_updated_at": "…" }
    ],
    "timesheets": { "approved_hours": 2112, "pending_hours": 0, "deployments_pending": 0 }
  },
  "meta": { "source": "Recruitment / Deployment / Timekeeping (mocked, read-only)", "read_only": true, "as_of": "…" }
}
```

The mock derives values deterministically from the contract, rate cards, company status and dates. It
never produces worker names or employee-level rows. A churned client's deployments are `ended` and its
AR is `closed`; a prospect with no signed contract has no job order/deployment.

## Account health

Health is a **computed read block** on `CompanyResource` and the manager dashboard. It never writes
`Company.status` and never triggers a lifecycle move. Flags are explainable and role-aware.

| Flag | Severity | Source | Suggested action |
| --- | --- | --- | --- |
| `handoff_failed` | critical | Service handoff | Retry handoff |
| `ar_overdue` | critical | Finance feed (manager+ full; owner redacted) | Finance follow-up |
| `satisfaction_attention` | critical | unresolved survey answer | Call back |
| `contract_expiring` | warning | signed contract inside `expiring_soon_days` | Renew |
| `deployment_underfilled` | warning | deployment feed | Review delivery |
| `job_order_stalled` | warning | job-order feed | Chase recruitment |
| `timesheets_pending` | warning | timekeeping feed | Confirm hours |
| `engagement_quiet` | info | last activity/communication | Check in |
| `renewal_overdue` | warning | contract end date passed without renewal | Renew / close |

`health` returns `{ state: healthy|attention|at_risk, flags[], suggested_action, can_view_financial_detail }`.
A salesrep sees a redacted financial reason without amounts. The account book may filter
`filter[health]=healthy|attention|at_risk`; the manager dashboard uses the same service.

## Manager dashboard

`GET /dashboard` gains three blocks for manager+ (the salesrep view keeps its existing shape):

- `commercial`: AR outstanding and overdue, renewals due in 30/60/90 days, active deployments,
  overall fill rate, deployments with pending timesheets.
- `account_risks`: the highest-severity health rows with account, flag, reason, owner and action link.
- `team_workload`: per-rep open leads, overdue follow-ups, unassigned queue and accounts owned.

The page keeps the existing 4 + 4 KPI rows and charts; the new blocks sit below the charts and above
recent activity. All values are scoped and derived on read. The AI report card is unchanged.

## Public surface

### Landing page

`/` is a simple, credible public page using the existing design language:

1. Public header with brand, **Request staffing**, **Sign in**.
2. Hero: hook title, supporting subtitle, **Request staffing** CTA, simple CSS-only animated
   background (gradient shapes/grid; `prefers-reduced-motion` honoured).
3. Feature cards with lucide icons: capture, qualify, account/contract visibility, communication and
   follow-up.
4. Two or three product previews built from real HTML table markup inside a framed “screenshot”
   component — no external images, no stock photos.
5. Embedded request-staffing form.
6. Footer with contact details and privacy note.

No new UI library. The page must remain readable at mobile width and meet contrast/focus rules.

### Public intake

`POST /api/v1/public/leads` (outside `auth:api`, throttled, honeypot):

```json
{
  "company_name": "Northpoint Retail",
  "contact_name": "Ana Cruz",
  "contact_email": "ana@northpoint.example",
  "contact_phone": "+63 917 000 0000",
  "industry": "Retail",
  "service_interest": "Warehouse staffing",
  "estimated_headcount": 12,
  "needed_roles": "Warehouse associates, inventory lead",
  "target_start_date": "2026-11-15",
  "message": "We are opening two stores.",
  "consent": true
}
```

Required: `company_name`, `contact_name`, at least one of email/phone, `consent`. The lead is
`status = new`, `owner_id = null`, `source = website`, `source_detail = "Public landing form"`.
Duplicate matching is advisory; the response is a friendly 201 with no internal ids or duplicate
detail. The form shows success, validation errors and a retryable failure state.

### Sign-in modal

- Standalone `Login.tsx` is removed; `LoginModal` is rendered by the public layout or a landing
  route.
- `/login` → `/?login=1`; protected routes → `/?login=1&next=<internal path>`.
- 401 responses clear local auth and open the modal with the current path as `next`.
- The modal is an accessible dialog: labelled title, initial email focus, focus trap, Escape/backdrop
  close, loading and inline error.
- Successful login closes the modal and navigates to a validated internal `next`, otherwise
  `/dashboard`.
- An authenticated visitor sees **Open dashboard** instead of **Sign in**.

## CSV import

`/leads/import` (all roles):

1. Download the template (`GET /leads/import/template`).
2. Upload CSV; `POST /leads/import/preview` returns mapped rows, per-row errors, duplicates and the
   target owner.
3. `POST /leads/import` commits only valid rows; invalid rows are reported and skipped.
4. Salesrep rows are owned by the importer. Manager/admin rows default to unassigned; the preview may
   pick an owner in their scope. `owner_id` is ignored/forced for salesreps.
5. Cap 500 rows per file. Duplicate matches are advisory; no merge. Imported leads carry
   `source_detail` naming the file/import.

## API surface

Existing endpoints are reused unless listed here.

| Method | Path | Slice | Notes |
| --- | --- | --- | --- |
| `GET` | `/contracts` | S8 | Scoped contract book |
| `GET` | `/contracts/{id}/rate-cards` | S7 | Current rate-card set |
| `PUT` | `/contracts/{id}/rate-cards` | S7 | Replace the set (manager+) |
| `POST` | `/companies/{id}/assign` | S5 | Single account assignment |
| `POST` | `/companies/bulk-assign` | S5 | Manager+ bulk assignment |
| `GET` | `/companies/{id}/delivery` | S9 | Read-only delivery feed |
| `GET` | `/deployments` | S10 | Read-only deployment book |
| `GET` | `/companies/{id}/billing` | S11 | Expanded Finance snapshot (manager+) |
| `GET` | `/billing` | S12 | Read-only AR book (manager+) |
| `GET` | `/leads/import/template` | S18 | CSV template |
| `POST` | `/leads/import/preview` | S18 | Validate/map without writing |
| `POST` | `/leads/import` | S18 | Commit valid rows |
| `POST` | `/public/leads` | S17 | Unauthenticated intake |

Contract create/update payloads accept the new header fields. `GET /contracts/{id}` includes
`rate_cards` and `commercial` when loaded. `GET /companies/{id}` includes `health`. The company list
accepts `filter[health]`.

## Events

- **`CompanyAssigned`** (new) — fired on single/bulk account reassignment; records a `system` activity
  and notifies the new owner (spec 06).
- **`LeadCreated`** — reused for public intake and each valid CSV import row, so existing assignment
  and activity behaviour stays in one place.
- Existing `ContractSigned`, `CompanyStatusChanged`, `CompanyActivated` and `CompanyChurned` are
  unchanged. Rate-card corrections write a `system` activity; they do not invent a new lifecycle event.
- Feed reads and health computation emit no events and write no records.

## Data model changes

- `contracts`: `payment_terms`, `po_reference`, `auto_renew` (bool, default false), `notice_days`
  (int), `signatory_name`, `signatory_position`.
- New `contract_rate_cards` table as above.
- No columns for job orders, deployments, timesheets or invoices; those are read-only feeds.
- No new table for account health; it is computed.
- `leads.consent_at` (nullable timestamp) records public-form consent; manual/CSV leads leave it null.
  Existing `source`/`source_detail`/`owner_id` cover the rest of public intake and import.
- Indexes: `contract_rate_cards.contract_id`, `contract_rate_cards.billing_unit`; existing contract
  indexes remain.

See `00-data-model.md` for the canonical fields.

## Permissions

| Capability | salesrep | manager | admin / superadmin |
| --- | --- | --- | --- |
| Lead workspace / views | own, unassigned pool | team | all |
| Create/import leads | own imports | team; owner selector | all imports; owner selector |
| Account book / Account 360 | own | team | all |
| Assign an account | self only | team | all |
| Bulk assign accounts | — | team | all |
| Contract header upload/edit | own account | team | all |
| Rate cards | view bill; no write | manage | manage |
| Pay rate / cost / margin | hidden | visible | visible |
| Billing feed | hidden (risk flag only) | full | full |
| Delivery feeds | own account, read-only | team | all |
| Lifecycle / churn / reactivate | — | team | all |
| CSV import | own rows | unassigned + owner selector | unassigned + owner selector |
| Public intake | — | — | — (unauthenticated) |

## Demo data

Seeds must make every slice demoable without setup:

- **Contracts:** every seeded contract gets a commercial header and rate cards. Harbor's signed
  renewal chain has two agreements with cloned rates; Sunrise's agreement out for signature has a
  rate card; BPO has the contract whose handoff failed; Greenfield has the terminated contract and
  Greenfield Cebu the draft.
- **Delivery:** Harbor (active) has filled job orders, active deployments and approved hours; BPO
  (onboarding) has an open job order, no deployment and pending/missing hours; Sunrise (prospect) has
  no delivery; Greenfield (churned) has ended deployments and closed AR; Greenfield Cebu (onboarding)
  has a draft agreement and no deployment.
- **Billing:** Harbor current/approved; BPO has an overdue onboarding/deposit invoice to demo the
  manager AR book and owner risk flag; Sunrise has no live AR; Greenfield is closed.
- **Health:** the combinations above produce a healthy account, an at-risk/onboarding account and a
  churned account, plus the Sunrise satisfaction-attention flag.
- **Manager dashboard:** the seeded book yields nonzero AR, renewals due, deployment fill and
  timesheet-pending figures; no fixture file.
- **Public/CSV:** no seed; the import template is downloadable and the public form works on a fresh
  database.

## Delivery slices

Each slice is demoable end to end and ships API, UI, permissions, seeds and tests together. Branch
names are suggestions; the `feature/module-01-*` prefix is required.

| Slice | Branch | Ships | Done when |
| --- | --- | --- | --- |
| **S0** | `feature/module-01-refresh-planning` | These ADRs + spec + index/flow/AGENTS cross-references | Docs reviewable; no code changes |
| **S1** | `feature/module-01-shell` | Grouped sidebar, canonical routes, redirects, updated dashboard/AI links | All old paths redirect; no dead sidebar links; route tests |
| **S2** | `feature/module-01-lead-workspace` | Saved views, manager workload panel, duplicate-created-record link | Each view filters correctly; workload is scoped; qualification/assignment/lifecycle/conversion actions unchanged |
| **S3** | `feature/module-01-account-book` | `/accounts` accounts+clients book, filters, next follow-up, counts | `/clients` view works; salesrep/manager scoping; pagination |
| **S4** | `feature/module-01-account-360` | Five grouped tabs, journey stepper, lifecycle moved out of the profile form | Tabs deep-link; old tab query maps; no lifecycle `status` in the profile form |
| **S5** | `feature/module-01-account-delegation` | Account assign/bulk-assign, activity, notification, target rules | Salesrep self only; manager team; admin all; new owner notified |
| **S6** | `feature/module-01-contract-header` | Commercial fields, validation, form, resource, edit activity, seeds | New fields persist; only account update right edits; timeline records the edit |
| **S7** | `feature/module-01-rate-cards` | Rate-card table/model/API/UI, computed values, renewal clone, manager gate | Hourly/monthly/placement totals compute; null margin stays null; salesrep cannot see pay/margin |
| **S8** | `feature/module-01-contracts-book` | `/contracts`, `/contracts/:id`, filters, rate summary | Scoped list; renewal/expiry/status filters; detail shows document + rates |
| **S9** | `feature/module-01-delivery-feed` | Recruitment/deployment/timekeeping interfaces, DTOs, mocks, Delivery tab, source labels | Read-only UI; no write routes; seeded scenarios; manager/salesrep scoping |
| **S10** | `feature/module-01-deployments-book` | `/deployments` read-only book, fill rate and timesheet summary | Filters/search; source label; no mutation actions |
| **S11** | `feature/module-01-finance-feed` | Expanded AR/invoice feed, Billing section, owner risk flag | Manager sees full feed; owner sees only risk flag; source/as_of present |
| **S12** | `feature/module-01-billing-book` | `/billing` manager+ AR book | Salesrep 403/nav hidden; aging, overdue, filters; read-only |
| **S13** | `feature/module-01-account-health` | Health service/block, flags, account filter, redaction | Flags explain themselves; no auto status change; financial detail redacted |
| **S14** | `feature/module-01-manager-dashboard` | Commercial strip, account risks, team workload | Manager+ blocks; salesrep view unchanged; all links resolve |
| **S15** | `feature/module-01-public-landing` | Landing refresh, animated background, feature cards, HTML product previews | Responsive; reduced-motion; no new UI library; no broken images |
| **S16** | `feature/module-01-login-modal` | Accessible modal, `/login` redirect, `next` handling, 401 flow | `/login` opens modal; deep-link return; keyboard/escape/focus; tests |
| **S17** | `feature/module-01-public-intake` | Embedded form + public endpoint | 201 unassigned website lead; validation/throttle/honeypot; thank-you state |
| **S18** | `feature/module-01-csv-import` | Import page, template, preview/commit, all-role ownership | Salesrep self-owned; manager unassigned + selector; invalid rows skipped; 500 cap |
| **S19** | `feature/module-01-sidebar-pattern` | Grouped sidebar for Modules 02–05, docs/bookkeeping | All existing module pages reachable; no dead links; no behavior change |

**Dependency order:** S0 → S1 → S2/S3 → S4 → S5/S6 → S7 → S8/S9 → S10 → S11 → S12 → S13 → S14.
S15 → S16/S17 are independent of the account chain; S18 depends on S2; S19 is last. Each slice waits
for review and merge before the next begins.

**Review protocol:** each slice is pushed on its `feature/module-01-*` branch and opened as a PR to
`main`. The agent does not start the next slice until the user has reviewed; after approval, the agent
merges the PR, deletes the branch, and starts the next slice from the updated `main`. S0 follows the
same protocol.

## Test bar

Every slice runs its narrow backend/frontend tests plus the existing suite before review:

- Backend: `php artisan test --compact` in `backend/`; feature tests cover permissions, scoping,
  validation and the slice's acceptance criteria.
- Frontend: `npm run lint`, `npm run test`, `npm run build` in `frontend/`.
- Seed the demo database (`php artisan migrate:fresh --seed`) and walk the slice demo path.
- No dead links, no unreachable sidebar entries, no placeholder-only screens.
- Update `AGENTS.md` and the spec index when a slice changes module bookkeeping.

## Not built here

- Any Recruitment/Deployment/Finance/HR/Legal operations, candidate/worker records, rosters, shifts,
  timesheet entry/approval, invoice creation/posting, payments, service tickets.
- Automatic lifecycle transitions, automatic aging, automatic renewal, scheduled jobs.
- Duplicate merge, advanced lead/account scoring, tier automation.
- Contract e-signature, clause versions, multiple documents per contract.
- Admin/superadmin settings modules; AI report changes.
- A full brand/marketing-site redesign; multi-currency.

## Open questions

None. The decisions are recorded in ADRs 0023–0026. Any later change is a new ADR and a spec edit
before code.
