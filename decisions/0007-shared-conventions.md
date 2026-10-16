# 0007 — Shared implementation conventions

- Status: Accepted
- Date: 2026-10-16

## Context

Before scaffolding, the specs need one agreed set of shared conventions so every subsystem behaves
consistently. Details: `../resources/specs/README.md` and `../resources/specs/00-data-model.md`.

## Decision

- **IDs:** **ULID** primary keys (non-sequential, URL-safe) for every entity exposed in a URL/list.
  **No auto-incrementing IDs** in the API/URL (prevents enumeration). Human references
  (`LD-2026-0001`, …) are display/search only and never route keys. Internal framework/audit tables
  may use bigint.
- **Locale / currency (PH-based):** locale `en-PH`, `Asia/Manila` (UTC+8) for display (store UTC),
  dates `DD MMM YYYY`, PH address format (Barangay / City-Municipality / Province / Region / ZIP),
  PH phone format, and **single currency PHP (₱)** — no multi-currency.
- **API:** `/api/v1`, JSON, JWT bearer; `{ "data": ... }` responses, list adds `meta`/`links`;
  pagination `?page` + `?per_page` (default 25, max 100); `?sort=`, `?filter[]=`, `?search=`;
  errors `{ "message", "errors" }`; soft delete + `/restore`.
- **Money:** `decimal(15,2)`. **Timestamps:** stored UTC, serialised ISO-8601.
- **Attachments:** polymorphic, **private** disk, pdf/png/jpeg, max 10 MB, signed-URL access.
- **Audit:** every mutation logged; user-visible changes also create an `activities` row.
- **Permissions:** role + ownership/team, enforced server-side (ADR-0004).
- **Frontend:** render inside `DashboardShell`; reuse shared components and design tokens only.

## Consequences

- Route model binding is by ULID; seed data uses fixed PH sample data (no faker — see `system-tech.md`).
- Single currency keeps money handling simple; revisit only if multi-currency is ever required.
- Enum/field names in `00-data-model.md` are the contract for migrations, API resources, and UI.
