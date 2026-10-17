# 08 — Lead-to-Client Conversion

Submodule 8. Entities: `Lead`, `Company`, `Contact`, `Opportunity`. ADRs: 0001, 0006, 0009, 0010,
0011, 0012.

> **Implemented.** The contract below is what shipped: the note under each section records the
> choices made where the spec was open (see ADR-0012). `CompanySite` is *not* created by a
> conversion — sites stay a manual, manager+ step on the company (spec 06).

## Purpose

Turn a qualified lead into durable records — Company + Contact(s) + Opportunity — in one atomic,
idempotent operation, without creating duplicate companies.

## Conversion outcomes

Given a `qualified` lead, create (atomically, in one transaction):

1. **Company** — `status = prospect`, `source_lead_id = lead.id`, `owner_id = lead.owner_id` (or the
   converting user if unassigned). Other profile fields come from the wizard or fall back to the
   lead's (`industry`).
2. **Contact** — from the lead's contact fields, one of them `is_primary = true`, `role_type` chosen
   in the wizard (pre-filled as `other`, i.e. "not classified yet").
3. **Opportunity** — `stage = qualified`, `name` defaulted from the lead (`"{company} — {service
   interest}"`), `owner_id`, `lead_id`, `primary_contact_id`, `source`, `currency = PHP`,
   `probability` from the stage (20).
4. Set `lead.status = converted`, `converted_company_id`, `converted_opportunity_id`; `converted`
   is terminal.

Events: `CompanyCreated`, `ContactCreated`, `OpportunityCreated`, `LeadConverted` (ALL dispatched
inside the transaction).

## Business rules

1. Only `status = qualified` leads can be converted (else 422 on `status`).
2. **Idempotent:** a `converted` lead cannot be converted again — `409`. Authorization runs first,
   so a caller who cannot edit the lead still gets `403`.
3. **Avoid duplicate companies (advisory):** the preview lists companies in the caller's scope that
   match the lead on **name (legal or trade), contact email, or contact phone** (last 7 digits),
   each with `matched_on`. The wizard offers to **link to an existing company** instead of creating
   one. It never blocks; the convert response repeats the advisory as `duplicates`. Full
   dedup/merge is Phase 2 (ADR-0006), and companies outside the caller's scope are neither offered
   nor reported.
4. All creation happens inside a DB transaction; any failure rolls back entirely (verified by a test
   that throws from a `ContactCreated` listener).
5. Sensitive fields are not copied onto the opportunity amount unless the converting user is
   manager+: a salesrep-opened deal has `amount = null` even if one is posted; manager+ conversions
   default the amount to the lead's `budget_rate` (an explicit `amount` wins). Deal value is visible
   to whoever can see the deal (ADR-0012 §4).
6. Conversion does **not** hand off to Service — that happens on **contract signed** (submodule 9).
7. A lead cannot be converted into a company in a different team than the caller's scope → `422` on
   `link_company_id` (only companies the caller can *view* are linkable).
8. At least one contact is required (`contacts` min 1); the first contact leads unless the wizard
   flags another one (ADR-0011 §3 invariant re-derived server-side).
9. Conversion always opens the deal at `qualified`; it creates no follow-up task (ADR-0012 §2–3).

## Permissions

salesrep own leads; manager team; admin/superadmin all — on the **lead** (`LeadPolicy::convert`
delegates to `update`, so an unassigned lead must be claimed by a salesrep first). Created records
are owned by the lead owner (or the converter for an unassigned lead); the company's visibility then
governs its contacts and its deals.

## API endpoints

| Method | Path | Description |
| --- | --- | --- |
| `GET` | `/api/v1/leads/{id}/conversion-preview` | Suggested company/contact/opportunity mapping + duplicate companies |
| `POST` | `/api/v1/leads/{id}/convert` | Body: `link_company_id?`, `company?`, `contacts[]`, `opportunity` |
| `GET` | `/api/v1/companies/{id}/opportunities` | Opportunities tab (filters: `stage`, `open`) + `meta { total, open }` |

**Preview payload:** `{ lead, company, contacts[], opportunity, duplicates[], can_convert,
can_set_amount, blocked_reason }`.

**Convert request:** either `link_company_id` (an existing, visible company; `company` is then
ignored/not required) or `company { name, trade_name?, industry?, size?, website?, description? }`.

**Convert response (201):** `{ data: { company, contacts[], opportunity, lead }, duplicates[] }`.
**Errors:** 422 not qualified / validation (missing company or link, no contacts) / target company
out of scope; 409 already converted.

**Deal visibility:** the opportunities list is scoped `salesrep → own deals`, `manager → team deals`,
`admin → all`, and opening the tab requires `CompanyPolicy::view` on the account. `stage` and `open`
filters accept comma-separated values.

## UI

- **Conversion wizard** (`/leads/:id/convert`) — 3 steps in a stepper (`FormCard`):
  1. Company (pre-filled; radio "create new" / "link an existing account" with account search;
     `AlertItem` warning listing duplicate candidates and what they matched on).
  2. Contacts (pre-filled from the lead; add/remove, choose `role_type`, pick the primary).
  3. Opportunity (name pre-filled, stage shown as `qualified` with its probability, close date;
     the amount input is rendered only when `can_set_amount`).
  A blocked lead (not qualified / already converted) shows the reason and offers no form.
- **Confirmation** — after converting, the wizard navigates to the new account (`/companies/:id`)
  with a success notice and the `duplicates` advisory in route state.
- **Lead detail** — the Lifecycle card shows a `Convert to client` action when `lead.can_convert`;
  a converted lead shows a banner linking to the account it became.
- **Company detail** — an **Opportunities** tab (read-only `DataTable`: reference, deal, stage badge,
  amount, probability, close date, owner, source lead link) plus a small pipeline summary card.
- **Conversion worklist** (`/conversion`) — qualified, not-yet-converted leads with a `Convert`
  link (added for discoverability; the sidebar lists the submodule).

## Events

- `LeadConverted` (lead, company, opportunity, contacts, actor) — enables the opportunity/contract
  flow (submodule 09) and the (future) service handoff.
- `OpportunityCreated` (opportunity, actor) — mirror of `CompanyCreated`/`ContactCreated`.

## Acceptance criteria

- [x] Converting a non-qualified lead returns 422.
- [x] Converting a `converted` lead returns 409 and creates nothing new.
- [x] On any step failure, no company/contact/opportunity is persisted (transaction rollback).
- [x] Linked-existing-company path attaches contact + opportunity without a duplicate company, and
      keeps the linked account's status.
- [x] Lead becomes `converted` and read-only with correct `converted_*` links, and the conversion is
      recorded as a `system` activity on its timeline.
- [x] salesrep conversion response omits the opportunity amount; manager+ inherits `budget_rate`.

## Open questions

- Initial opportunity stage: always `qualified`, or let the wizard choose? — **Always `qualified`**
  (ADR-0012 §2); the stage machine is the pipeline module's job.
- Should conversion auto-create a task/follow-up for the new opportunity? — **No** (ADR-0012 §3);
  follow-up reminders are a deferred module.
