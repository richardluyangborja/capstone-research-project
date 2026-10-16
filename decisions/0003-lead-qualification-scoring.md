# 0003 — Lead qualification & scoring

- Status: Accepted
- Date: 2026-10-16

## Context

Generic BANT fits product sales; a manpower agency qualifies on staffing-specific signals.

## Decision

Qualify leads with a staffing-specific checklist plus a rating:

- **Checklist:** required headcount, roles/skills, start date/urgency, budget/rate, decision-making
  authority, work site/location.
- **Rating:** Hot / Warm / Cold (score optional).
- **Disqualification:** capture a reason; support a **Nurture** state for future revisit.

## Consequences

- Qualification fields drive filtering, assignment priority, and conversion readiness.
- See `../resources/crm-flow.md` (submodule 2).
