# Specs — CRM modules

Implementation specs for the CRM modules (`../system-modules.md`, `../crm-flow.md`). Read
`00-data-model.md` first — all specs share its entities, enums, and conventions. Decisions referenced
as ADR live in `../../decisions/`. The roadmap (module order + numbering) is ADR-0014: `00` is the
shared data model, `01`–`09` are module 01's submodules, and `10+` is **one file per later module**,
with that module's **delivery slices** declared in the file (ADR-0016).

## Module 01 — Lead & Client Tracking (done)

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

Deferred **inside** module 01: 5. Duplicate Detection & Merge and advanced tiers/health scoring
(ADR-0006). These are not "Phase 2" — they are tracked module-01 deferrals.

## Later modules (ADR-0014)

| File | Module |
| --- | --- |
| `10-opportunity-pipeline-visualization.md` | 2. Opportunity Pipeline Visualization *(done — 1 slice)* |
| `11-communication-history.md` | 3. Communication History Management *(done — 5 slices)* |
| `12-follow-up-reminders.md` | 4. Follow-up Reminder System *(done — 4 slices)* |
| `13-client-satisfaction-survey.md` | 5. Client Satisfaction & Survey *(in progress — 4 slices; S1–S3 built)* |

The AI service (dashboard + management reports + suggestions) is cross-cutting and is built after
modules 01–05.

From module 02 on, the delivery unit is the **slice**, not the spec file: each spec declares its slices
with their own acceptance criteria and each ships as its own PR (ADR-0016). Module 01 predates this and
used one file (and one PR) per submodule.

## Shared conventions (proposed defaults — confirm before scaffolding)

- **Stack:** Laravel `backend/` exposing `/api/v1` (OpenAPI), React + Vite `frontend/`, Postgres, JWT.
- **IDs:** **ULID** primary keys (non-sequential, URL-safe) for every entity exposed in a URL/list —
  Lead, Company, Site, Contact, Opportunity, Activity, Contract, Attachment. **Never use
  auto-incrementing IDs** for records addressed via the API/URL (prevents enumeration). Human
  references (`LD-2026-0001`, `CO-2026-0001`, `OPP-2026-0001`, `CON-2026-0001`) are **display and
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
- **Documents:** stored on a **private** disk and never exposed by path; downloads go through an
  authorized endpoint (`GET /contracts/{id}/file` in Phase 1). Allowed MIME `application/pdf`,
  `image/png`, `image/jpeg`; max 10 MB. A record needing *several* files would use the polymorphic
  `attachments` table, which is deferred (`00-data-model.md`).
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
  `/contacts`, `/opportunities`, `/opportunities/:id`, `/pipeline`.

## Phase-1 event map (for wiring + mocks)

| Event | Emitted by | Effect |
| --- | --- | --- |
| `LeadCreated` | intake | notify assignee/queue |
| `LeadQualified` | qualification | eligible for conversion |
| `LeadConverted` | conversion | create Company/Contact/Opportunity (ADR-0001) |
| `OpportunityWon` | deal pipeline (module 02) | deal closed won; prompts contract creation — does **not** hand off to Service (ADR-0015 §4) |
| `ContractSigned` | client lifecycle | contract `signed` → company advances + **handoff to Service** (mock) |
| `CompanyStatusChanged` | client lifecycle | timeline activity per lifecycle move |
| `CompanyActivated` / `CompanyChurned` | client lifecycle | win/retention bookkeeping (reporting deferred) |

Shared conventions are locked in **ADR-0007**. Remaining decisions to confirm:

- file storage driver — **local private disk** (ADR-0013 §2); an S3-compatible bucket is a disk swap.
- automatic aging/dormancy thresholds and whether they run in Phase 1 — **manual only in Phase 1**
  (ADR-0013 §3); the thresholds return with the deferred follow-up module.
- round-robin assignment and whether `onboarding` is a distinct state (see submodule open questions) —
  round-robin stays deferred (spec 03 §3); `onboarding` **stays distinct** as the state a signed
  client waits in for the Service handoff (spec 09).
