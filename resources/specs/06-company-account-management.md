# 06 — Company / Account Management

Submodule 6. Entities: `Company`, `CompanySite`, `Activity` (the `Contact`, `Opportunity` and
`Contract` tabs land with submodules 07–09). ADRs: 0001, 0004, 0006, 0010, 0012, 0013.

## Purpose

Maintain the organization record that a lead becomes and that a client is built on, including
branches, sites, and the roll-up of its contacts, opportunities, contracts and activities.

## Scope

- **In:** company CRUD, sites, parent/child (branches), status/tier, owner, activity timeline, and the
  roll-up counts that the tabs read.
- **Out:** the client lifecycle moves and contracts themselves (submodule 09 — no `PATCH` reaches a
  client state), **editing** deals (submodule 08 ships a read-only Opportunities tab; the deal
  pipeline is a later module), advanced health/engagement scoring (Phase 2) and company merge
  (Phase 2 dedup).

## Business rules

1. A company is created **by conversion** (submodule 8) or **manually** (e.g. importing an existing
   client). Manual creation is open to **every role** (ADR-0010): a salesrep's new company is owned
   by them and always starts as a `prospect`; manager+ may open one directly in a client state.
2. `reference` server-generated (`CO-YYYY-NNNN`); `name` is soft-unique (warn on collision, allow).
3. **Parent/child:** a company may have `parent_company_id`. Cycles are rejected (422). A branch
   inherits nothing automatically; it is a separate operating record with its own sites/contacts,
   and the parent exposes only a branch **count** — no aggregated reporting numbers.
4. `status` follows the client lifecycle (`prospect → onboarding → active → at_risk → dormant →
   churned`). It is advanced by the submodule-09 rules (`PATCH /companies/{id}/lifecycle`, manager+,
   state-machine validated) or by signing a contract; it may be set at creation by manager+, and
   afterwards **only** an admin may set the field directly through `PATCH /companies/{id}` as a
   system override (ADR-0010 §3, ADR-0013 §6).
5. `tier` is **manual** in Phase 1 (advanced scoring deferred).
6. Deleting a company with a signed contract or a non-archived opportunity is blocked (422); use
   soft delete only when no active dependencies remain. `Company::deletionBlockers()` carries both
   blockers — any deal with `stage ∉ {won, lost}` (submodule 08) and any `signed` contract
   (submodule 09) — the messages name what blocks (ADR-0010 §6, ADR-0012, ADR-0013 §8).
7. `owner_id` is required; defaults from the source lead's owner on conversion, or the creator when
   created manually.
8. Sensitive roll-ups (opportunity amounts, contract rate terms, billing) are hidden from salesrep
   (ADR-0004). Deal amounts are visible to whoever can see the deal (ADR-0012 §4); billing is
   manager+ only (ADR-0013 §7).

## Permissions

| Action | salesrep | manager | admin / superadmin |
| --- | --- | --- | --- |
| View | Own only | Team | All |
| Create (manual) | ✓ (own, `prospect` only) | ✓ | ✓ |
| Update | Own only | Team | All |
| Delete / restore | — | Team | All |
| Manage sites / parent | — | Team | All |

Assigning an existing company to another owner follows the target rules: a salesrep may only keep it
themselves, a manager may hand it to their team, an admin to anyone (`owner_id` violations are 422).

## API endpoints

| Method | Path | Description |
| --- | --- | --- |
| `GET` | `/api/v1/companies` | List + filters (`status,tier,industry,owner_id,parent_company_id`) + `search` + `page` |
| `POST` | `/api/v1/companies` | Create (manual) — responds 201 with a `duplicates` advisory array |
| `GET` | `/api/v1/companies/{id}` | Detail with roll-up counts |
| `PATCH` | `/api/v1/companies/{id}` | Update |
| `DELETE` | `/api/v1/companies/{id}` | Soft delete (guarded) |
| `POST` | `/api/v1/companies/{id}/restore` | Restore |
| `GET/POST` | `/api/v1/companies/{id}/sites` | List / add sites |
| `PATCH/DELETE` | `/api/v1/companies/{id}/sites/{siteId}` | Update / remove site |
| `GET/POST` | `/api/v1/companies/{id}/activities` | Activity/communication tab + log an activity |
| `GET/POST` | `/api/v1/companies/{id}/contacts` | Contacts tab — submodule 07 (list / add) |
| `GET` | `/api/v1/companies/{id}/opportunities` | Opportunities tab — submodule 08 (read-only) |
| `GET` | `/api/v1/companies/{id}/contracts` | Contracts tab — submodule 09 (list) |

`GET /companies/{id}` returns affordances (`can_update`, `can_manage_sites`, `can_manage_parent`,
`can_assign`, `can_delete`, `can_manage_lifecycle`, `can_view_billing`, `can_upload_contract`) plus
`counts: { sites, branches, contacts, opportunities, contracts, activities }` so the UI never has to
re-derive permissions from the role. (`contacts` was added by submodule 07, `opportunities` by 08,
`contracts` and the lifecycle/billing affordances by 09.) The Company detail also carries the client
lifecycle fields (`client_since`, `churn_reason`, `churned_at`, `handoff { … }`,
`allowed_status_transitions`) — see spec 09.

## UI

- **Companies list** `/companies` — `DataTable`: name (+ `reference`), industry, status
  (`StatusBadge`), tier, owner, sites, branches, created; search + status/tier filters, paginated
  (blue = counts).
- **Company detail** `/companies/:id` — `PageHeader` + tabs: Overview (profile, ownership, client
  lifecycle, branch, billing, record), Contacts (submodule 07), Sites, Opportunities (submodule 08,
  read-only), Contracts (submodule 09), Activities. The lifecycle panel is described in spec 09.
- One primary site per company; site editor lives in the detail page's Sites tab (`FormCard`).
- Creation is a dedicated route `/companies/new` (warn on a name collision, never block).

## Events

- `CompanyCreated` — fired on manual creation and (submodule 08) on conversion.
- `CompanyStatusChanged` — fired by the client lifecycle moves (submodule 09) and by contract
  signing; recorded on the company timeline.

## Acceptance criteria

- [x] Creating a company with `parent_company_id` pointing to its own descendant returns 422.
  (Also covers a company naming itself as its own parent.)
- [x] A site can be marked primary; marking a new primary unsets the old one.
- [x] Deleting a company with an open opportunity (submodule 08) or a signed contract (submodule 09)
  returns 422 with a message naming the blocker.
- [x] salesrep sees only own-owner companies; manager sees team's.
- [x] Detail response includes the roll-up counts — `counts: { sites, branches, contacts,
  opportunities, contracts, activities }` (ADR-0010, ADR-0012, ADR-0013).
- [x] Only an admin may set `status` through `PATCH /companies/{id}` directly; manager+ move the
  lifecycle through `PATCH /companies/{id}/lifecycle` instead (spec 09).

## Open questions

- Should branches auto-roll up to a parent for reporting in Phase 1? — **No**: branches are separate
  operating records and the parent shows a count only (ADR-0010). Revisit if head-office reporting
  is asked for.
- Manual company creation restricted to manager+, or allow salesrep for walk-in existing clients? —
  **Allow salesrep**, own records and `prospect` only (ADR-0010).
