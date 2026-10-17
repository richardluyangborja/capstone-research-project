# 0015 — Opportunity pipeline conventions

- Status: Accepted
- Date: 2026-10-17

## Context

Module 02 (`../resources/specs/10-opportunity-pipeline-visualization.md`, ADR-0014) builds the deal
pipeline that submodule 08 deliberately left out: submodule 08 created the `Opportunity` record,
stages and probability, but a salesrep still cannot move a deal, and `OpportunityPolicy` only polices
viewing (ADR-0012 §1). Building the pipeline raised six questions:

1. How fluid is the stage machine — guided transitions like leads/contracts, or any-to-any?
2. How does a rep move a card on the board (drag, control, both)?
3. Are deals still conversion-only, or can a rep open one directly?
4. What does `won` *do*? ADR-0002 says the handoff, but submodule 09 moved the handoff to signing.
5. Who may edit a deal, set its value, or delete it?
6. How much forecasting ships here versus the AI/reports module?

## Decision

1. **Stage moves are any-to-any with rules.** `OpportunityStage::allowedTransitions()` is the single
   definition: a deal may move to **any other** stage; a **terminal** stage (`won`, `lost`) may only be
   **reopened to an open stage** (never terminal→terminal). Rules: `lost` requires a `lost_reason`,
   `won`/`lost` stamp `actual_close_date`, reopening clears `actual_close_date` and `lost_reason`, and
   `probability` re-defaults from the new stage unless the caller overrides it. Every move stamps
   `stage_changed_at` and writes a `system` activity — the same pattern as `LeadStatusChanged`.
2. **The board is drag-and-drop *and* keyboard-accessible.** Cards drag between columns with the
   native HTML5 drag events (no new dependency), and every card carries a **Move to…** menu listing
   `allowed_transitions` for keyboard/screen-reader users. Both paths call the one stage endpoint.
3. **Deals can be created and edited directly, not only through conversion.** Flat `/opportunities`
   routes (index/store/show/update/destroy + restore) join the conversion path; `company_id` must be a
   company the caller can see (422 otherwise) and the owner defaults to the creator. Conversion
   (submodule 08) stays a valid entry point — it is simply no longer the **only** one.
4. **`won` prompts the contract; signing stays the Service handoff.** This supersedes the literal
   ADR-0002 consequence "Won triggers the service-department handoff": marking a deal `won` records
   the outcome and surfaces a **Create contract from deal** action that reuses the contract upload
   endpoint with `opportunity_id`; the mocked Service handoff still fires **only** on
   `mark-signed` (ADR-0013 §5). `OpportunityWon` / `OpportunityLost` events are dispatched as the
   reporting seam (no listener yet).
5. **Permissions extend ADR-0004 / ADR-0012.** View, edit and stage-move a deal: own / team / all.
   Setting `amount` is **manager+** (a salesrep sees it but cannot change it — `can_set_amount`), so
   the pipeline does not silently weaken ADR-0012 §4. Delete is a **soft** delete for own/team/all,
   restore is **manager+**, force delete is admin.
6. **Forecasting is a board header, not a report.** The board and `GET /opportunities` expose
   `meta` totals (open/weighted value, per-stage breakdown, win rate); the full dashboard and
   management reports remain the AI module (`system-ai-feature.md`).

## Consequences

- `opportunities` gains additive columns `stage_changed_at`, `last_activity_at`, `next_step` (a plain
  free-text next step — scheduled tasks remain the follow-up module) and indexes on `owner_id`,
  `expected_close_date`. No new tables: the deal timeline reuses the `activities` morph.
- `OpportunityStage` gains `allowedTransitions()`; `OpportunityPolicy` gains `update`, `changeStage`,
  `setAmount`, `delete`, `restore`, `forceDelete`. `OpportunityResource` gains `stage_changed_at`,
  `last_activity_at`, `next_step`, `days_in_stage`, `is_stale`, `allowed_transitions[]`,
  `can_*` flags and the linked-contract reference.
- `resources/specs/README.md` moves `OpportunityWon` from "not built" to module 02.
- The deal detail page and the pipeline board are the two contracts 08 promised; the company
  Opportunities tab becomes interactive.
