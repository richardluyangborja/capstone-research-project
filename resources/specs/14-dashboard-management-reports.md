# 14 — Dashboard & Management Reports

Cross-cutting layer over modules 01–05 (ADR-0014, **ADR-0020**). Reuses `Lead`, `Company`,
`Opportunity`, `Contract`, `Activity`, `Communication`, `SurveyResponse` and `User`; adds **no
table**. The AI service (`resources/system-ai-feature.md`) is a Python service that this spec
treats as an HTTP contract with a documented fallback.

> **Status: D1 built with simulated data.** The dashboard home ships first, for presentation, with
> all numbers read from one fixture module. D2 (real endpoints) and D3 (the `ai/` service) are
> declared but not built. Module 05's S4 (closed-loop follow-through) is deliberately deferred
> behind this slice (ADR-0020 §1).

## Purpose

Give every role a home that reads like a real CRM: the numbers a manager asks about on a Monday
morning, the shape of the pipeline, the handful of things that need a human this week, and how the
team itself is performing. It is also the visible home of the **AI management report** — a written
summary and suggested actions over the same data.

The layer is **read-only and cross-cutting**: it owns no entity, changes no module's rules, and
every element links back to the page that does own the work. A dashboard number that cannot be
opened is a dead end; this spec forbids dead ends.

## Delivery slices

| Slice | Branch | Facet | Demo |
| --- | --- | --- | --- |
| **D1** *(built)* | `feature/dashboard-management-reports` | Dashboard home, simulated | log in as each role → KPIs, charts, needs-attention table, staff scorecard, AI management report; switch the period |
| **D2** | `feature/dashboard-data` | Real endpoints + wiring | the same page reads `/api/v1/dashboard`; every fixture field is replaced by a query; empty/loading/error states |
| **D3** | `feature/ai-reports` | The Python service | the AI panel calls the service; a timeout falls back to the rules template; `source` records which path answered |

D2 is purely additive: the components already consume the final shape. D3 changes only the
`ai` block.

## Layout (D1)

