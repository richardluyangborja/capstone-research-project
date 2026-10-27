# 0025 — Read-only external department feeds

- Status: Accepted
- Date: 2026-10-27

## Context

The CRM is the client-side relationship system. Recruitment, deployment/assignment, timekeeping and
finance own their operations in other systems; this repo must not recreate their job-order,
deployment, timesheet or invoice workflows. The CRM still needs the information those systems would
provide so a manager can answer: is the job order filled, is the crew deployed, are hours approved,
and is the client paying?

The existing integration seam already has `ServiceHandoff` and a small `FinanceBilling` snapshot
(ADR-0005, ADR-0013 §4). Module 01 needs the same read-only treatment for delivery and timekeeping,
without adding operational records.

## Decision

1. **Every department integration is a read-only feed behind an interface, a `Mock*` driver and
   `final readonly` DTOs in `backend/app/Integrations/`.** The mock is a deterministic fixture adapter,
   not a department module. Real drivers replace the binding without changing controllers or screens.

2. **The CRM consumes these feeds:**
   - **Recruitment / job orders** — job order reference, role, requested/filled headcount, status,
     priority, target start, recruiter and last update.
   - **Deployment / assignment** — deployment reference, contract/job-order reference, role, site,
     status, active/filled headcount, start/end, operations owner, last update and fill rate.
   - **Timekeeping** — per-deployment current-period summary: period bounds, approved hours, pending
     hours, last submission and status. No employee-level rows.
   - **Finance / AR** — the existing billing summary plus invoice rows, aging, due dates, balances,
     payment status and credit terms.
   - **Service / operations** — the existing handoff result (`sent`/`failed`), reference and requested
     time.

   **HR, legal/documents and payroll are deferred.** Candidate/applicant records, worker names,
   rosters, shifts, timesheet entry/approval, invoice creation/posting and service tickets are **not
   built**.

3. **The CRM never writes feed data.** The only outbound integration remains the Service handoff on
   contract signing (`POST /contracts/{id}/mark-signed` and its retry path). There are no create,
   update or delete routes for job orders, deployments, timesheets, invoices or AR.

4. **No tables are added for any feed.** The data is assembled on read. The mock derives coherent
   values from CRM-owned records (contract, rate cards, company status, dates) rather than faker, so
   the demo tells one story on every run. It is still labelled as external data.

5. **Every feed response carries provenance:** `meta.source` (for example `Recruitment (mocked,
   read-only)`), `meta.read_only: true` and `meta.as_of`. The UI prints the label and an “as of”
   timestamp; it never presents feed data as a CRM-owned record or offers a mutation action.

6. **API surface:**
   - `GET /companies/{id}/delivery` — handoff + job orders + deployments + timesheet summaries.
   - `GET /deployments` — the cross-account read-only deployment book; filters by status/company/
     contract/owner and `search`.
   - `GET /companies/{id}/billing` — existing Finance snapshot, expanded with invoices and aging.
   - `GET /billing` — the cross-account read-only AR book, manager+.

   All are role-scoped through the account: a salesrep sees their own accounts, a manager the team's,
   an admin all.

7. **Financial visibility stays split.** The full AR/invoice feed is manager+. An account owner who
   may view the account sees only a redacted `ar_overdue` health flag / billing-risk note and the source
   label, never amounts, balances or invoice rows. This is the same rule as ADR-0023 §3 and ADR-0013 §7.

8. **The mock must be honest about being assumed data.** It may be derived from a signed contract and
   its rate cards, but it does not claim that a deployment or invoice exists in this database. The
   source label, read-only flag and absence of write routes are part of the contract.

## Consequences

- New integration interfaces/DTOs/mocks, controllers and resources; no new tables.
- Company delivery and billing tabs become read-only views; global deployments and billing books are
  cross-account reads.
- Seeded scenarios cover: a healthy active client with current AR, approved hours and filled
  deployments; an onboarding client whose handoff failed; a prospect with no delivery yet; and a
  churned client with closed/ended feeds.
- Tests assert read-only behaviour, scoping, source metadata, manager-only billing and the absence of
  mutation routes.
- When real APIs arrive, the mock binding is replaced; the UI and CRM rules do not change.
