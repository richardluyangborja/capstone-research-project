# 0011 — Contact conventions

- Status: Accepted
- Date: 2026-10-17

## Context

Submodule 07 (`../resources/specs/07-contact-management.md`) adds the people at a company — the
decision-makers staffing work runs through. The spec fixed the fields and the "one primary per
company" rule, but left open how permissions attach, what happens to the flag when the primary
leaves, how a repeated email is treated, and which surfaces exist.

## Decision

1. **Contacts inherit the company.** There is no `owner_id` on `contacts`: every permission and all
   list scoping resolve through the owning company (ADR-0004), so a contact can never drift out of
   the visibility of whoever owns the account.
2. **Contacts are operational, not structural.** Unlike sites and branch links (manager+ per
   ADR-0010), anyone who may update the company may maintain its contacts — including a salesrep on
   their own account. That is the literal reading of spec 07's permission table and matches the job:
   a salesrep meets the hiring manager, not the manager.
3. **Exactly one primary contact per company, among active contacts.** The first contact added
   becomes primary; `make-primary` moves the flag explicitly; deleting or deactivating the primary
   promotes the oldest remaining active contact; an inactive contact can never be primary; a restored
   contact only takes the flag when nobody else holds it. Inactive contacts are skipped everywhere.
4. **A repeated email is advisory, never blocking.** Creating or updating a contact whose email
   already exists on the same company returns the record with a `duplicates` array — the same
   "warn, don't fail" pattern as a company name collision (spec 06 §2). The same email on a
   *different* company is not flagged at all.
5. **Contacts are never hard deleted** (spec 07 §6): `status = inactive` takes someone out of
   consideration while keeping their history, and `DELETE` is a soft delete with a matching restore.
6. **Single contact channels in Phase 1** — `email`, `phone`, `mobile` as the data model defines
   them. Multiple emails/phones per person would need a child table and is deferred.
7. **Two surfaces, one editor.** The Contacts tab on `/companies/:id` is where contacts are created,
   edited, promoted and retired; `/contacts` is a read-only book across every account the caller may
   see, linking each row back to its company. There is deliberately no contact detail page.
8. **No contact activity timeline.** Activities stay attached to leads and companies in Phase 1; the
   `activities` table remains polymorphic, so a contact timeline can be added later without migration.

## Consequences

- `ContactResource` exposes affordances (`can_update`, `can_make_primary`, `can_delete`) so the UI
  never re-derives permissions from the role.
- Company roll-up `counts` gain `contacts`; the remaining counts (opportunities, contracts) arrive
  with submodules 08–09.
- Conversion (submodule 08) creates a company's first contact from the lead's contact details and
  flags it primary (spec 07 §5) — allowed for any role, because creation follows company-update
  rights.
- The primary invariant is maintained by `Contact::promoteToPrimary()` and
  `Contact::syncPrimaryFor()`; controllers call one of the two after every mutation, so a new code
  path cannot quietly leave two primaries behind — or none.
