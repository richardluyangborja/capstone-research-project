# 01 — Lead Capture & Intake

Submodule 1 of Lead & Client Tracking. Entities: `Lead` (`00-data-model.md`). ADRs: 0001, 0004, **0026**.

> **Module 01 refresh (spec `16`, ADR-0026).** CSV import becomes a full `/leads/import` page available
> to every role (salesrep rows are self-owned; manager/admin rows default to the unassigned queue with
> an optional target owner), and public website intake is `POST /api/v1/public/leads`, embedded on the
> refreshed landing page. This file remains the baseline for manual lead capture.

## Purpose

Capture every inbound/outbound inquiry as a `Lead` with its source, from any channel, and give an
advisory duplicate warning before creating a record.

## Scope

- **In:** manual creation, bulk CSV import (template + preview + commit), public website intake,
  channel/source tagging, advisory duplicate check, listing/filtering/searching leads.
- **Out:** the full duplicate-merge workflow (Phase 2), campaign management, a general-purpose form
  builder or marketing-site CMS. The refresh ships one embedded request-staffing form (spec 16).

## Business rules

1. `company_name`, `contact_name`, and `source` are required. At least one of `contact_email` /
   `contact_phone` is required.
2. New leads get `status = new` and `owner_id = null` (unassigned queue) unless the creator is a
   salesrep, in which case `owner_id = creator`.
3. `reference` is generated server-side (`LD-YYYY-NNNN`); clients cannot set it.
4. **Advisory duplicate check:** before persisting, match on normalised `company_name` and on
   `contact_email` / `contact_phone`. If matches exist, create anyway but return `duplicates: [...]`
   in the response and record a `system` activity. Hard blocking is Phase 2 (ADR-0006).
5. `source = other` requires `source_detail`.
6. Import is all-or-nothing per row: invalid rows are reported, valid rows are created. A file is
   capped at 500 rows, previewed before commit, and duplicate matches are advisory only.
7. **CSV ownership (all roles):** a salesrep's imported rows are owned by the importer; manager/admin
   imports default to the shared unassigned queue and may choose a target owner in their scope in the
   preview. No merge is performed.
8. **Public intake:** `POST /public/leads` is unauthenticated, throttled and honeypot-protected. It
   creates an unassigned lead (`source = website`, `source_detail = "Public landing form"`) and cannot
   set owner, status, score or sensitive fields. Company, contact, at least one contact channel and
   consent are required; the submission stamps `consent_at`. Duplicates are advisory and not exposed in
   the public response.

## Permissions

| Action | salesrep | manager | admin / superadmin |
| --- | --- | --- | --- |
| Create / import | ✓ | ✓ | ✓ |
| List | Own only | Team | All |
| View | Own only | Team | All |
| Delete/restore | Own only | Team | All |

CSV import is available to every role under rule 7; the owner target is the only difference. Public
intake is unauthenticated and always creates an unassigned lead.

## API endpoints

| Method | Path | Description |
| --- | --- | --- |
| `POST` | `/api/v1/leads` | Create a lead |
| `GET` | `/api/v1/leads/import/template` | Download the CSV template (all roles) |
| `POST` | `/api/v1/leads/import/preview` | Validate/map rows without writing (all roles) |
| `POST` | `/api/v1/leads/import` | Commit valid CSV rows (all roles; ownership per rule 7) |
| `POST` | `/api/v1/public/leads` | Public website intake — unauthenticated, throttled (rule 8) |
| `GET` | `/api/v1/leads` | List + filters (`status,source,owner_id,rating,created_at`) + `search` |
| `GET` | `/api/v1/leads/{id}` | Detail |
| `DELETE` | `/api/v1/leads/{id}` | Soft delete |
| `POST` | `/api/v1/leads/{id}/restore` | Restore |

**Create request (summary):** `company_name, contact_name, contact_email?, contact_phone?,
source, source_detail?, industry?, location?, service_interest?, estimated_headcount?, needed_roles?,
notes?`. **Response:** created lead + optional `duplicates` (id/company_name/match_reason).

**Public intake request:** `company_name, contact_name, contact_email?, contact_phone?, industry?,
service_interest?, estimated_headcount?, needed_roles?, target_start_date?, message?, consent`.
Required: company, contact, at least one channel, consent. **Response:** 201 `{ message }` with no
internal ids or duplicate detail.

## Validation

- `source` must be a `LeadSource` value; `other` ⇒ `source_detail` required.
- `contact_email` valid email if present; `contact_phone` digit/length sanity if present.
- `estimated_headcount` integer ≥ 1 if present.
- Public intake: consent required; no owner/status/sensitive fields accepted; throttled and
  honeypot-protected.
- CSV import: maximum 500 rows; invalid rows are reported and skipped; duplicate matches are advisory.

## UI

- **Lead Workspace** `/leads` — saved views (All / Mine / Unassigned / Team / Qualification / Aging /
  Conversion / Archived), `DataTable` columns reference, company, contact, source, status
  (`StatusBadge`), rating, owner, next follow-up, last activity; filters + search (blue = counts).
  Qualification/assignment/lifecycle/conversion are actions/views here, not sidebar entries
  (spec 16).
- **New lead** `/leads/new` — `FormCard`; duplicate warnings via `AlertItem` (non-blocking).
- **Import** `/leads/import` — template download, upload/preview, per-row errors and duplicates,
  target-owner selector for manager+, then commit.
- **Public request-staffing form** on `/` — company/contact/channel/consent, success and error states;
  calls `POST /public/leads`.
- **Empty state** via `EmptyState`.

## Events

- `LeadCreated` — notify assignee (if any) or manager queue.

## Acceptance criteria

- [ ] Creating a lead without source or without any contact channel returns 422.
- [ ] A near-duplicate returns 201 **with** a `duplicates` array (no hard block).
- [ ] salesrep list returns only their leads; manager returns the team's.
- [ ] `reference` is unique and server-generated.
- [ ] Import reports invalid rows without creating them and creates valid rows.
- [ ] CSV import is available to every role; salesrep rows are self-owned and manager/admin rows
      default to unassigned with an optional target owner.
- [ ] Public intake creates an unassigned `website` lead, requires consent, and returns no internal
      ids or duplicate detail; throttling and the honeypot reject abuse.

## Open questions

- ~~Which channels need a real web-form endpoint vs manual entry for the demo?~~ — one embedded public
  request-staffing form backed by `POST /public/leads` (ADR-0026).
- ~~CSV import field mapping — fixed template or user-mapped?~~ — fixed template plus preview/commit
  (ADR-0026).
