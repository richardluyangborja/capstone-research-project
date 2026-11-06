# 18 — Module 02 refresh — Opportunity Pipeline Visualization

Refresh of Module 02 (Opportunity Pipeline Visualization). Extends `00-data-model.md`,
`08-lead-to-client-conversion.md` and `10-opportunity-pipeline-visualization.md`.
ADRs: **0029** (pipeline refresh & staffing domain alignment), **0015** (pipeline conventions),
**0027** (contracts are read-only), and **0018** (follow-up reminders).

> **Status: S1 omitted; S2–S5 planned.** The dedicated drag-handle slice was reverted to the baseline
> whole-card drag-and-drop after review. This spec declares four remaining reviewable delivery slices
> (S2–S5). It elevates the deal pipeline to mirror real-world manpower and staffing agency workflows,
> captures staffing headcount demand, enforces proper Won/Lost outcome governance, synchronizes follow-up
> tasks, and connects cross-department seams.

---

## Purpose

Module 02 established the core Kanban board and deal records, but operational use identified key areas
for improvement:

1. **Drag-and-drop affordance (S1, omitted):** Deal cards lacked an explicit drag handle icon. The
   proposed handle slice was reverted after review; the baseline whole-card drag remains in place.
2. **Staffing domain alignment:** Opportunities tracked generic financial lump sums without requested
   headcount, staffing service placement types, or target start dates. When leads converted, their
   headcount demand was lost.
3. **Stage governance & ADR-0027 alignment:** Moving to `Won` was silent and displayed an outdated prompt
   (“Create its contract to activate the client”) that contradicted ADR-0027. Moving to `Lost` lacked a notes
   field.
4. **Follow-up tracking gap:** Deals lacked follow-up synchronization with Module 04, leaving reps without
   visibility into overdue touches or unscheduled deals.
5. **Cross-department visibility & ergonomics:** Won deals did not surface linked contract projections, and
   users lacked a unified view switcher (Board vs List) and visual funnel analytics.

The remaining refresh resolves these issues through four incremental, reviewable slices (S2–S5). S1
(drag handle interactivity) is omitted.

---

## Scope

- **In:**
  - Baseline whole-card drag-and-drop and the keyboard-accessible Move-to menu, unchanged.
  - Staffing demand fields on `Opportunity`: `estimated_headcount`, `service_type`, `target_start_date`, and `win_note`.
  - Lead-to-Client conversion mapping preserving staffing demand.
  - Celebratory `WonConfirmDialog` confirming close date, won value, win notes, and external contract/recruitment context.
  - Enhanced `LostReasonDialog` capturing notes and competitor details.
  - Retirement of outdated contract creation alerts on `/pipeline` (aligning with ADR-0027).
  - Synchronized `next_follow_up_at` on `Opportunity` (integrated with Module 04 task lifecycle).
  - Follow-up status indicators and unscheduled follow-up warnings on Kanban cards.
  - Eager-loaded contract projection badges (`CON-YYYY-NNNN · Signed`) on won deals.
  - Read-only staffing demand / Recruitment Job Orders (`RecruitmentFeed`) preview on deal detail.
  - Unified View Switcher on `/pipeline` (Kanban Board, Deal Table List, Funnel Overview) with persistent filters.
  - Visual Stage Funnel Progress Bar showing stage volume and value distribution.
  - Quick Deal Peek Drawer for rapid deal review without leaving the board.
- **Out:**
  - A dedicated drag handle, custom drag ghosting or custom drop-target styling (S1 omitted; the baseline
    whole-card drag remains).
  - Automatic stage movement based on external feeds.
  - Re-creating Recruitment or Contract Management operational CRUD inside CRM.
  - Breaking changes to existing stage transitions (`OpportunityStage::allowedTransitions()` remains unchanged).
  - External ML forecasting (handled by Module 07 / AI Management Reports).

---

## Decisions in Force

| ADR | Decision |
| --- | --- |
| **0015** | Stage moves are any-to-any with rules; board has drag-and-drop plus accessible Move-to menu; scoping by owner/team/all |
| **0027** | Contracts are read-only projections owned by Core Transaction 3; won deals link to projections and never activate accounts or upload contracts |
| **0018** | Follow-up tasks are Activities with `type = task` and `due_at`; subjects keep `next_follow_up_at` synchronized |
| **0029** | Opportunity pipeline refresh: staffing demand fields, won/lost governance, follow-up sync and view switcher (S1 drag handle omitted) |

---

## Delivery Slices

