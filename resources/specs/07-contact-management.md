# 07 — Contact Management

Submodule 7. Entities: `Contact`, `Company`, `Activity`. ADRs: 0001, 0004.

## Purpose

Track the people at a company — especially decision-makers relevant to staffing (hiring manager,
HR, procurement, finance) — with a designated primary contact per company.

## Business rules

1. Every contact belongs to exactly one company (`company_id` required).
2. `role_type` is required and limited to `ContactRoleType`.
3. **Exactly one primary contact per company.** Setting `is_primary = true` clears the previous
   primary (transactional).
4. Email uniqueness is enforced **per company** (warn/skip duplicate email on the same company,
   allow the same email across different companies).
5. On conversion (submodule 8), lead contact details become the company's first contact, flagged
   primary.
6. `status = inactive` hides a contact from pickers but keeps history; contacts are never hard
   deleted.
7. Contacts can be linked to opportunities as `primary_contact_id`.

## Permissions

Inherited from the owning company (ADR-0004): salesrep own, manager team, admin/superadmin all.

## API endpoints

| Method | Path | Description |
| --- | --- | --- |
| `GET` | `/api/v1/companies/{companyId}/contacts` | List contacts (filters: `role_type,status`) |
| `POST` | `/api/v1/companies/{companyId}/contacts` | Create contact |
| `GET` | `/api/v1/contacts/{id}` | Detail |
| `PATCH` | `/api/v1/contacts/{id}` | Update |
| `POST` | `/api/v1/contacts/{id}/make-primary` | Set as the company's primary |
| `DELETE` | `/api/v1/contacts/{id}` | Soft delete |
| `POST` | `/api/v1/contacts/{id}/restore` | Restore |

## Validation

- `name` and `role_type` required; `email` valid if present; `phone`/`mobile` sanity if present.
- `company_id` must exist and be in the caller's scope.

## UI

- **Contacts tab** on `/companies/:id` — `DataTable` with role badges; primary row highlighted.
- **Contact form** modal/page (`FormCard`): name, role_type, email, phone, mobile, primary toggle.
- **Contacts index** `/contacts` — global list across the caller's scope (filter by role/company).
- Decision-maker types shown with distinct badges (people-domain styling = green).

## Events

- `ContactCreated`, `ContactPrimaryChanged`.

## Acceptance criteria

- [ ] Creating a contact with an email already used on the same company returns 422 (or a clear
      duplicate warning), but the same email on another company is allowed.
- [ ] Making a contact primary unsets the previous primary in one operation.
- [ ] `role_type` is required and enum-validated.
- [ ] Deactivated contacts disappear from pickers but remain on historical records.

## Open questions

- Is a global `/contacts` index needed for Phase 1, or only the company tab?
- Do we need multiple emails/phones per contact now, or single fields suffice?
