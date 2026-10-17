# 0010 — Company / account conventions

- Status: Accepted
- Date: 2026-10-17

## Context

Submodule 06 (`../resources/specs/06-company-account-management.md`) introduces the `Company`
record every lead converts into and every client is built on. Its spec left three things open or
spread thinly: who may create a company manually, how branches relate to their parent, and what the
detail page may roll up before contacts, opportunities and contracts exist (submodules 07–09).

## Decision

1. **Manual creation is open to every role**, including `salesrep` — importing an existing client is
   routine sales work and blocking it would make managers a bottleneck. This deviates from spec 06's
   "manager+" default in one place only; everything a salesrep creates is owned by them and starts as
   a `prospect`. A salesrep cannot open a company directly in a client state, and cannot create a
   record owned by somebody else.
2. **Structural operations stay manager+**: delete/restore, managing sites, and (re)linking a branch
   are manager+ within their scope; a salesrep gets `403` (or a `422` for the branch field), matching
   the spec's permission table and ADR-0004's conservative salesrep scope. Updates to the profile
   fields remain own/team/all.
3. **Status is not editable by non-admins.** `PATCH /companies/{id}` accepts `status` only from an
   admin (override). Creation may set it for manager+ (importing an existing client). Manager+
   advance the lifecycle through the dedicated `PATCH /companies/{id}/lifecycle` introduced by
   submodule 09 (ADR-0013 §6), which validates against the state machine — the two paths do not
   overlap.
4. **Branches are independent records.** `parent_company_id` is a link, not a roll-up: a branch
   inherits nothing, keeps its own owner/sites/contacts, and creates no aggregate numbers on the
   parent. Cycles are rejected with a `422`. This keeps "the branch is a separate operating record"
   honest instead of half-implementing head-office aggregation.
5. **Roll-ups are limited to what exists.** `counts` carries `sites`, `branches`, `contacts`
   (submodule 07, ADR-0011), `opportunities` (submodule 08, ADR-0012), `contracts` (submodule 09,
   ADR-0013) and `activities`.
6. **Delete is guarded, not blocked.** `Company::deletionBlockers()` is the single extension point
   for "has active contracts/opportunities". Submodule 08 activated the opportunity half — any deal
   with `stage ∉ {won, lost}` returns `422` with a message naming the count. Submodule 09 added the
   contract half: any `signed` contract returns `422`. So a delete is a soft delete (restorable) that
   already refuses to orphan live work.
7. **The activity timeline is shared.** `ActivityResource` is extracted from the lead lifecycle
   controller and reused for companies, so one polymorphic `activities` table and one payload shape
   serve every record type.

## Consequences

- The company detail page drives its controls from affordances (`can_update`, `can_manage_sites`,
  `can_manage_parent`, `can_assign`, `can_delete`, plus `can_manage_lifecycle`, `can_view_billing`
  and `can_upload_contract` from submodule 09) rather than from role checks in the UI.
- Conversion (submodule 08) can create companies for any role because creation is unrestricted; it
  must still set the owner from the source lead.
- `deletionBlockers()` carries both blockers now: an open (non-won/lost) deal (submodule 08) and a
  `signed` contract (submodule 09). Spec 06's acceptance criterion "deleting a company with an active
  contract returns 422" is satisfied by the same method.
- Roll-up widgets for contracts arrived with submodule 09 (the Contracts tab and `counts.contracts`);
  spec 06, spec 09 and `AGENTS.md` record it.
