# 0028 — The lead journey is a derived view

- Status: Accepted
- Date: 2026-11-05

## Context

The lead state machine intentionally requires `new → contacted → qualified → converted` and rejects
`new → qualified` (`../specs/04-lead-lifecycle-status.md`). That rule is sound, but the current
lead detail page exposes only a status dropdown in the right-hand Lifecycle card. A user who wants to
qualify a fresh lead sees no explanation that the lead must be contacted first, and the missing
checklist criteria are shown as raw field keys.

The Account 360 page already solves the same orientation problem with a read-only journey stepper.
The lead page should use the same language without changing the state machine or adding a second
source of truth.

## Decision

1. **The lead journey is a derived, read-only view.** It is computed from the existing `LeadResource`
   (`status`, `missing_criteria`, `can_convert`, converted account/deal ids and activity/timeline
   data). It is not stored, not a new status and not a migration.

2. **The main path is Captured → Contacted → Qualified → Converted.** The journey uses the shared
   `JourneyStepper` with `label="Lead journey"`. It shows what is done, what is current and what comes
   next; it never moves the record.

3. **The journey explains the gate instead of bypassing it.** When a lead is `new`, the current step is
   **Contacted**, with a CTA to mark the lead contacted or log an outbound activity. When a lead is
   `contacted`, the current step is **Qualified**; missing criteria are named and the qualify action is
   only offered when the checklist is complete. A `qualified` lead gets the existing **Convert to
   client** action. `new → qualified` stays illegal.

4. **Branch states are shown beside the main path, not forced into it.** `nurture`, `disqualified` and
   `archived` get a clear branch callout with the valid re-entry path:
   - nurture → re-engage or qualify when ready
   - disqualified → re-open through Contacted
   - archived → restore to New to restart

   The linear steps only claim what the current status proves; the timeline remains the audit history.

5. **The next action uses existing endpoints.** The journey's CTAs call the existing status,
   qualification and conversion endpoints. No new lead endpoint is required.

6. **Criterion labels are human-readable.** The UI maps `estimated_headcount`, `needed_roles` and
   `contact` to labels such as “Estimated headcount”, “Needed roles” and “Contact channel”. The raw
   keys remain the API contract.

## Consequences

- The lead detail page gets a journey panel above the qualification checklist and a next-action CTA;
  the right-hand Lifecycle panel stays for the complete legal transition list, notes and archive.
- The `JourneyStepper` component is reused rather than duplicated; the account journey is unchanged.
- Frontend tests cover every status, branch state, missing-criteria message and CTA.
- No backend migration, new table or state-machine change is introduced.
- The lead timeline remains the chronological source for “what actually happened”; the journey is a
  navigation aid, not an audit log.
