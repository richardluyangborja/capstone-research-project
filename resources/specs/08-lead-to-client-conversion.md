# 08 — Lead-to-Client Conversion

Submodule 8. Entities: `Lead`, `Company`, `CompanySite`, `Contact`, `Opportunity`. ADRs: 0001, 0006.

## Purpose

Turn a qualified lead into durable records — Company + Contact(s) + Opportunity — in one atomic,
idempotent operation, without creating duplicate companies.

## Conversion outcomes

Given a `qualified` lead, create (atomically, in one transaction):

1. **Company** — `status = prospect`, `source_lead_id = lead.id`, `owner_id = lead.owner_id` (or the
   converting user if unassigned).
2. **Contact** — from the lead's contact fields, `is_primary = true`, `role_type` chosen in the wizard.
3. **Opportunity** — `stage = qualified`, `name` defaulted from the lead/company, `owner_id`,
   `lead_id`, `primary_contact_id`.
4. Set `lead.status = converted`, `converted_company_id`, `converted_opportunity_id`; `converted`
   is terminal.

## Business rules

1. Only `status = qualified` leads can be converted (else 422).
2. **Idempotent:** a `converted` lead cannot be converted again (409/422); the operation is safe to
   retry.
3. **Avoid duplicate companies (advisory):** the wizard offers to **link to an existing company**
   that matches the lead (name/email/phone) instead of creating a new one, and then attaches the
   contact/opportunity to it. Full dedup/merge is Phase 2 (ADR-0006).
4. All creation happens inside a DB transaction; any failure rolls back entirely.
5. Sensitive fields (e.g. `budget_rate`) are not copied onto the opportunity amount unless the
   converting user is manager+.
6. Conversion does **not** hand off to Service — that happens on **contract signed** (submodule 9).
7. A lead cannot be converted into a company in a different team than the caller's scope.

## Permissions

salesrep own leads; manager team; admin/superadmin all. Only users who can edit the lead may convert.

## API endpoints

| Method | Path | Description |
| --- | --- | --- |
| `GET` | `/api/v1/leads/{id}/conversion-preview` | Suggested company/contact/opportunity mapping + possible duplicate companies |
| `POST` | `/api/v1/leads/{id}/convert` | Body: `link_company_id?`, `company:{...}`, `contacts:[...]`, `opportunity:{...}` |

**Response:** `{ company, contacts, opportunity, lead }`.
**Errors:** 422 not qualified / missing required; 409 already converted.

## UI

- **Conversion wizard** (`/leads/:id/convert`) — 3 steps in a stepper (`FormCard`):
  1. Company (pre-filled; "link to existing" if duplicates found via `AlertItem`).
  2. Contacts (pre-filled; add more, choose `role_type`, primary).
  3. Opportunity (name, stage = qualified, amount/close date editable by manager+).
- Confirmation shows created records with links; lead becomes read-only.

## Events

- `LeadConverted` → (optional) notify manager; enables the opportunity/contract flow.

## Acceptance criteria

- [ ] Converting a non-qualified lead returns 422.
- [ ] Converting a `converted` lead returns 409 and creates nothing new.
- [ ] On any step failure, no company/contact/opportunity is persisted (transaction rollback).
- [ ] Linked-existing-company path attaches contact + opportunity without a duplicate company.
- [ ] Lead becomes `converted` and read-only with correct `converted_*` links.
- [ ] salesrep conversion response omits sensitive opportunity amount if not permitted.

## Open questions

- Initial opportunity stage: always `qualified`, or let the wizard choose?
- Should conversion auto-create a task/follow-up for the new opportunity?
