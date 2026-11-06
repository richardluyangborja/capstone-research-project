# 07 — Contact Management

Submodule 7. Entities: `Contact`, `Company`, `Activity`. ADRs: 0001, 0004, 0011.

## Purpose

Track the people at a company — especially decision-makers relevant to staffing (hiring manager,
HR, procurement, finance) — with a designated primary contact per company.

## Business rules

1. Every contact belongs to exactly one company (`company_id` required, set at creation and never
   moved afterwards).
2. `role_type` is required and limited to `ContactRoleType`.
3. **Exactly one primary contact per company, among active contacts.** The first contact added is
   primary; setting `is_primary = true` (or `POST /contacts/{id}/make-primary`) clears the previous
   primary (transactional); deleting or deactivating the primary promotes the oldest remaining
   active contact; an inactive contact can never be primary.
4. Email duplication is enforced **per company as an advisory** (ADR-0011): a repeated email is
   reported back in a `duplicates` array and the save still succeeds; the same email on a different
   company is allowed and not reported.
5. On conversion (submodule 8), lead contact details become the company's first contact, flagged
   primary.
6. `status = inactive` hides a contact from pickers but keeps history; contacts are never hard
   deleted (`DELETE` is a soft delete, with `POST /contacts/{id}/restore`).
7. Contacts can be linked to opportunities as `primary_contact_id`.
8. A contact's `created_at` never changes and its history is preserved: retiring one is reversible.

## Permissions

Inherited from the owning company (ADR-0004 / ADR-0011): salesrep own, manager team, admin/superadmin
all. Because a contact is operational rather than structural, the full CRUD set — including delete,
restore and `make-primary` — follows the company's **update** rights; there is no manager+ gate as
there is for sites and branch links.

## API endpoints

| Method | Path | Description |
| --- | --- | --- |
| `GET` | `/api/v1/companies/{companyId}/contacts` | List a company's contacts (filters: `role_type,status`) |
| `POST` | `/api/v1/companies/{companyId}/contacts` | Create contact — 201 with a `duplicates` advisory |
| `GET` | `/api/v1/contacts` | Global contact book, paginated (filters: `company_id,role_type,status` + `search`) |
| `GET` | `/api/v1/contacts/{id}` | Detail |
| `PATCH` | `/api/v1/contacts/{id}` | Update (`company_id` is not accepted) — `duplicates` advisory |
| `POST` | `/api/v1/contacts/{id}/make-primary` | Set as the company's primary (active contacts only) |
| `DELETE` | `/api/v1/contacts/{id}` | Soft delete |
| `POST` | `/api/v1/contacts/{id}/restore` | Restore |

The company-scoped list returns the whole (scoped) list, matching the sites endpoint; the global
book paginates. `role_type`/`status` filters accept comma-separated values.

## Validation

- `name` and `role_type` required; `email` valid if present; `phone`/`mobile` sanity if present
  (digits with optional `+`, spaces, dashes or parentheses).
- `company_id` must exist and be in the caller's scope — enforced by the route binding plus
  `ContactPolicy::create(user, company)`.
- Unknown `role_type`/`status` values are rejected (422).

## UI

- **Contacts tab** on `/accounts/:id` — `DataTable` with role badges (people-domain green),
  the primary row badged `Primary`, role/status filters, and row actions: `Make primary`, `Edit`
  and `Retire`. The add/edit `FormCard` sits beside it with name, position, role, email, phone,
  mobile, status and a primary toggle (new contacts only).
- **Contacts index** `/contacts` — global list across the caller's scope (filters: search, role,
  status, company), read-only: each row links to the account that owns it.
- Decision-maker types shown with distinct badges (people-domain styling = green); `other` stays
  neutral.

## Events

- `ContactCreated` — fired on manual creation and (submodule 08) on conversion.
- `ContactPrimaryChanged` — fired whenever the flag moves, including when the previous primary was
  deactivated or removed.

## Acceptance criteria

- [x] Creating a contact with an email already used on the same company returns 201 with a
      `duplicates` advisory (ADR-0011), while the same email on another company is allowed and
      reported as no duplicate.
- [x] Making a contact primary unsets the previous primary in one operation.
- [x] `role_type` is required and enum-validated.
- [x] Deactivated contacts disappear from pickers (`filter[status]=active` and the primary rules)
      but remain on the account and on historical records.

## Open questions

- Is a global `/contacts` index needed for Phase 1, or only the company tab? — **Both**: the company
  tab edits, the global book reads across accounts (ADR-0011 §7).
- Do we need multiple emails/phones per contact now, or single fields suffice? — **Single fields**
  for Phase 1; a `contact_channels` table can follow if asked (ADR-0011 §6).
