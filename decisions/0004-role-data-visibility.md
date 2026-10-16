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