The design system requires: 4 primary stat cards → 4 secondary stat cards → chart section (line +
donut) → activity list + alerts list, inside `DashboardShell` (design instruction, "Standard
Dashboard Home Layout"). The dashboard keeps that spine and adds the two blocks the presentation
needs (the worklist and the staff scorecard):

1. **Header** — greeting, scope line (`Org-wide` / team / `Your book`), last-updated line, period
   toggle (`Last 30 days` / `This quarter` / `Year to date`).
2. **KPI row 1 (primary)** — New leads · Qualified leads · Open pipeline · Revenue won.
3. **KPI row 2 (secondary)** — Win rate · Active clients · Follow-ups due · Needs attention.
4. **Charts** — `Acquisition & conversion` (line: leads / qualified / won) and `Open pipeline`
   (donut by stage). Both carry a caption and a link to their module page.
5. **AI management report** — headline, narrative, three insight cards, four suggested actions;
   every item links to a page. Presented as the product's AI report — the simulation is recorded
   here and in ADR-0020, never in the UI.
6. **Needs attention + right rail** — a worklist table (priority, subject, type, owner, action) and
   two cards: **Alerts** (`AlertItem`s with links) and **Recent activity** (linked rows).
7. **Staff performance** — CRM output beside core2 HR figures (attendance, leave, late arrivals),
   with a `core2 · HR` badge and the seam explained in the footnote.

Every card is a link. No card is decorative.

## Role awareness

One layout, one route (`/dashboard`); the **content** is scoped by the session role (`ADR-0004`).
This satisfies the design rule that all roles share `DashboardShell` and differ only by a `role`
prop — no per-role layouts.

| Session role | Scope (`scope.tier`) | Sees |
| --- | --- | --- |
| `salesrep` | `personal` — "Your book" | their KPIs, their worklist rows, their scorecard row |
| `manager` | `team` — "Business Development team" | the team's KPIs, team + unassigned rows, all team scorecards |
| `admin`, `superadmin` | `org` — "Primepower · CRM org-wide" | every KPI, every worklist row, every scorecard |

Worklist rows, alerts and staff rows carry a `minTier` (`personal` < `team` < `org`). A row is shown
when the session tier is at or above it. In D2 the server applies the same filter — the client must
never be the only gate.

## Mock data (D1 policy)

1. **One source.** `frontend/src/mocks/dashboard.ts` builds every block; `getDashboard(role,
   range)` in `frontend/src/lib/dashboard.ts` is the only accessor the page imports. D2 replaces the
   accessor body with HTTP calls and deletes the fixture file. No component knows the difference.
2. **Documented, not surfaced.** The repo (this spec, ADR-0020 and the accessor docblock) records
   that D1 is mock data; the page carries **no** simulation badge, banner or disclaimer. The UI is
   presentation-ready product UI. D2 removes the fixtures, not the documentation.
3. **No fixture ids in links.** A fixture row links to a module page or a filtered book
   (`/pipeline`, `/satisfaction/responses?attention=true`), never to `/companies/{fixture-id}` —
   so a mocked row can never 404. D2's real payload sends record hrefs.
4. **Deterministic.** No faker and no randomness: the same role + range always renders the same
   numbers, so the demo and the tests are reproducible.
5. **Real names, fictional numbers.** Accounts and staff are the seeded demo records (Sunrise, BPO,
   Harbor, Greenfield; Maria, Sam, Liza); the figures are invented for the presentation and are
   **not** derived from the seeders.

### Fixture content (D1)

| Block | Org, last 30 days | Source of the fiction |
| --- | --- | --- |
| New leads / qualified | 128 / 74 | modules 01–02 |
| Open pipeline / revenue won | ₱8.64M / ₱2.72M | modules 02, 09 |
| Win rate / active clients | 38% / 27 | modules 02, 09 |
| Follow-ups due / needs attention | 19 / 8 | modules 04, 05 |
| Line series | 5 weekly points | modules 01–02 |
| Donut | 4 open stages | module 02 |
| Worklist | 8 rows | modules 01–05 |
| Staff | 3 rows (Maria, Sam, Liza) | modules 01–05 + core2 |

A salesrep's figures are the personal slice (18 leads / ₱1.45M open / 2 attention); a manager's the
team slice (54 / ₱4.12M / 5). `This quarter` and `Year to date` scale deterministically. Attendance,
leave and late arrivals do **not** scale with the period except for leave days (they are point-in-
time HR reads).

## Data contract (D2)

All money is PHP; dates display `DD MMM YYYY` in `Asia/Manila` (store UTC). Lists are scoped
server-side. The dashboard sends **raw values + units**; the frontend formats (one formatting rule,
`lib/format.ts`). D1 fixtures pre-format because they are static strings — the D2 adapter formats,
the components do not change.

### `GET /api/v1/dashboard?range=30d|quarter|ytd`

```jsonc
{
  "data": {
    "range": "30d",
    "updated_at": "2026-10-20T01:00:00Z",
    "scope": { "tier": "team", "label": "Business Development team", "description": "…" },
    "primary": [{
      "id": "new-leads", "label": "New leads", "value": 54, "unit": "count",
      "trend": { "direction": "up", "value": 18, "unit": "percent", "tone": "positive" },
      "hint": "Captured in the period", "href": "/leads"
    }],
    "secondary": [ /* same shape */ ],
    "line": {
      "caption": "Weekly, 22 Sep – 20 Oct",
      "labels": ["22 Sep", "…"],
      "series": [{ "name": "New leads", "domain": "counts", "values": [20, 28, 26, 29, 25] }]
    },
    "donut": {
      "caption": "Open pipeline by stage", "total": 4120000,
      "segments": [{ "label": "Negotiation", "value": 1150000, "href": "/pipeline" }]
    }
  }
}
```

Query rules (must match the fixtures' story):

- **New leads** — `leads.created_at` in the period, visible to the caller, `status != archived`.
- **Qualified** — leads that entered `qualified` in the period (`status_changed_at`).
- **Open pipeline** — sum of `opportunities.amount` on open stages, visible; snapshot, not period.
- **Revenue won** — `amount` of deals whose `actual_close_date` falls in the period.
- **Win rate** — won ÷ (won + lost) closed in the period.
- **Active clients** — `companies.status` past `prospect`, not `churned`.
- **Follow-ups due** — open tasks + scheduled messages falling due in the period
  (`Activity` + `Communication`, module 04).
- **Needs attention** — unresolved attention-flagged responses + accounts at risk (module 05 S4,
  module 01 aging).

### `GET /api/v1/dashboard/worklist?range=…`

Rows, newest severity first:

```jsonc
{
  "data": [{
    "id": "…", "kind": "survey|company|opportunity|lead|follow_up|contract",
    "subject": "Sunrise Foods Inc", "detail": "CSAT 2 · NPS 4 on the post-deployment check-in",
    "owner": { "id": 2, "name": "Sam Salesrep" },
    "due": { "label": "19 Oct", "severity": "danger" },
    "href": "/companies/01J…?tab=satisfaction", "action_label": "Read answer"
  }],
  "meta": { "total": 8 }
}
```

Composition (one query per source, merged and sorted): unhappy/unresolved survey responses,
scheduled messages and tasks past due, accounts at risk or with failed handoffs, deals stalled
longer than `crm.pipeline.stuck_days`, unassigned leads older than a day, contracts inside
`crm.contracts.expiring_soon_days`.

### `GET /api/v1/dashboard/staff-performance?range=…`

```jsonc
{
  "data": [{
    "user": { "id": 2, "name": "Sam Salesrep", "job_title": "Sales representative", "team": "…" },
    "leads_handled": 86, "qualified": 52, "deals_won": 17, "won_value": 3240000,
    "conversion_rate": 38, "follow_up_on_time": 91, "csat": 4.1,
    "hr": { "attendance_rate": 94, "leave_days": 3, "late_arrivals": 2, "source": "core2" }
  }],
  "meta": { "period": "30d", "hr_available": true }
}
```

- CRM fields are queries over modules 01–05, scoped like the rest of the dashboard.
- **HR fields come from core2 / performance management**, not from this database. D2 binds an
  `HrGateway` mock (ADR-0005 pattern, `backend/app/Integrations/`) — `attendance(userIds, from, to)`
  returns deterministic demo values. It is **not** a direct connection to an HR database, and a
  failure returns `hr: null` so the UI shows `—`, never `0`.
- A rep with no survey answers in the period has `csat: null` and is excluded from the team average.

### Alerts & activity

Folded into `GET /api/v1/dashboard` rather than separate endpoints:

- `alerts[]` — `{ id, variant: info|success|warning|danger, title, body, href, link_label }`,
  derived from the worklist aggregates (contract expiries, overdue follow-ups, attention answers,
  unassigned leads). Each alert carries the same `minTier` scoping.
- `activity[]` — `{ id, domain: counts|people|activity|cost, title, detail, occurred_at, href }` —
  the last six entries across leads, deals, communications, follow-ups and survey responses the
  caller may see.

## AI management report (D3)

The `ai` block is a **contract**, not an implementation. The future Python service
(`resources/system-ai-feature.md`) receives a redacted payload — scope, period, the KPI numbers and
the aggregated signals (no contact names beyond account names, no email bodies) — and returns:

```jsonc
{
  "headline": "Revenue is ahead, but eight accounts need a human this week.",
  "narrative": "…",
  "insights": [{ "title": "…", "body": "…", "domain": "people", "href": "/…", "link_label": "…" }],
  "actions": [{ "label": "…", "description": "…", "href": "/…" }],
  "model": "…", "generated_at": "…", "source": "model|rules"
}
```

Rules:

1. **Timeout + fallback.** A 3-second timeout (or any non-2xx) falls back to a rules-based template
   computed in Laravel from the same numbers. The panel always renders something.
2. **Never invent a record.** Every insight/action `href` is a real route chosen from a fixed set;
   the model writes prose, not URLs. The same rule as D1 fixtures.
3. **Source recorded.** `source` (`model|rules`) is carried in the payload for diagnostics and the
   repo record. The UI shows no simulation label; `model` and `generated_at` are the only visible
   provenance.
4. **No personal data in the prompt** beyond what the caller may see; the service is stateless.

D1 ships the rules template as static fixtures (the same text shape), so the UI is already final.

## Click map (D1)

| Element | Destination |
| --- | --- |
| New leads · Qualified | `/leads` · `/qualification` |
| Open pipeline · Revenue won · Win rate | `/pipeline` · `/opportunities` |
| Active clients · Needs attention | `/clients` · `/satisfaction/responses?attention=true` |
| Follow-ups due | `/follow-ups` |
| Line chart `Leads` | `/leads` |
| Donut legend + `Board` | `/pipeline` |
| Worklist rows and actions | the module page owning the item |
| Alerts | the worklist/book they summarise |
| Activity rows | the record's module page |
| Staff `Team workload` | `/assignment` |
| AI insights / actions | `/satisfaction/responses?attention=true`, `/pipeline`, `/follow-ups`, `/assignment` |

The response book reads `?attention=` (and `?band=`) from the URL, so an "attention" link lands
pre-filtered; `/satisfaction/responses?company=` already existed (S3).

## Acceptance criteria

### D1 — built

- [x] `/dashboard` renders inside `DashboardShell` for every role, without a per-role layout.
- [x] Content follows the session role (`personal` / `team` / `org`); a salesrep sees only their
      scorecard row and only personal worklist rows; a manager sees the team; an admin the org.
- [x] Header states the scope, the last update and a working period toggle (`30d` / `quarter` /
      `ytd`); switching re-reads the fixtures without a page reload.
- [x] No simulation badge, banner or disclaimer appears in the UI; the D1 mock status is recorded
      in this spec, ADR-0020 and the accessor docblock only.
- [x] 4 primary + 4 secondary KPI cards, all clickable, each with a hint and a trend.
- [x] Line chart (3 series) + donut (4 stages) render from tokens, with labelled axes/legend and
      accessible names; no chart library added.
- [x] The needs-attention table lists severity, subject + detail, type, owner and an action; every
      row and action is a link.
- [x] Alerts and recent activity are present and linked.
- [x] The staff scorecard shows CRM output **and** core2 HR fields, badged `core2 · HR`, with the
      seam explained and `—` semantics reserved for missing data.
- [x] AI panel: headline, narrative, 3 linked insights, 4 linked actions, simulation note.
- [x] Responsive at mobile/tablet/desktop (tables scroll horizontally, grids collapse).
- [x] Tokens/utility classes only — no hardcoded hex or arbitrary px; lucide icons only.
- [x] `frontend` tests cover scope by role, KPI links, worklist links, AI labelling, period toggle,
      charts and the staff table; `npm run lint`, `npm run test`, `npm run build` are clean.

### D2 — future

- [ ] `GET /api/v1/dashboard`, `/dashboard/worklist`, `/dashboard/staff-performance` exist, are
      policy-scoped, and return the shapes above.
- [ ] `getDashboard` calls them; the fixture module is deleted; components are untouched.
- [ ] Loading, empty and error states are exercised (including `hr_available: false`).
- [ ] Backend tests per role (salesrep/manager/admin) prove the scoping; Postgres verified.
- [ ] A dashboard number can be reconciled against its module's list count in a test.

### D3 — future

- [ ] The `ai/` service implements `POST /reports/dashboard` with the documented payload.
- [ ] Laravel times out in ≤3s and falls back to the rules template; `source` records the path.
- [ ] No personal data leaves the CRM beyond the caller's scope.

## Out of scope

- Scheduled/emailed reports, PDF/Excel export, drill-down charts, custom date ranges, saved
  dashboard layouts, per-user widget configuration.
- Real core2/HR connectivity — the seam is a deterministic mock (`HrGateway`).
- A dedicated `/reports` page: D1 is the dashboard home; a fuller management-report page, if the
  client wants one, is a D4 candidate (the AI panel already carries the report content).
- Module 05 S4 (closed-loop follow-through) — deferred behind D1 by ADR-0020; its attention
  notification and resolve flow remain specified in `13-client-satisfaction-survey.md`.

## Impact on other specs

- `13` §S4 is **deferred**, not dropped: the dashboard already reads the attention flag and links
  to the filtered book, so S4 lands without a dashboard rewrite.
- The design system gains `ChartCard`, `LineChart` and `DonutChart` in `components/common/`
  (usable by any later report page).
- `00-data-model.md` is untouched: no table, no column.
