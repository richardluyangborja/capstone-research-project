# 0023 — Module 01 workspace consolidation & account health

- Status: Accepted
- Date: 2026-10-27

## Context

Module 01 is functionally complete but fragmented in the UI. Eight flat sidebar entries present
submodule facets as separate destinations: qualification, assignment and lifecycle are filtered lead
views, conversion is a wizard, and `/clients` duplicates `/companies` behind a status filter. The
company detail page has grown into a long tab strip, while contracts, delivery status and billing
have no cross-account home.

The module also lacks the read model a manager needs: account health is either not computed or hidden
inside separate screens, so a team cannot see which accounts need commercial or delivery attention
without opening each one.

## Decision

1. **The sidebar becomes a grouped tree, not a list of submodules.** Module 01 reads:
   - **Leads** — Lead Workspace, Capture Lead, Import Leads
   - **Accounts** — Account Book, Contracts, Deployments, Billing
   - **Contacts** — Contact Book

   Qualification, assignment, lifecycle and conversion are **not** sidebar entries. They are saved
   views or actions inside the Lead Workspace; conversion remains a dedicated wizard page when
   launched from a lead. `/clients` becomes a **view of the Account Book**, not a second page.

2. **`/accounts` is the canonical account book; `/companies` redirects to it.** The API keeps
   `/companies` — this is a frontend information-architecture change, not an API rename. Existing
   detail links, deep links and bookmarks continue to resolve through redirects.

3. **Account 360 uses five grouped tabs**, replacing the long tab strip:
   - **Overview** — header, journey stepper, health flags, next actions, ownership, lifecycle.
   - **People & Sites** — contacts, primary contact, sites and branches.
   - **Commercial** — opportunities, contracts, rate cards and the billing snapshot.
   - **Delivery** — handoff, job orders, deployments and timesheet summaries (read-only feeds,
     ADR-0025).
   - **Engagement** — communications, satisfaction and activities/timeline.

   Global **Contracts**, **Deployments** and **Billing** pages remain for cross-account work.
   **Billing is manager+**; the account owner sees only a risk flag on an account they may otherwise
   view.

4. **The Lead Workspace is one worklist with saved views.** It carries All / Mine / Unassigned /
   Team (manager+) views plus needs-qualification, at-risk, ready-to-convert and archived filters.
   The manager view adds a workload panel (open leads, follow-ups and unassigned queue by rep). The
   existing qualification, assignment, lifecycle and conversion APIs are reused; no new lead
   pipeline is introduced.

5. **Account health is computed on read and explainable.** A health block is assembled from existing
   and read-only signals: contract expiry/renewal, overdue AR, deployment fill, pending timesheets,
   last touch and satisfaction attention. It returns `flags[]` with a severity, reason and source;
   an optional `suggested_status`/`action`; and never changes `Company.status` automatically. A
   salesrep sees a redacted financial reason (for example, “Finance follow-up needed”) without
   amounts. Automatic lifecycle transitions remain deferred.

6. **The manager dashboard gains a commercial and delegation strip.** It shows AR outstanding,
   renewals due, active deployment headcount/fill, pending timesheets and a team workload table. It
   is a read layer over the same scoped data, not a new reporting table.

7. **Ownership changes become first-class.** Assigning or bulk-assigning an account uses the same
   own/team/all target rules as leads, writes an activity and notifies the new owner. Contracts keep
   deriving their owner from the account; no separate contract-owner field is added.

## Consequences

- Frontend routes, sidebar entries and redirects change; old deep links must continue to work.
- No table is added for account health or the dashboard strip.
- Account 360 fetches its tabs independently, so a heavy tab does not block the overview.
- Billing remains manager+; the owner-facing risk flag is deliberately less detailed.
- `resources/specs/16-module-01-refresh.md` is the delivery contract; `01`, `06` and `09` remain the
  baseline for the original submodule behavior and point to spec 16 where the refresh supersedes them.
- Tests cover redirects, tab permissions, health explanations, redaction and assignment notifications.
