# 10 — Opportunity Pipeline Visualization

Module 2 (ADR-0014). Entities: `Opportunity`, `Company`, `Contact`, `Activity`, `Contract`, `User`.
ADRs: 0002, 0004, 0007, 0012, 0015.

> **Implemented.** The contract below is what shipped (see ADR-0015 for the choices made where the
> spec was open). It builds on the deal record submodule 08 created (ADR-0012 §1). The full
> dashboard/management reports stay with the separate AI module; the deal pipeline does **not**
> trigger the Service handoff on `won` — that stays on `mark-signed` (ADR-0015 §4).

## Purpose

Turn the `Opportunity` record into a working deal pipeline: see every open deal as a board, move a
deal through the staffing stages, record won/lost, log the activity that drives it, and hand a won
deal to a contract — so reps work deals and managers can read the forecast.

## Pipeline stages

Stages are `OpportunityStage` (`new_inquiry, qualified, discovery, proposal_sent, negotiation, won,
lost, on_hold` — ADR-0002, `specs/00-data-model.md`). The board shows the **open** stages as columns
(`new_inquiry` … `negotiation`, `on_hold`); `won`/`lost` are terminal and are shown in a separate
**Closed** view, not as columns.

**Stage transitions — any-to-any with rules (ADR-0015 §1).** `OpportunityStage::allowedTransitions()`
is the single definition:

| From | May move to |
| --- | --- |
| any open stage | any **other** stage (open or terminal) |
| `won` / `lost` | any **open** stage (reopen only — never terminal → terminal) |
| any stage | not itself (422) |

Rules applied by `Opportunity::transitionTo(stage, ...)`:

1. `lost` requires a `lost_reason` (`LostReason`); it is cleared when the deal is reopened.
2. Entering `won` or `lost` stamps `actual_close_date` (defaults to today). Reopening clears it.
3. `probability` re-defaults from the new stage (`OpportunityStage::defaultProbability()`) unless the
   caller supplies one.
4. Every move stamps `stage_changed_at` and `last_activity_at` and records a `system` activity on the
   deal timeline (mirrors `LeadStatusChanged`, ADR-0009).
5. A move to `won` is a **record only** — it does **not** trigger the Service handoff (ADR-0015 §4);
   the UI offers **Create contract from deal**.

## Submodules & delivery slices

The five submodules are **facets of one increment**, not five shippable slices: the `Opportunity`
entity, its stages and its reference already existed (submodule 08, ADR-0012 §1), and three of the five
are behaviours of the same two screens.

1. **Pipeline Board** — the Kanban (`/pipeline`), drag-and-drop + accessible move menu, column totals.
2. **Deal Management** — flat `/opportunities` CRUD, the deal list and the deal detail page.
3. **Deal Stage & Outcome** — the stage endpoint, won/lost, reopen, the `stage_changed_at` trail.
4. **Deal Activity & Timeline** — log touches/next actions on a deal; the deal timeline.
5. **Pipeline Forecast** — board-header and list `meta` totals (weighted value, per-stage, win rate).

**Delivery slices (ADR-0016): one.** Module 02 shipped as a single end-to-end slice — board, deal book,
deal page, stage moves, timeline and forecast together. It *could* have been split into (S1) a read-only
board and deal book, then (S2) stage moves, won/lost and the timeline; that was rejected because the
stage rules were the point of ADR-0015 and a board nobody can act on does not demo. Recorded here rather
than left implicit, per ADR-0016 §4.

## API endpoints

Static paths precede the resource route (project convention, `routes/api.php`).

