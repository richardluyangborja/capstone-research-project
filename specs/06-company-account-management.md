# 06 — Company / Account Management

Submodule 6. Entities: `Company`, `CompanySite`, `Activity`, `Contract` (plus the other tabs landed
with submodules 07–09). ADRs: 0001, 0004, 0006, 0010, 0012, 0013, **0023**, **0024**, **0025**.

> **Module 01 refresh (spec `16`, ADR-0023–0025).** The account book moves to `/accounts` (the API
> stays `/companies`), Account 360 uses five grouped tabs, account assignment becomes a first-class
> action, and computed health/read-only delivery and billing blocks join the account payload. This
> file remains the baseline for account CRUD, sites, branches and roll-ups.

## Purpose

Maintain the organization record that a lead becomes and that a client is built on, including
branches, sites, and the roll-up of its contacts, opportunities, contracts and activities.

## Scope

- **In:** company CRUD, sites, parent/child (branches), status/tier, owner assignment/delegation,
  activity timeline, the roll-up counts the tabs read, the Account Book and the five-tab Account 360,
  computed account health, and read-only delivery/billing blocks (ADR-0023–0025).
- **Out:** the client lifecycle moves and contracts themselves (submodules 09 and spec 16), editing
  deals (submodule 08 ships a read-only Opportunities tab; the deal pipeline is a later module),
  advanced/stored health scoring (Phase 2; health flags are computed on read), company merge (Phase 2
  dedup), and any operational Recruitment/Deployment/Finance/HR/Legal record.

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
8. **Commercial visibility:** contract bill rates and client-facing contract values are visible on an
   account the salesrep may view; pay rates, internal cost, margin and full billing/AR are manager+
   only (ADR-0024, ADR-0013 §7). Billing is manager+; an account owner sees only a redacted risk flag.
   Deal amounts remain visible to whoever can see the deal (ADR-0012 §4).
9. **Account assignment/delegation:** assigning an account follows the lead target rules — a salesrep
   may keep it themselves only, a manager may hand it to their team, an admin to anyone. Bulk assign is
   manager+. Every reassignment writes a `system` activity and notifies the new owner. Contracts keep
   deriving their owner from the account (ADR-0013 §7).
10. **Health is computed, explainable and non-mutating.** The account payload carries a `health` block
    (state, flags with reason/source/severity, suggested action) assembled from contracts, read-only
    feeds and engagement. It never changes `status`; lifecycle moves remain manual (ADR-0023 §5).
    Financial reasons are redacted for salesreps.

## Permissions

| Action | salesrep | manager | admin / superadmin |
| --- | --- | --- | --- |
| View | Own only | Team | All |
| Create (manual) | ✓ (own, `prospect` only) | ✓ | ✓ |
| Update | Own only | Team | All |
| Assign owner | Self only | Team | All |
| Bulk assign | — | Team | All |
| Delete / restore | — | Team | All |
| Manage sites / parent | — | Team | All |
| View full billing / AR | — | Team | All |
| View bill rates / client contract value | Own only | Team | All |
| Manage contract pay rates / margin | — | Team | All |

Assigning an existing company to another owner follows the target rules: a salesrep may only keep it
themselves, a manager may hand it to their team, an admin to anyone (`owner_id` violations are 422).
Account assignment is also the path that writes the ownership activity and notification (rule 9).

## API endpoints

