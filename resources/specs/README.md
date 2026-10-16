# Phase-1 Specs — Lead & Client Tracking

Implementation specs for the submodules in `../crm-flow.md`. Read `00-data-model.md` first — all
specs share its entities, enums, and conventions. Decisions referenced as ADR live in `../../decisions/`.

## Index (file ↔ submodule number)

| File | Submodule |
| --- | --- |
| `00-data-model.md` | Shared entities, fields, enums, relationships |
| `01-lead-capture-intake.md` | 1. Lead Capture & Intake |
| `02-lead-qualification-scoring.md` | 2. Lead Qualification & Scoring |
| `03-lead-assignment-ownership.md` | 3. Lead Assignment & Ownership |
| `04-lead-lifecycle-status.md` | 4. Lead Lifecycle & Status |
| `06-company-account-management.md` | 6. Company / Account Management |
| `07-contact-management.md` | 7. Contact Management |
| `08-lead-to-client-conversion.md` | 8. Lead-to-Client Conversion |
| `09-client-account-lifecycle.md` | 9. Client Account Lifecycle |

Deferred to Phase 2: 5. Duplicate Detection & Merge, advanced tiers/health, and the separate
communication-history, follow-up, and satisfaction modules.

## Shared conventions (proposed defaults — confirm before scaffolding)

- **Stack:** Laravel `backend/` exposing `/api/v1` (OpenAPI), React + Vite `frontend/`, Postgres, JWT.
- **IDs:** **ULID** primary keys (non-sequential, URL-safe) for every entity exposed in a URL/list —
  Lead, Company, Site, Contact, Opportunity, Activity, Contract, Attachment. **Never use
  auto-incrementing IDs** for records addressed via the API/URL (prevents enumeration). Human
  references (`LD-2026-0001`, `CO-2026-0001`, `OPP-2026-0001`, `CT-2026-0001`) are **display and
  search only** and are never used as route keys. Internal framework/audit tables may still use bigint.
- **Common columns** on every table: `id` (ULID), `created_at`, `updated_at`, `created_by`,
  `updated_by`, `owner_id` (where applicable), `deleted_at` (soft delete).
- **Money:** `decimal(15,2)` in **PHP (Philippine Peso, ₱) only** — single currency, no
  multi-currency; format as `₱1,234.56`.
- **Locale / timezone (PH-based):** locale `en-PH`; store timestamps UTC and **display in
  `Asia/Manila` (UTC+8)**; dates shown `DD MMM YYYY`; addresses in PH format
  (Barangay / City-Municipality / Province / Region / ZIP); phones in PH format (`+63…` / `09…`).
- **Timestamps:** store UTC, serialise ISO-8601; date-only fields for pure dates.
- **Enums:** stored as lowercase strings; defined in `00-data-model.md`. Never store free text where
  an enum exists.
- **Attachments:** polymorphic (`attachable_type/id`); allowed MIME `application/pdf`,
  `image/png`, `image/jpeg`; max 10 MB; stored on a **private** disk; access via signed URLs.
- **Audit:** every mutation writes a row to `audit_logs` (actor, action, before/after) and, where
  user-visible, an `activities` row.

## API conventions

- Base path `/api/v1`; JSON only; `Authorization: Bearer <jwt>`.
- Resource endpoints, standard verbs. Status codes: 200 read, 201 create, 204 delete, 422
  validation, 403 policy, 404 missing.
- **List endpoints** support: `?page=`, `?per_page=` (default 25, max 100), `?sort=-created_at`,
  `?filter[field]=value`, `?search=` (name/company/email/phone).
- **Responses:** `{ "data": ... }` for single; list adds `"meta"` + `"links"`. Errors:
  `{ "message": ..., "errors": { field: [...] } }`.
- **Soft delete:** `DELETE /resource/{id}` soft-deletes; `POST /resource/{id}/restore` restores.
- Field names `snake_case`; resource paths plural.

## Permissions model (ADR-0004)

Enforced **server-side** via policies combining role + ownership/team:

- `salesrep` — own records only; sensitive fields (rate, budget, billing) stripped from responses.
- `manager` — records owned by users in their team; sensitive fields read-only.
- `admin`, `superadmin` — all records.

Requires a **Team** relation on users (`users.team_id`, `teams.manager_id`). Unassigned/queue
records are visible to manager+.

## Frontend conventions

- All CRM pages render inside the shared `DashboardShell` (ADR + `../departamental-design-instruction.md`).
- Reuse shared components: `DataTable`, `FormCard`, `PageHeader`, `StatusBadge`, `StatCard`,
  `EmptyState`, `AlertItem`. Never hardcode colors — use tokens.
- Domain color mapping: counts=blue, people=green, activity=purple, cost=orange.
- Suggested routes (dashboard): `/leads`, `/leads/:id`, `/companies`, `/companies/:id`,
  `/contacts`, `/opportunities`, `/opportunities/:id`.

## Phase-1 event map (for wiring + mocks)

| Event | Emitted by | Effect |
| --- | --- | --- |
| `LeadCreated` | intake | notify assignee/queue |
| `LeadQualified` | qualification | eligible for conversion |
| `LeadConverted` | conversion | create Company/Contact/Opportunity (ADR-0001) |
| `OpportunityWon` | (pipeline module) | enable contract upload |
| `ContractSigned` | client lifecycle | company → active client; **handoff to Service** (mock) |
| `CompanyChurned` | client lifecycle | retention/win-back reporting |

Shared conventions are locked in **ADR-0007**. Remaining decisions to confirm:

- file storage driver (local private disk vs S3-compatible)
- automatic aging/dormancy thresholds and whether they run in Phase 1
- round-robin assignment and whether `onboarding` is a distinct state (see submodule open questions)
