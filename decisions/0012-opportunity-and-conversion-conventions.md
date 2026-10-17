# 0012 — Opportunity & conversion conventions

- Status: Accepted
- Date: 2026-10-17

## Context

Submodule 08 (`../resources/specs/08-lead-to-client-conversion.md`) turns a qualified lead into a
company, its first contact(s) and a first deal, and it introduces the `Opportunity` record that
submodule 09 (contracts, client lifecycle) and the later pipeline module build on. Building it
raised five questions the spec left open or answered only loosely:

1. How deep is submodule 08's opportunity support — does it ship the deal pipeline?
2. Which stage does a converted deal open at?
3. Does conversion create a follow-up task?
4. Is the deal `amount` sensitive data hidden from salesreps (ADR-0004), or ordinary data?
5. What stops a second conversion of the same lead, and can conversion target an account the caller
   cannot see?

## Decision

1. **Submodule 08 builds the record, not the pipeline.** Conversion creates the opportunity; the
   company page gains a **read-only** Opportunities tab (`GET /companies/{id}/opportunities`). Stage
   moves, won/lost, proposal tracking and the Kanban *pipeline visualization* are a separate module
   and stay out of scope here — a deal cannot be edited through the API yet, and `OpportunityPolicy`
   therefore only polices viewing.
2. **Conversion always opens the deal at `qualified`.** The lead was just qualified; letting the
   wizard pick a later stage would invent progress that never happened.
3. **Conversion creates no follow-up task.** Follow-up reminders are a deferred module
   (ADR-0006); conversion stays atomic and side-effect-free beyond the three records. The
   `system` activity on the lead timeline is the only trace.
4. **Deal value is visible to whoever can see the deal, but only manager+ may set it** (spec 08 §5,
   user decision). ADR-0004 keeps *rate cards, margins and billing* hidden from salesreps; a deal's
   `amount` is the commercial context its owner works with, so it is shown to the owner/team/admin
   that can view the record. Because a salesrep cannot set it, a salesrep-opened deal simply starts
   with `amount = null`; a manager+ conversion defaults the amount to the lead's sensitive
   `budget_rate` unless an explicit amount is sent. `can_set_amount` on the preview payload is
   server-derived so the wizard never guesses.
5. **Conversion is idempotent and scope-bound.** `converted` is terminal: a second attempt returns
   `409` (after authorization, which still applies — another salesrep gets `403` first). Only
   `qualified` leads convert (`422` otherwise). `link_company_id` must resolve to a company inside
   the caller's scope, else `422` on that field. The whole operation runs in one transaction;
   `LeadConverted`, `CompanyCreated`, `ContactCreated` and `OpportunityCreated` are dispatched from
   inside it.

Two implementation details worth recording:

- **The lead's rate is copied once, at conversion.** Later edits to the lead are impossible
  (terminal status), so the opportunity's amount is the only surviving copy.
- **Contacts are created through the relation and then re-derived** with
  `Contact::syncPrimaryFor()`, so the "exactly one primary per company" invariant (ADR-0011 §3) holds
  even when the wizard flags a later contact as primary.

## Consequences

- `counts` now reports `opportunities`, and `Company::deletionBlockers()` finally has a blocker:
  a company with any **open** deal (`stage ∉ {won, lost}`) returns `422` on delete. Contracts plug
  into the same method in submodule 09.
- `LeadResource` exposes `can_convert`, `is_converted`, `converted_company_id` and
  `converted_opportunity_id`, so the lead UI never re-derives conversion state.
- `budget_rate` is no longer dead data: it seeds the first deal for manager+ conversions.
- Opportunity references are `OPP-YYYY-NNNN` (display only, ADR-0007).
- The company detail page carries an Opportunities tab from 08 onward; the deal detail page and
  pipeline board remain a follow-on module.