| Method | Path | Description |
| --- | --- | --- |
| `GET` | `/api/v1/opportunities` | Deal list — `filter[stage\|owner\|company\|open\|source\|close_window]` + `search`, paginated; `meta` summary |
| `POST` | `/api/v1/opportunities` | Create a deal; `company_id` required and visible; owner defaults to the creator |
| `GET` | `/api/v1/opportunities/{id}` | Deal detail (company, lead, owner, primary contact, linked contracts, timeline-ready) |
| `PATCH` | `/api/v1/opportunities/{id}` | Edit name/amount*/probability/expected close/`next_step`/primary contact/owner*/source (*manager+) |
| `DELETE` | `/api/v1/opportunities/{id}` | Soft delete |
| `POST` | `/api/v1/opportunities/{id}/restore` | Restore a deleted deal — manager+ |
| `POST` | `/api/v1/opportunities/{id}/stage` | Body `stage`, `lost_reason?`, `probability?`, `note?`; any-to-any with rules |
| `GET` | `/api/v1/opportunities/{id}/timeline` | Deal activities (timeline) |
| `POST` | `/api/v1/opportunities/{id}/activities` | Log an activity on the deal |
| `GET` | `/api/v1/pipeline` | Board — columns per open stage with their cards, per-column totals, forecast `meta` |

Won → contract reuses `POST /api/v1/companies/{company}/contracts` with `opportunity_id` (already
supported by `ContractInput`); no new endpoint. The company Opportunities tab
(`GET /companies/{id}/opportunities`) stays.

**List / board query:** `?filter[stage]=`, `?filter[owner]=self|team|{user_id}`, `?filter[company]=`,
`?filter[open]=true`, `?filter[source]=`, `?filter[close_window]=month|quarter|overdue`, `?search=`.

## Payloads

**OpportunityResource additions (on top of submodule 08):** `stage_changed_at`, `last_activity_at`,
`next_step`, `days_in_stage` (computed), `is_stale` (read-time flag, `crm.pipeline.stuck_days`,
default 21), `allowed_transitions[]`, `contracts` (linked contract references),
`can_update`, `can_change_stage`, `can_set_amount`, `can_assign`, `can_delete`, `can_restore`.

**Pipeline payload:**
`{ data: { columns: [{ stage, label, count, value, weighted_value, deals: Opportunity[] }] },
meta: { open_count, open_value, weighted_value, won_value, won_count, lost_value, lost_count,
win_rate, average_deal, by_stage: [{ stage, count, value, weighted_value }], stale_count } }`.

**Errors:** 422 — self stage move, terminal→terminal, `lost` without a reason, `company_id` outside
the caller's scope, `amount` set by a salesrep; 403 — deal outside scope (view/edit/delete), restore
or force delete without the right role; 404 — missing deal. Creates return 201; stage moves return
200 with the updated deal.

## Business rules

1. **Any-to-any with rules** — see the transitions table; enforced once in
   `OpportunityStage::allowedTransitions()` + `Opportunity::transitionTo()` and re-used by the request
   validation and the UI (`allowed_transitions`).
2. **Deals are scoped like their owner** (ADR-0004): salesrep own, manager team, admin all. A deal's
   owner may be changed by manager+ (and a salesrep may set only themselves), mirroring lead
   assignment; there is **no unassigned deal queue** (`owner_id` is required).
3. **Creating a deal does not need a lead.** `source` is copied from the lead when a deal comes from
   conversion, otherwise it is chosen directly.
4. **`amount` is manager+ to set** (ADR-0012 §4, ADR-0015 §5) but visible to whoever can see the deal;
   a salesrep-opened deal starts with an amount only if a manager sets one.
5. **`won` records the outcome** and exposes **Create contract from deal**; it never runs the Service
   handoff (that is `mark-signed`, ADR-0013 §5). Reopening a won/lost deal clears the close date and
   lost reason.
6. **Deletion is soft.** A deleted deal leaves the board/list and returns on restore (manager+). A
   deal does **not** block deleting its company once it is closed (`won`/`lost`); an open deal still
   blocks (ADR-0012 §4).
7. **Logging an activity refreshes `last_activity_at`**, so `is_stale` reflects real touches, not just
   stage moves.
8. **Forecasting is read-only** and computed on read; nothing is scheduled.

## Permissions

| Action | salesrep | manager | admin / superadmin |
| --- | --- | --- | --- |
| View deals / board / timeline | Own | Team | All |
| Create a deal (on a visible company) | ✓ (owner = self) | ✓ (any assignee) | ✓ |
| Edit a deal, move its stage | Own | Team | All |
| Set / change `amount` | — (read-only) | ✓ | ✓ |
| Reassign the owner | self only | ✓ | ✓ |
| Soft delete | Own | Team | All |
| Restore / force delete | — | restore | ✓ (force) |