| Slice | Name | Focus | Key Deliverables |
| --- | --- | --- | --- |
| **S1** | ~~Drag & Drop Interactivity~~ | ~~Frontend UX~~ | **Omitted** — reverted to the baseline whole-card drag; no dedicated handle or custom drop styling |
| **S2** | **Staffing Domain Scope** | Data Model & Conversion | `estimated_headcount`, `service_type`, `target_start_date` on `opportunities`, `LeadConversionController` mapping, form inputs & card badges |
| **S3** | **Stage Governance & Outcomes** | Outcomes & ADR-0027 | `WonConfirmDialog`, enhanced `LostReasonDialog` with notes, contract projection badge on won cards, retirement of outdated contract prompt |
| **S4** | **Follow-up Integration** | Module 04 Integration | `next_follow_up_at` column, `Opportunity::syncNextFollowUpAt()`, follow-up status badges & unscheduled warnings on cards |
| **S5** | **Workspace Ergonomics & Analytics** | Navigation & Seams | Unified View Switcher (Board/List), visual Stage Funnel Bar, Quick Deal Peek Drawer, Recruitment Job Orders context |

---

## Detailed Slice Specifications

### Slice S1 — Omitted

The dedicated `GripVertical` handle and custom drag feedback were reverted to the baseline whole-card
drag-and-drop after review. `Pipeline.tsx` and `pipeline.test.tsx` are restored to their pre-S1 state;
no S1 acceptance criteria are in force. The baseline board still drags between columns and keeps the
keyboard-accessible Move-to menu.

---

### Slice S2 — Staffing Domain Scope & Lead Conversion Continuity

**Branch:** `feature/module-02-staffing-fields`

#### Data Model
Add additive columns to `opportunities`:

| Field | Type | Nullable | Notes |
| --- | --- | --- | --- |
| `estimated_headcount` | unsignedInteger | Yes | Requested workforce count (e.g. 25 workers) |
| `service_type` | string | Yes | Enum: `temporary_staffing`, `contract_to_hire`, `direct_hire`, `project_based` |
| `target_start_date` | date | Yes | Target date for worker deployment |
| `win_note` | text | Yes | Captured outcome details and deployment requirements |

#### Backend Changes
1. **Lead Conversion:** In `LeadConversionController::createOpportunity()`, copy staffing fields from `Lead`:
   ```php
   'estimated_headcount' => $attributes['estimated_headcount'] ?? $lead->estimated_headcount,
   'target_start_date' => $attributes['target_start_date'] ?? $lead->target_start_date,
   'service_type' => $attributes['service_type'] ?? ($lead->service_interest ?? 'temporary_staffing'),
   ```
2. **Validation & Requests:** Update `StoreOpportunityRequest` and `UpdateOpportunityRequest` to validate:
   - `estimated_headcount`: `nullable|integer|min:1|max:10000`
   - `service_type`: `nullable|string|in:temporary_staffing,contract_to_hire,direct_hire,project_based`
   - `target_start_date`: `nullable|date`
3. **Resources:** Expose the new fields in `OpportunityResource`.

#### Frontend Changes
1. Types in `frontend/src/types/index.ts` and API helpers in `frontend/src/lib/opportunities.ts`.
2. Display staffing badges on `DealCard` (e.g. `25 workers · Temporary Staffing`) and columns in `Opportunities.tsx`.
3. Add inputs for staffing fields in `NewOpportunity.tsx` and `OpportunityDetail.tsx`.

#### Acceptance Criteria
- [ ] Migrations add `estimated_headcount`, `service_type`, `target_start_date`, and `win_note`.
- [ ] Converting a lead with headcount and target date transfers them to the created opportunity.
- [ ] Deal cards and the deal table display staffing headcount and service placement badges.
- [ ] Store and update endpoints validate and persist staffing fields.
- [ ] Backend and frontend tests pass.

---

### Slice S3 — Stage Governance, Won Milestone & ADR-0027 Alignment

**Branch:** `feature/module-02-won-lost-governance`

#### Implementation
1. **`WonConfirmDialog` Component:**
   - Triggered when dragging or moving a deal into `Won`.
   - Confirms actual close date (default: today).
   - Allows manager+ to confirm/adjust final won amount (`can_set_amount`).
   - Captures optional win notes (`win_note` or `note`).
   - Explains next steps: Core Transaction 3 (Legal & Compliance) prepares the contract; Recruitment (Core Transaction 2) receives the staffing demand.
2. **Enhanced `LostReasonDialog`:**
   - Adds an optional notes textarea for competitor details or client feedback (passed as `note` to `Opportunity::transitionTo`).
