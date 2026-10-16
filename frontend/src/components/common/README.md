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
- `ChartCard` — line + donut chart wrapper — pending
- `DataTable` — list/tables with filters, pagination, bulk actions — pending

Rules: tokens/utilities only (no hardcoded hex or arbitrary px); icons from `lucide-react`; render
dashboards inside `DashboardShell`.
