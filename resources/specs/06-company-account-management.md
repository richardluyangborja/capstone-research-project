# 06 — Company / Account Management

Submodule 6. Entities: `Company`, `CompanySite`, `Contact`, `Opportunity`, `Contract`, `Activity`.
ADRs: 0001, 0004, 0006.

## Purpose

Maintain the organization record that a lead becomes and that a client is built on, including
branches, sites, and the roll-up of its contacts, opportunities, and contracts.

## Scope

- **In:** company CRUD, sites, parent/child (branches), status/tier, owner, related-record tabs.
- **Out:** advanced health/engagement scoring (Phase 2); company merge (Phase 2 dedup).

## Business rules

1. A company is created **by conversion** (submodule 8) or **manually** (e.g. importing an existing
   client). Manual creation is manager+ by default.
2. `reference` server-generated (`CO-YYYY-NNNN`); `name` is soft-unique (warn on collision, allow).
3. **Parent/child:** a company may have `parent_company_id`. Cycles are rejected (422). A branch
   inherits nothing automatically; it is a separate operating record with its own sites/contacts.
4. `status` follows the client lifecycle (`prospect → onboarding → active → at_risk → dormant →
   churned`) but is only advanced by the lifecycle rules in submodule 9 (or admin override).
5. `tier` is **manual** in Phase 1 (advanced scoring deferred).
6. Deleting a company with active contracts or non-archived opportunities is blocked (422); use
   soft delete only when no active dependencies remain.
7. `owner_id` is required; defaults from the source lead's owner on conversion.
8. Sensitive roll-ups (opportunity amounts, contract rate terms) are hidden from salesrep.

## Permissions

| Action | salesrep | manager | admin / superadmin |
| --- | --- | --- | --- |
| View | Own only | Team | All |
| Create (manual) | — | ✓ | ✓ |
| Update | Own only | Team | All |
| Delete / restore | — | Team | All |
| Manage sites / parent | — | Team | All |

## API endpoints

| Method | Path | Description |
| --- | --- | --- |
| `GET` | `/api/v1/companies` | List + filters (`status,tier,industry,owner_id`) + `search` |
| `POST` | `/api/v1/companies` | Create (manual) |
| `GET` | `/api/v1/companies/{id}` | Detail with roll-up counts |
| `PATCH` | `/api/v1/companies/{id}` | Update |
| `DELETE` | `/api/v1/companies/{id}` | Soft delete (guarded) |
| `POST` | `/api/v1/companies/{id}/restore` | Restore |
| `GET/POST` | `/api/v1/companies/{id}/sites` | List / add sites |
| `PATCH/DELETE` | `/api/v1/companies/{id}/sites/{siteId}` | Update / remove site |
| `GET` | `/api/v1/companies/{id}/contacts` | Contacts tab |
| `GET` | `/api/v1/companies/{id}/opportunities` | Opportunities tab |
| `GET` | `/api/v1/companies/{id}/contracts` | Contracts tab |
| `GET` | `/api/v1/companies/{id}/activities` | Activity/communication tab |

## UI

- **Companies list** `/companies` — `DataTable`: name, industry, status (`StatusBadge`), tier,
  owner, #open opportunities; filter by status/tier (blue = counts).
- **Company detail** `/companies/:id` — `PageHeader` + tabs: Overview, Contacts, Sites,
  Opportunities, Contracts, Activities. Lifecycle stepper (submodule 9).
- One primary site per company; site editor modal (`FormCard`).

## Events

- `CompanyCreated`, `CompanyStatusChanged` (partner to submodule 9).

## Acceptance criteria

- [ ] Creating a company with `parent_company_id` pointing to its own descendant returns 422.
- [ ] A site can be marked primary; marking a new primary unsets the old one.
- [ ] Deleting a company with an active contract returns 422.
- [ ] salesrep sees only own-owner companies; manager sees team's.
- [ ] Detail response includes roll-up counts of contacts, opportunities, contracts.

## Open questions

- Should branches auto-roll up to a parent for reporting in Phase 1?
- Manual company creation restricted to manager+, or allow salesrep for walk-in existing clients?