3. **ADR-0027 Contract Alignment:**
   - Remove the outdated alert on `/pipeline`: *“Create its contract to activate the client”*.
   - Replace with:
     - When linked contract projection exists: *““{deal.name}” is won. Linked contract: CON-YYYY-NNNN (Signed).”* with a direct link to `/contracts/:id`.
     - When no contract exists yet: *““{deal.name}” is won. Contracts are managed in Contract Management (Core Transaction 3).”*
4. **Contract Badge on Cards:**
   - Eager load `contracts` in `PipelineController::index` and display a compact contract status badge on won cards in the Closed column.

#### Acceptance Criteria
- [ ] Moving to `Won` triggers `WonConfirmDialog` and captures close details.
- [ ] Moving to `Lost` captures reason and optional notes.
- [ ] Outdated contract creation alert is completely removed from `/pipeline`.
- [ ] Won deals link to the read-only contract projection when available.
- [ ] Regression tests verify ADR-0027 contract read-only compliance.

---

### Slice S4 — Cross-Module Follow-up Integration (Module 04)

**Branch:** `feature/module-02-followup-sync`

#### Implementation
1. **Database & Model:**
   - Add `next_follow_up_at` (`timestamp, nullable, indexed`) to `opportunities`.
   - Implement `Opportunity::syncNextFollowUpAt()`:
     ```php
     public function syncNextFollowUpAt(): void
     {
         $next = $this->activities()
             ->where('type', ActivityType::Task)
             ->where('status', ActivityStatus::Open)
             ->whereNotNull('due_at')
             ->orderBy('due_at')
             ->value('due_at');

         $this->forceFill(['next_follow_up_at' => $next])->save();
     }
     ```
2. **Subject Sync Hook:**
   - In `backend/app/Models/Concerns/HasSubjectRecord.php`:
     ```php
     if ($subject instanceof Opportunity) {
         $subject->syncNextFollowUpAt();
     }
     ```
3. **Resource & Payload:**
   - Expose `next_follow_up_at` and `next_follow_up` (`FollowUpDueState::block(...)`) in `OpportunityResource`.
4. **Kanban Card & Table Indicators:**
   - Render `next_follow_up` badge (`Overdue`, `Today`, `Upcoming`) on `DealCard`.
   - Show a subtle warning badge (`No follow-up scheduled`) for open deals in active stages (`Proposal Sent`, `Negotiation`) without an upcoming task.

#### Acceptance Criteria
- [ ] Creating, updating, completing, or cancelling a task for an opportunity updates `next_follow_up_at`.
- [ ] `OpportunityResource` carries `next_follow_up_at` and computed `next_follow_up` state.
- [ ] Deal cards display follow-up badges reflecting real task due dates.
- [ ] Unscheduled active deals display an alert indicator.
- [ ] Feature tests verify follow-up synchronization.

---

### Slice S5 — Workspace Ergonomics, Analytics & Department Seams

**Branch:** `feature/module-02-workspace-ergonomics`

#### Implementation
1. **Unified View Switcher on `/pipeline`:**
   - Add segmented control in the page header:
     `[ Board (Kanban) ]` `[ Table (List) ]` `[ Funnel Overview ]`
   - Preserves active search, owner, and close window filters across view changes.
2. **Visual Stage Funnel Progress Bar:**
   - Render horizontal funnel progress bar above board columns displaying relative count and value across open stages.
3. **Quick Deal Peek Drawer:**
   - Clicking a card opens an accessible side drawer showing deal summary, staffing demand, next step, upcoming follow-up, and recent timeline activity without losing board position.
4. **Recruitment Seam on `OpportunityDetail`:**
   - Display a read-only **Staffing & Recruitment Context** card consuming the account's active Job Orders from `RecruitmentFeed` (`GET /companies/{id}/delivery`), bridging pipeline demand with operational fulfillment.

#### Acceptance Criteria
- [ ] View switcher seamlessly toggles between Kanban, Table, and Funnel views while maintaining filters.
- [ ] Funnel bar accurately reflects stage value and count distributions.
- [ ] Side peek drawer displays deal details and timeline without full page reloads.
- [ ] Opportunity detail renders read-only recruitment context when available.
- [ ] All automated tests pass; lint and formatting verified.

---

## Documentation and Bookkeeping

When this refresh lands, update:
- `../AGENTS.md` — summarize Module 02 refresh capabilities and reference spec 18 & ADR-0029.
- `../implementation/crm-flow.md` — update Opportunity object model and pipeline flow.
- `00-data-model.md` — record additive opportunity columns.
- `10-opportunity-pipeline-visualization.md` — add banner referencing spec 18.
- `README.md` — index spec 18.
