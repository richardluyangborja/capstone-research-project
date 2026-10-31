# Shared components

Reusable components live here (`components/common/`) and in `components/layout/`. **Reuse before
creating** — anything used by 2+ subsystems/roles belongs here (design system rule, no duplicates).

Required shared set (from `resources/departamental-design-instruction.md`). Build only when needed;
check this list first:

- `DashboardShell`, `Sidebar`, `Topbar` — **done** (`../layout/`)
- `PublicLayout` — **done** (`../layout/`)
- `StatCard` — stat with domain color (counts=blue, people=green, activity=purple, cost=orange) — **done**
- `PageHeader` — **done**
- `StatusBadge` — token-mapped badge (status + rating tones) — **done**
- `AlertItem` — inline alert (info/success/warning/danger) — **done**
- `FormCard` — titled card with optional footer — **done**
- `EmptyState` — empty list placeholder — **done**
- `LeadOwnerControl` — claim / reassign a lead's owner — **done**
- `LeadAgingBadge` — stale / at-risk / archive-due flag from the API's computed aging — **done**
- `StageMoveControl` — accessible "Move to…" stage menu driven by the API's `allowed_transitions` (module 02) — **done**
- `LostReasonDialog` — reason prompt before a deal is marked lost (module 02) — **done**
- `CommunicationList` — one record's communication history + the log entry point (module 03, shared by the account tab and the lead/deal sections) — **done**
- `RescheduleDialog` — the due-date prompt on the follow-up worklist and task page (module 04) — **done**
- `ChartCard` — chart wrapper (title, description, action, footer) — **done**
- `LineChart` — token-only SVG line chart, multi-series, accessible — **done** (spec 14)
- `DonutChart` — token-only SVG donut with a clickable legend — **done** (spec 14)
- `DataTable` — list/tables with filters slot, pagination, bulk actions — **done**
- `ReportBody` — headline, narrative, three insight cards and four action links (module 06 dashboard
  summary + module 07 `/reports`) — **done** (spec 15)
- `JourneyStepper` — read-only, derived account journey (module 01 refresh, Account 360) — **done**
- `RateCardTable` — read-only contract rate-card table with manager-only pay/margin columns (module 01
  refresh, Account 360 + contract detail) — **done**
- `RateCardEditor` — replace-the-set rate-card form with site, notes and computed preview totals
  (module 01 refresh, Account 360 + contract detail) — **done**

Rules: tokens/utilities only (no hardcoded hex or arbitrary px); icons from `lucide-react`; render
dashboards inside `DashboardShell`.
