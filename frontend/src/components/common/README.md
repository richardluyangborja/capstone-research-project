# Shared components

Reusable components live here (`components/common/`) and in `components/layout/`. **Reuse before
creating** — anything used by 2+ subsystems/roles belongs here (design system rule, no duplicates).

Required shared set (from `resources/departamental-design-instruction.md`). Build only when needed;
check this list first:

- `DashboardShell`, `Sidebar`, `Topbar` — **done** (`../layout/`)
- `PublicLayout` — **done** (`../layout/`)
- `StatCard` — stat with domain color (counts=blue, people=green, activity=purple, cost=orange)
- `ChartCard` — line + donut chart wrapper
- `PageHeader`
- `DataTable` — list/tables with filters, pagination, bulk actions
- `StatusBadge`
- `AlertItem`
- `FormCard`
- `EmptyState`

Rules: tokens/utilities only (no hardcoded hex or arbitrary px); icons from `lucide-react`; render
dashboards inside `DashboardShell`.