| Method | Path | Description |
| --- | --- | --- |
| `GET` | `/api/v1/companies` | List + filters (`status,tier,industry,owner_id,parent_company_id,health`) + `search` + `page` |
| `POST` | `/api/v1/companies` | Create (manual) — responds 201 with a `duplicates` advisory array |
| `POST` | `/api/v1/companies/bulk-assign` | **Manager+** — reassign accounts in scope |
| `POST` | `/api/v1/companies/{id}/assign` | Assign one account (target rules in rule 9) |
| `GET` | `/api/v1/companies/{id}` | Detail with roll-up counts, `health` and permissions |
| `PATCH` | `/api/v1/companies/{id}` | Update |
| `DELETE` | `/api/v1/companies/{id}` | Soft delete (guarded) |
| `POST` | `/api/v1/companies/{id}/restore` | Restore |
| `GET/POST` | `/api/v1/companies/{id}/sites` | List / add sites |
| `PATCH/DELETE` | `/api/v1/companies/{id}/sites/{siteId}` | Update / remove site |
| `GET/POST` | `/api/v1/companies/{id}/activities` | Activity/communication tab + log an activity |
| `GET/POST` | `/api/v1/companies/{id}/contacts` | Contacts tab — submodule 07 (list / add) |
| `GET` | `/api/v1/companies/{id}/opportunities` | Opportunities tab — submodule 08 (read-only) |
| `GET` | `/api/v1/companies/{id}/contracts` | Contracts tab — submodule 09 (list) |
| `GET` | `/api/v1/companies/{id}/delivery` | **Read-only** delivery feed (ADR-0025) |
| `GET` | `/api/v1/companies/{id}/billing` | **Manager+** read-only Finance snapshot (expanded in spec 16) |

`GET /companies/{id}` returns affordances (`can_update`, `can_manage_sites`, `can_manage_parent`,
`can_assign`, `can_delete`, `can_manage_lifecycle`, `can_view_billing`, `can_view_margin`,
`can_upload_contract`) plus `health` and `counts: { sites, branches, contacts, opportunities,
contracts, activities }`, so the UI never has to re-derive permissions from the role. (`contacts`
was added by submodule 07, `opportunities` by 08, `contracts` and the lifecycle/billing affordances by
09; `health`, `can_assign` and `can_view_margin` by the refresh.) The Company detail also carries the
client lifecycle fields (`client_since`, `churn_reason`, `churned_at`, `handoff { … }`,
`allowed_status_transitions`) — see spec 09.

## UI

- **Account Book** `/accounts` — `DataTable`: name (+ `reference`), industry, status
  (`StatusBadge`), tier, owner, contracts, open deals, next follow-up, last activity, health; search +
  status/tier/owner/industry filters and `?view=clients`; paginated (blue = counts). `/companies`
  redirects here.
- **Account 360** `/accounts/:id` — `PageHeader` + five grouped tabs: **Overview** (profile, journey,
  health, ownership, lifecycle, branch, record), **People & Sites** (contacts, primary contact, sites,
  branches), **Commercial** (opportunities, contracts/rate cards, billing), **Delivery** (handoff,
  job orders, deployments, timesheets — read-only), **Engagement** (communications, satisfaction,
  activities). Old tab query values redirect to the grouped tab (spec 16).
- The lifecycle panel is described in spec 09. Ownership assignment (single and bulk) is available on
  the book and the Overview; manager+ bulk assign.
- One primary site per company; site editor lives in People & Sites (`FormCard`).
- Creation is a dedicated route `/accounts/new` (warn on a name collision, never block).
- Global `/contracts`, `/deployments` and `/billing` pages cover cross-account work; Billing is
  manager+.

## Events

- `CompanyCreated` — fired on manual creation and (submodule 08) on conversion.
- `CompanyAssigned` — fired when an account's owner changes (single or bulk); records a `system`
  activity and notifies the new owner.
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
- [ ] Account assignment follows the target rules, writes a timeline activity and notifies the new
  owner; bulk assign is manager+.
- [ ] `health` is computed on read, explains each flag, never mutates `status`, and redacts financial
  detail for salesreps.
- [ ] Delivery and billing blocks are read-only, carry source/`as_of`, and offer no mutation actions.

## Open questions

- ~~Should branches auto-roll up to a parent for reporting in Phase 1?~~ — **No**: branches are
  separate operating records and the parent shows a count only (ADR-0010). Revisit if head-office
  reporting is asked for.
- ~~Manual company creation restricted to manager+, or allow salesrep for walk-in existing clients?~~ —
  **Allow salesrep**, own records and `prospect` only (ADR-0010).
