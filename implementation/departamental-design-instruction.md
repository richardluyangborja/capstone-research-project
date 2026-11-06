> Service Management and Enterprise Resource System (ISMERS) Design System — Official Announcement
>
> Team, we now have a formal design system in place to ensure UI consistency across all subsystems and roles. Please review this before building or modifying any page or component.
>
> Layer 1 — Design Tokens
>
> All colors, spacing, and typography are defined centrally in tailwind.config.js and index.css
> No hardcoded hex values or arbitrary pixel sizes — tokens/utility classes only
> Fixed color mapping per data domain (blue = counts, green = people, purple = activity, orange = cost)
> Note: this is the agreed reference design, pending final client sign-off — treat as current default
>
> Layer 2 — Shared Components
>
> Located in components/common/ and components/layout/
> Available: DashboardShell, Sidebar, Topbar, StatCard, ChartCard, PageHeader, DataTable, StatusBadge, AlertItem, FormCard, EmptyState
> Rule: any component used across 2+ subsystems or roles belongs in common/ — no duplicate implementations
>
> Layer 3 — Layout Standards
>
> Public site uses PublicLayout (no sidebar)
> User, Admin, and Superadmin dashboards all share a single DashboardShell, differentiated only by a role prop
> Separate layouts per role are not permitted
>
> Standard Dashboard Home Layout
>
> Row 1 — 4 primary StatCards
> Row 2 — 4 secondary StatCards
> Row 3 — Chart section (line + donut)
> Row 4 — Activity list + Alerts list
>
>      Before Building Any Page
>
> Check the shared component list first — reuse before creating new
> Use tokens exclusively — no inline styling
> Wrap all dashboard pages in DashboardShell
> Report any new shared components to the team for tracking
>
> Development Workflow
>
> All members are required to use Agents for coding tasks going forward, to keep implementation consistent with this design system
> When generating or editing UI code, make sure the design system context is provided so output stays aligned with our tokens and shared components
>
> Open Items for Future Iteration
>
> Responsive/mobile breakpoints
> Loading and error states (can be applied now)
> Accessibility standards (contrast, keyboard navigation)
> Form validation styling (can be applied now)
> Standardized icon library (can be applied now, use lucide)
> Decision on dark mode support (can be applied now, available on './design-tokens.md')
>
> Please familiarize yourselves with this before your next contribution. Questions or suggestions are welcome. @everyone
