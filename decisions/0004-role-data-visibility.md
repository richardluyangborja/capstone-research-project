# 0004 — Role data visibility & ownership

- Status: Accepted
- Date: 2026-10-16

## Context

Roles are superadmin / admin / manager / salesrep (`../resources/system-roles.md`). Salesreps must
not see sensitive business data; managers oversee a team.

## Decision

- **salesrep** — own records only; sensitive data (rate cards, margins, billing) hidden.
- **manager** — their team's records; sensitive data view-only.
- **admin / superadmin** — all records.

Enforced **server-side**; ownership/team scoping is required on lead, company, contact, opportunity.

## Consequences

- Requires an ownership + team model and policy enforcement in the API layer.
- Reporting is scoped by role.
- See `../resources/crm-flow.md` (role visibility).

## Clarification — shared unassigned queue (submodule 03)

The unassigned lead pool (`owner_id = null`) is a **shared, claimable queue** and is therefore
visible to *every* role, so a salesrep can browse it and claim work (`POST /leads/{id}/claim`).
This is the single, deliberate exception to "salesrep — own records only".

- A salesrep may **view** a queued lead but must **claim** it before editing/qualifying it
  (`LeadPolicy::update` denies unassigned leads to salesreps).
- Managers/admins may edit the queue directly and assign to any qualifying target
  (manager → their team; admin → any salesrep).
- Round-robin distribution (spec 03 §3) is deferred; assignment is manual + claim-based for now.
- Encoded in `Lead::scopeVisibleTo` and `App\Policies\LeadPolicy`.
