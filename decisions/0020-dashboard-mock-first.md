# 0020 — Dashboard first, mock-first, and the AI report contract

- Status: Accepted
- Date: 2026-10-20

## Context

ADR-0014 ordered the AI layer **last** — after module 05 — because it consumes what the five data
modules produce. Module 05 is at S3 (responses & scoring); S4 (closed-loop follow-through) is next
in that plan.

The client now needs a presentation-ready CRM dashboard **urgently**, including a mocked AI
management report, and is still deciding the AI technology. The dashboard is also the first place a
viewer judges whether this looks like a real CRM, so it cannot wait behind S4, and it cannot be a
page of zeros (`DashboardHome` currently renders eight empty `StatCard`s).

Two constraints shape the decision:

- The dashboard is **cross-cutting** (ADR-0014 §1) — it owns no entity and must not become a sixth
  data module.
- The real numbers require orchestration over five modules (role scoping, period rules, core2 HR
  data) and the AI service does not exist. Building both before the presentation would miss the
  deadline.

## Decision

1. **Ship the dashboard before module 05 S4.** S4 stays specified in `specs/13` and is deferred,
   not dropped. The dashboard reads the attention flag S2 already stores and links to
   `/satisfaction/responses?attention=true`, so S4 lands later without a dashboard rewrite.
   Delivery slices for this layer are declared in `specs/14`: **D1** dashboard home (this slice),
   **D2** real endpoints, **D3** the AI service.

2. **D1 is mock-first, behind one swap point.** Every dashboard number lives in
   `frontend/src/mocks/dashboard.ts`; the page imports only `getDashboard(role, range)` from
   `frontend/src/lib/dashboard.ts`. D2 replaces that function body with HTTP calls and deletes the
   fixtures; **no component changes**. Fixtures are deterministic (no faker/randomness), use the
   seeded demo names, and **never put a fixture id in a link** — every row links to an existing
   module page, so a mocked row cannot 404.

3. **Simulation is documented, not surfaced.** The repo (this ADR, spec 14 and the accessor
   docblock) records that D1 is mock data; the dashboard carries **no** simulation badge, banner or
   disclaimer. The UI is presentation-ready product UI. The fixtures are documented as presentation
   fiction, not seed-derived data, and D2 deletes the fixtures, not the documentation.

4. **One role-aware dashboard, not per-role pages.** The route, layout and components are shared;
   only the data scope changes (`personal` → salesrep, `team` → manager, `org` → admin/superadmin).
   This keeps the design system rule that all roles share `DashboardShell` and differ only by the
   `role` prop. Scoping is re-applied server-side in D2; the client is never the only gate.

5. **Charts are in-house SVG, added to `components/common/`.** No chart dependency is introduced:
   `ChartCard`, `LineChart` and `DonutChart` draw with design tokens, so the fixed domain colours
   hold in light and dark mode. They are reusable by later report screens.

6. **The AI report is a labelled contract with a fallback.** The panel consumes
   `{ headline, narrative, insights[], actions[], model, generated_at, source }`. D3's Python service
   answers; a ≤3-second timeout (or any failure) falls back to a rules-based template over the same
   numbers, and `source` drives the label. The model writes prose, never URLs: every insight/action
   target comes from a fixed route set, so generated text cannot invent a record.

7. **Staff performance joins CRM output with core2 (HR), through a gateway.** Attendance, leave and
   late arrivals are core2 / performance-management data, not CRM data. D2 reads them through an
   `HrGateway` mock (ADR-0005 pattern under `backend/app/Integrations/`), never a direct HR database
   query. Missing HR data renders `—`, never `0` (a zero would read as a real measurement). The
   table and the footnote say `core2 · HR`.

8. **Every dashboard element is clickable, and links only to an existing page.** A KPI, chart legend
   entry, worklist row, alert, activity row, staff action and AI action all navigate; the dashboard
   itself duplicates no action (no inline status changes). This is an acceptance criterion in
   `specs/14`, not a style preference.

9. **The data contract is the deliverable of D1.** `specs/14` §"Data contract" fixes the proposed
   endpoints, per-KPI query rules, worklist composition and HR seam. D2 is expected to be mechanical;
   if a rule changes, the spec changes first.

## Alternatives rejected

- **Build the real endpoints now and ship later.** The bottleneck is orchestration and scoping, not
  UI; the presentation date does not move. D2 keeps the work, with a contract already agreed.
- **Hardcode numbers inside the page.** Fast for one afternoon, expensive forever: no swap point, no
  documented source, tests pinned to JSX.
- **Adopt Recharts/Chart.js.** A new dependency for two charts, and its colours would bypass the
  design tokens. Hand-drawn SVG is ~150 lines and already reusable.
- **Per-role dashboards.** Violates the single `DashboardShell` rule and triples the UI to maintain.
- **Call a real model now.** The AI technology is undecided; a contract + labelled mock + fallback
  lets D3 replace one function without touching the page.
- **Treat the dashboard as a sixth module.** It owns no table and no rules; it is a read layer.

## Consequences

- `frontend/src/mocks/` is introduced as the single fixture home. This is a frontend-only folder; it
  is deleted/emptied in D2, so it must not accumulate unrelated fixtures.
- `DashboardHome` is rewritten; `StatCard` gains optional `to`, `icon`, `hint` and `trend` props
  (backwards compatible). The shared component list gains `ChartCard`, `LineChart`, `DonutChart`.
- Module 05's completion order changes: **S4 after D1** (and possibly after D2). `specs/13` marks S4
  as still specified; `AGENTS.md`, `specs/README.md`, `crm-flow.md` and ADR-0014 carry a note.
- D1 ships without backend changes; the backend suite is unaffected.
- D2 must add endpoint tests per role and reconcile at least one dashboard number against its
  module's list; D3 must prove the timeout fallback and the label.
