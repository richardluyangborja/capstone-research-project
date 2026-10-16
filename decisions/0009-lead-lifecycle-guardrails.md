# 0009 — Lead lifecycle transition guardrails

- Status: Accepted
- Date: 2026-10-16

## Context

Submodule 4 (`../resources/specs/04-lead-lifecycle-status.md`) adds a status state machine, an aging
flag, and archiving. Building it raised four choices the spec left open or implied:

1. Are the aging thresholds (proposed 14/30/60 days) fixed or configurable?
2. Do status transitions need a reason?
3. Does the generic `PATCH /leads/{id}/status` endpoint share the submodule-2 qualification rules,
   or can it bypass them?
4. `qualified → converted` is in the state machine, but conversion (submodule 8) also has to create
   a Company/Client.

## Decision

- **Aging thresholds are configurable** in `backend/config/crm.php` (`CRM_STALE_DAYS` 14,
  `CRM_AT_RISK_DAYS` 30, `CRM_ARCHIVE_DAYS` 60). They are computed on read — no stored flag and no
  migration to retune them.
- **Transitions take an optional note** (stored on the `system` activity). The only required text is
  the existing disqualification `reason`.
- **The state machine is the single gate.** `PATCH /leads/{id}/status` enforces the same rules as the
  semantic endpoints (submodule 2): qualifying still requires the minimum criteria
  (headcount + roles + a contact channel), and disqualifying still requires a reason. `POST
  /leads/{id}/qualify|disqualify|nurture` are kept as thin wrappers over the same transition.
- **Direct conversion is deferred.** `converted` stays in `LeadStatus::allowedTransitions()` for
  submodule 8, but `actionableTransitions()` (the list exposed to the API/UI) omits it, so a lead can
  only become a client through the conversion flow that creates the client record.

## Consequences

- Aging can be tuned per environment; the UI only renders the computed level
  (`stale` / `at_risk` / `archive`).
- `new → qualified` is no longer reachable directly — a lead must be `contacted` first. That is the
  spec's state machine, and it is what makes the "log the first outbound activity" rule meaningful.
- Reaching `qualified`/`disqualified` is impossible without meeting the submodule-2 requirements,
  whichever endpoint is used.
- Submodule 8 must transition to `converted` through the model and create the client; it should not
  just widen `actionableTransitions()`.
