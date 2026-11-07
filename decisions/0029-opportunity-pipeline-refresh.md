# 0029 — Opportunity pipeline refresh and staffing domain alignment

- Status: Accepted
- Date: 2026-11-05

Extends `0015-opportunity-pipeline-conventions.md`, `0027-contracts-read-only.md`, and
`0018-follow-up-reminder-model.md`. Implemented by `../specs/18-module-02-refresh.md`.

## Context

Module 02 (`../specs/10-opportunity-pipeline-visualization.md`, ADR-0015) delivered the baseline
deal pipeline: an open-stage Kanban board (`/pipeline`), the flat deal book (`/opportunities`), any-to-any
stage moves with rules, deal timeline touches, and forecast summaries.

However, real-world operation and comparison with Module 01's workspace refresh revealed five gaps:

1. **Missing staffing domain context:** Primepower Manpower Service deploys rank-and-file workers. In real
   staffing, a deal is defined by requested headcount, role types, service placement type, and target start
   date. While `Lead` tracks `estimated_headcount` and `target_start_date`, `LeadConversionController`
   previously discarded these fields when creating an `Opportunity`.
2. **Stage governance and milestone capture:** Moving to `Won` currently moves the stage silently and displays
   an outdated alert (`Create its contract to activate the client`) that directly violates ADR-0027 (contracts
   are read-only projections owned by Core Transaction 3). Moving to `Lost` lacks a notes field for competitor
   or client feedback even though the backend supports `$note`.
3. **Follow-up reminder disconnect:** Module 04 introduced scheduled follow-ups (`Activity` with `type = task`),
   keeping `next_follow_up_at` synchronized on `Lead` and `Company`. Deals on the pipeline board have no
   visible follow-up indicators, making it hard for reps and managers to spot neglected or unscheduled deals.
4. **Cross-department visibility:** Won deals did not eager-load or display linked contract projection badges
   (`CON-YYYY-NNNN · Signed`), and deal detail lacked read-only operational hiring demand from Recruitment
   (`RecruitmentFeed`, ADR-0025).
5. **Workspace ergonomics:** The Kanban board and table list are separate destinations with disconnected filter
   state, lacking an integrated view switcher, a visual funnel distribution bar, or a quick-peek drawer.

## Decision

1. **Ground opportunities in the staffing domain.** Add additive columns to `opportunities`:
   `estimated_headcount` (unsigned integer), `service_type` (string enum: `temporary_staffing`,
   `contract_to_hire`, `direct_hire`, `project_based`), `target_start_date` (date), and `win_note` (text).
   `LeadConversionController` automatically maps `estimated_headcount`, `target_start_date`, and service
   intent from `Lead` to the created `Opportunity`.
2. **Win Milestone Dialog and outcome governance.**
   - Dropping on or moving to `Won` opens a `WonConfirmDialog` confirming the actual close date, won value
     (manager+), and optional win notes. It presents clear context: Core Transaction 3 (Legal & Compliance)
     handles contract execution, and Recruitment prepares candidate sourcing.
   - The outdated contract activation message is replaced with links to the read-only contract projection or
     the external contract tracking notice per ADR-0027.
   - `LostReasonDialog` gains an optional notes textarea for competitor feedback and client reasons.
3. **Synchronize follow-up state (Module 04 integration).** Add `next_follow_up_at` to `opportunities`
   and implement `Opportunity::syncNextFollowUpAt()` triggered by task changes in `HasSubjectRecord`.
   `OpportunityResource` exposes `next_follow_up_at` and `next_follow_up` (`FollowUpDueState`), rendering
   upcoming/overdue badges or unscheduled warnings on Kanban cards.
4. **Cross-department seams.** Eager load `contracts` on the pipeline board to surface the linked contract
   reference and status badge on won cards. Display read-only recruitment job orders from `RecruitmentFeed`
   on the deal detail page.
5. **Workspace visualization.** Provide a unified View Switcher on `/pipeline` (Board vs Table vs Funnel) with
   synchronized filters, a visual stage funnel progress bar, and an accessible side peek drawer for rapid
   deal inspection without losing position on the Kanban board.

## Consequences

- S2 and S3 are delivered; S4–S5 remain planned in `../specs/18-module-02-refresh.md`.
- All existing API endpoints remain backward-compatible; new fields are strictly additive.
- Preserves the ADR-0015 any-to-any stage transition rules and ADR-0004 role-based scoping (salesrep own,
  manager team, admin all).
- Resolves the ADR-0027 tracking contradiction on the pipeline board.
