# 01 — Lead Capture & Intake

Submodule 1 of Lead & Client Tracking. Entities: `Lead` (`00-data-model.md`). ADRs: 0001, 0004.

## Purpose

Capture every inbound/outbound inquiry as a `Lead` with its source, from any channel, and give an
advisory duplicate warning before creating a record.

## Scope

- **In:** manual creation, bulk CSV import, channel/source tagging, advisory duplicate check,
  listing/filtering/searching leads.
- **Out:** the full duplicate-merge workflow (Phase 2), campaign management, automated web-form
  embedding (a public `POST /leads/intake` endpoint is enough for Phase 1).

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
6. Import is all-or-nothing per row: invalid rows are reported, valid rows are created.

## Permissions

| Action | salesrep | manager | admin / superadmin |
| --- | --- | --- | --- |
| Create / import | ✓ | ✓ | ✓ |
| List | Own only | Team | All |
| View | Own only | Team | All |
| Delete/restore | Own only | Team | All |

## API endpoints

| Method | Path | Description |
| --- | --- | --- |
| `POST` | `/api/v1/leads` | Create a lead |
| `POST` | `/api/v1/leads/import` | Bulk CSV import (multipart) |
| `POST` | `/api/v1/leads/intake` | Channel/website intake (source required) |
| `GET` | `/api/v1/leads` | List + filters (`status,source,owner_id,rating,created_at`) + `search` |
| `GET` | `/api/v1/leads/{id}` | Detail |
| `DELETE` | `/api/v1/leads/{id}` | Soft delete |
| `POST` | `/api/v1/leads/{id}/restore` | Restore |

**Create request (summary):** `company_name, contact_name, contact_email?, contact_phone?,
source, source_detail?, industry?, location?, service_interest?, estimated_headcount?, needed_roles?,
notes?`. **Response:** created lead + optional `duplicates` (id/company_name/match_reason).

## Validation

- `source` must be a `LeadSource` value; `other` ⇒ `source_detail` required.
- `contact_email` valid email if present; `contact_phone` digit/length sanity if present.
- `estimated_headcount` integer ≥ 1 if present.

## UI

- **Leads list** `/leads` — `DataTable` columns: reference, company, contact, source, status
  (`StatusBadge`), rating, owner, last activity; filters + search (blue = counts).
- **New lead** `/leads/new` — `FormCard`; duplicate warnings via `AlertItem` (non-blocking).
- **Import** dialog on list — upload CSV, map columns, show per-row results.
- **Empty state** via `EmptyState`.

## Events

- `LeadCreated` — notify assignee (if any) or manager queue.

## Acceptance criteria

- [ ] Creating a lead without source or without any contact channel returns 422.
- [ ] A near-duplicate returns 201 **with** a `duplicates` array (no hard block).
- [ ] salesrep list returns only their leads; manager returns the team's.
- [ ] `reference` is unique and server-generated.
- [ ] Import reports invalid rows without creating them and creates valid rows.

## Open questions

- Which channels need a real web-form endpoint vs manual entry for the demo?
- CSV import field mapping — fixed template or user-mapped?