## UI

- **Pipeline board** (`/pipeline`) — the sidebar entry for module 02. Columns for the open stages,
  cards (deal name, company link, amount in cost/orange, owner, expected close, probability, days in
  stage, a stale badge), a **Closed** toggle for `won`/`lost`, board filters (owner, company, close
  window, source, search) and a **forecast summary** header (open value, weighted value, win rate).
  Cards **drag between columns** and every card has a keyboard-accessible **Move to…** menu; a drop on
  `lost` opens a reason prompt, a drop on `won` reveals **Create contract from deal**.
- **Deal list** (`/opportunities`) — `DataTable` (reference, deal, company, stage badge + probability,
  amount, expected close, owner, next step) with the same filters and pagination.
- **New deal** (`/opportunities/new`) — `FormCard`: company (visible companies only), name, amount
  (manager+ only), probability, expected close, primary contact, source, `next_step`.
- **Deal detail** (`/opportunities/:id`) — `PageHeader` (reference, company link, stage badge, owner),
  an edit form, the **stage control** (move to any `allowed_transitions` stage, reopen included), the
  **deal timeline** with a log-activity form, the linked **contracts** (with **Create contract from
  deal** when won), and delete/restore actions by role.
- **Company Opportunities tab** (`/accounts/:id`) — becomes interactive: rows link to the deal, an
  **Add deal** button seeds `company_id`, and the *Engagements* summary links to `/pipeline`.
- Stage colors reuse the design tokens/`StatusBadge`; money uses the cost/orange domain color; no
  hardcoded hex (design system).

## Events

- `OpportunityStageChanged` (deal, from, to, actor, note) — handled by a listener that writes the
  `system` activity (timeline).
- `OpportunityWon` / `OpportunityLost` (deal, actor) — dispatched from the stage move as the
  reporting seam (no listener yet).
- `OpportunityCreated` (submodule 08) still fires on conversion and now on manual creation.

## Demo data

A `DemoOpportunitySeeder` (deterministic, no faker) seeds deals across **every** stage for the seeded
accounts — e.g. Harbor Logistics with a `negotiation` and a `won` deal, Sunrise Foods with
`proposal_sent`/`discovery`, BPO Solutions PH with a `qualified` deal, Greenfield Hotels with a `lost`
deal — owned by Sam/Liza/Maria, with amounts, probabilities and close dates, so the board, the
forecast header and the win-rate all demo without faking.

## Acceptance criteria

- [x] A deal moves to any other stage; a self-move and terminal→terminal are 422; `lost` without a
      reason is 422; `won`/`lost` stamp `actual_close_date`; reopening clears it and the lost reason.
- [x] Every stage move stamps `stage_changed_at` and appears on the deal timeline as a `system`
      activity; `last_activity_at` refreshes on a logged activity.
- [x] `GET /pipeline` returns the open stages as columns with per-column count/value/weighted value
      and a forecast `meta`; `won`/`lost` are excluded from the columns.
- [x] A salesrep sees only their deals (board, list, detail, timeline); a manager sees the team; an
      admin sees all — a deal outside scope is 403.
- [x] A salesrep **cannot** set `amount` (422) but can edit the rest of their deal and move its stage;
      `can_set_amount` is false on the payload.
- [x] A deal can be created directly on a visible company (owner = creator) and the company
      Opportunities tab links to it; conversion still opens a `qualified` deal.
- [x] Marking a deal `won` does **not** hand off to Service (no handoff runs); the "Create contract
      from deal" action opens the contract upload prefilled with `opportunity_id`.
- [x] Soft-deleting a deal removes it from the board and list; restore (manager+) brings it back; a
      salesrep cannot restore.
- [x] The board drag path and the Move-to menu produce identical results.
- [x] A `signed` contract still blocks deleting the company; a `won` deal does not.

## Open questions

- Stuck-deal threshold name/value — proposed `crm.pipeline.stuck_days` (default 21), read-time only;
  automatic stale/at-risk effects stay with the follow-up module.
- Should the board cap the cards per column (e.g. 50) with a "load more"? — proposed yes, `per_page`
  honoured; revisit if demo volume grows.
