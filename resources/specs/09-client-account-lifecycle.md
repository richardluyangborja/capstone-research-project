# 09 — Client Account Lifecycle

Submodule 9. Entities: `Company`, `Contract`, `ContractRateCard` (refresh), `Opportunity`, `Contact`,
`Activity`. ADRs: 0001, 0005, 0007, 0010, 0013, **0023**, **0024**, **0025**.

> **Module 01 refresh (spec `16`, ADR-0023–0025).** Contracts gain a commercial header and rate cards,
> the account's contracts/billing move into the grouped **Commercial** tab, the global **Contracts** and
> **Billing** books are added, and delivery/timekeeping/Finance arrive as read-only feeds. The lifecycle
> rules below are unchanged. Where a commercial or visibility rule differs, spec 16 is the newer
> contract.

> **Implemented.** The contract below is what shipped: notes record the choices made where the spec
> was open (see ADR-0013). The deal pipeline, contract versioning/e-signature, renewal reminders and
> automatic aging are explicitly **not** part of this submodule.

## Purpose

Manage a company once it becomes a client: advance its lifecycle state, trigger the service-department
handoff when a contract is signed, support repeat business, and track dormancy/churn/win-back.

## Lifecycle states

```
prospect ──► onboarding ──► active ◄──► at_risk ◄──► dormant
    │              │           ▲            │            │
    │              └─► churned ◄┴────────────┴────────────┘
    └──────────────────────► active  (a signed contract activates straight from prospect)
```

`Company.status` is the single source of truth and the allowed moves live in
`CompanyStatus::allowedTransitions()`:

| From | May move to |
| --- | --- |
| `prospect` | `onboarding`, `active` *(via a signed contract)* |
| `onboarding` | `active`, `churned` |
| `active` | `at_risk`, `dormant`, `churned` |
| `at_risk` | `active`, `dormant`, `churned` |
| `dormant` | `active`, `at_risk`, `churned` |
| `churned` | `active` *(win-back)* |

`client_since` is stamped on the first move past `prospect` and is never reset — a churned client that
is won back keeps its original client date.

## Business rules

1. **Contract signed → client:** `mark-signed` is the *only* path to `Contract.status = signed`.
   It writes the contract, marks it signed, and then (after the write commits) runs the activation +
   handoff orchestration on the company. A company moves `prospect|onboarding → active`; any other
   status (`active`, `at_risk`, `dormant`, `churned`) is **not** reset by repeat business.
2. **Service handoff (mocked, ADR-0005, ADR-0013 §4–5):** `ServiceHandoff::handoff()` returns a
   `HandoffResult` — never an exception, so it can never roll a signed contract back. Success stores
   `handoff_status = sent` + `handoff_reference = SVC-{contract reference}`. Failure stores
   `handoff_status = failed` + `handoff_error`, parks a `prospect` in `onboarding`, and leaves the
   client retryable via `POST /companies/{id}/handoff/retry` (same orchestration). `HandoffResult`,
   the stored fields and the retry are also the test/demo seam: `CRM_HANDOFF_SIMULATE_FAILURE=true`
   forces the failure path deterministically.
3. **Repeat business:** a client may hold many opportunities and contracts; a new signed contract
   adds a contract and refreshes the handoff, but never resets the lifecycle (rule 1).
4. **`at_risk` / `dormant` are manual in Phase 1.** A manager sets them through the lifecycle
   endpoint; nothing is scheduled and no staleness is computed for clients. Automatic aging and
   renewal reminders are the deferred follow-up/reminder module.
5. **Churn / win-back:** moving to `churned` requires a `reason` (stored with `churned_at`) and is
   manager+; `POST /companies/{id}/reactivate` returns a **churned** client to `active` and clears
   `churn_reason`/`churned_at`. Both write a `system` activity (with the optional `note`) to the
   company timeline. Reactivating anything that is not churned is a 422.
6. **Renewals are a chain:** `renewal_of_contract_id` may reference an earlier contract **of the same
   company** (422 otherwise). The new agreement is uploaded and signed like any other; **“Renew”
   clones the previous header and rate cards** (ADR-0024 §6). `is_expiring_soon`
   (`crm.contracts.expiring_soon_days`, default 30) flags a signed contract whose `end_date` is inside
   the window, and `days_until_expiry` is computed on read — the UI badges it, nothing is scheduled.
7. **Billing visibility (mocked):** `GET /companies/{id}/billing` proxies the Finance department
   (read-only DTO, deterministic from the company reference). The **full** snapshot, invoices and AR
   are manager+ only; a salesrep who may view the account sees a redacted `ar_overdue` health flag /
   billing-risk note and no amounts. Salesreps see client-facing contract bill rates/values but not pay
   rates, cost or margin (ADR-0024). The CRM never writes billing data.
8. **Documents are real:** uploads are multipart (`pdf, png, jpg, jpeg`, ≤ `CRM_CONTRACT_MAX_KB`) on
   the private disk; downloads go through `GET /contracts/{id}/file` after authorization. The stored
   path is never serialized.
9. **A signed contract blocks deleting its company** (`Company::deletionBlockers()` → 422), alongside
   the open-deal blocker from submodule 08.
10. **Commercial terms are structured.** A contract carries a commercial header and zero or more
    `contract_rate_cards` (hourly/monthly/placement). Client monthly value, internal cost, margin and
    estimated total are computed on read; `value` stays the negotiated headline. Manager+ writes rate
    cards; a salesrep reads bill rates but not pay/margin (ADR-0024, spec 16).
11. **Delivery, timekeeping and Finance are read-only feeds** (ADR-0025): handoff, job orders,
    deployments, timesheet summaries and AR/invoices are labelled external, never written by the CRM,
    and never create a CRM table. The only outbound integration remains the Service handoff.

## Permissions

A contract inherits its company's rights (ADR-0011 §2 pattern extended by ADR-0013 §7); its
`owner_id` is the account owner.

| Action | salesrep | manager | admin / superadmin |
| --- | --- | --- | --- |
| View client / contract / document | Own | Team | All |
| Upload + edit a contract, `mark-signed` | Own | Team | All |
| View bill rates / client contract values | Own | Team | All |
| Manage rate cards / view pay rate, cost, margin | — | Team | All |
| Terminate, retire or restore a contract | — | ✓ | ✓ |
| Lifecycle move (`PATCH …/lifecycle`), churn, reactivate | — | Own/team | All |
| Retry a failed handoff | Own | Team | All |
| View full billing / AR (read-only) | hidden; risk flag only | view | view |
| View delivery / timekeeping feeds (read-only) | Own | Team | All |
| Set `Company.status` through `PATCH /companies/{id}` | — | — | ✓ *(system override)* |

## API endpoints

| Method | Path | Description |
| --- | --- | --- |
| `GET` | `/api/v1/companies/{id}/contracts` | Contracts tab — `filter[status]`, `filter[expiring]`, `meta { total, active }` |
| `POST` | `/api/v1/companies/{id}/contracts` | Upload (multipart) + commercial header; 201 |
| `GET` | `/api/v1/contracts` | **Refresh** — scoped cross-account contract book |
| `GET` | `/api/v1/contracts/{id}` | Contract detail (company, opportunity, renewal, owner, rate cards) |
| `GET` | `/api/v1/contracts/{id}/rate-cards` | **Refresh** — current rate-card set |
| `PUT` | `/api/v1/contracts/{id}/rate-cards` | **Refresh** — replace the set (manager+) |
| `PATCH` | `/api/v1/contracts/{id}` | Terms/status; `signed` is rejected (use `mark-signed`), termination is manager+ |
| `DELETE` | `/api/v1/contracts/{id}` | Retire (soft delete) — manager+ |
| `POST` | `/api/v1/contracts/{id}/restore` | Restore a retired contract — manager+ |
| `GET` | `/api/v1/contracts/{id}/file` | Stream the stored document (authorized) |
| `POST` | `/api/v1/contracts/{id}/mark-signed` | Set `signed` → activates the client + Service handoff |
| `PATCH` | `/api/v1/companies/{id}/lifecycle` | Body `status`, `reason` (churn), `note`; manager+ |
| `POST` | `/api/v1/companies/{id}/reactivate` | Win-back a churned client → `active` |
| `POST` | `/api/v1/companies/{id}/handoff/retry` | Retry a failed Service handoff |
| `GET` | `/api/v1/companies/{id}/delivery` | **Refresh** — read-only delivery/timekeeping feed |
| `GET` | `/api/v1/companies/{id}/billing` | Read-only Finance snapshot (manager+; owner risk flag) |
| `GET` | `/api/v1/billing` | **Refresh** — manager+ cross-account AR book |
| `GET` | `/api/v1/companies?filter[status]=…` | Lifecycle lists (client book) |

**Contract payload:** `{ id, reference, company_id, company?, opportunity_id, opportunity?,
renewal_of_contract_id, renewal_of?, title, status, start_date, end_date, signed_at, value, currency,
payment_terms, po_reference, auto_renew, notice_days, signatory_name, signatory_position, terms,
notes, rate_cards[], commercial { client_monthly_value, internal_monthly_cost, gross_margin,
gross_margin_percent, estimated_total_value, can_view_margin }, file { present, name, size, mime },
days_until_expiry, is_expiring_soon, is_signed, is_active, owner, owner_id, allowed_transitions[],
can_update, can_mark_signed, can_delete, created_at, updated_at }`.

**Company payload additions:** `counts.contracts`, `churn_reason`, `churned_at`,
`handoff { status, reference, error, requested_at, can_retry }`, `allowed_status_transitions[]`,
`health { state, flags[], suggested_action, can_view_financial_detail }`, `can_manage_lifecycle`,
`can_view_billing`, `can_view_margin`, `can_upload_contract`.

**Create/update errors:** 422 — not a pdf/png/jpeg, file missing or over 10 MB, `renewal_of_contract_id`
from another company, `opportunity_id` from another company, `status = signed`, illegal status move,
termination by a salesrep, illegal lifecycle move, churn without a reason, invalid rate-card unit or
negative amount; 403 — outside the account, rate-card write for a salesrep, or full billing for a
salesrep (the account's risk flag remains readable).

## UI

- **Lifecycle panel** on `/accounts/:id` (Overview) — current badge, `client_since`, churn reason/date,
  the `Service handoff` reference, and one button per legal move from `allowed_status_transitions`;
  the `churned` move opens an inline reason form, and `churned → active` reads **Reactivate**.
- **Handoff banner** (`AlertItem`, danger) whenever `handoff.status = failed`, with the error and a
  **Retry handoff** button; it states that the signed contract stands.
- **Commercial tab > Contracts** — `DataTable` (reference, agreement with renewal link, status +
  *Renewal due* badge, value, term + expiry countdown, document link, actions) with a status filter, an
  **Engagements** summary card, and an **Upload a contract** form (`FormCard`, multipart) incl. the
  commercial header and the **Renew** prefill that chains `renewal_of_contract_id`. Row actions:
  `Mark signed`, `Renew`, `Retire`.
- **Contract detail** `/contracts/:id` — header, document, renewal chain and the **rate-card editor**
  (role, site, headcount, unit, bill/pay rate, units per period, notes) with computed client monthly
  value, internal cost and margin. Manager+ writes; a salesrep sees bill rates only.
- **Commercial tab > Billing** — read-only snapshot marked as coming from Finance; manager+ sees the
  full AR/invoice table, while the account owner sees only the redacted `ar_overdue` health flag /
  billing-risk note and the source label.
- **Delivery tab** — handoff, job orders, deployments and timesheet summaries, all labelled
  “mocked, read-only” with an `as_of` timestamp; no mutation actions.
- **Account Book** `/accounts` — prospects and clients in one book, filtered by lifecycle status, tier,
  health and owner, with `client_since`, contract count (blue = counts) and tier (cost = orange);
  `?view=clients` is the old client book. The sidebar lists the book under *Lead & Client Tracking*.
- **Global contracts / billing books** — `/contracts` and `/billing` (manager+) for cross-account work.
- Lifecycle moves, signing, handoffs, rate-card corrections and feed-driven health changes all land on
  the account **Engagement > Activities** timeline; feed data itself is not written as an activity.

## Events

- `ContractSigned` (contract, company, actor) — dispatched **after** the contract commits; handled by
  `HandoffContractToService`, which calls `Company::handoffToService()`.
- `CompanyStatusChanged` (company, from, to, actor, note) — handled by `RecordCompanyStatusChange`
  (timeline).
- `CompanyActivated` (company, contract?, actor) and `CompanyChurned` (company, reason, actor) —
  bookkeeping for reporting/retention; dispatched from the same orchestration.

## Acceptance criteria

- [x] `mark-signed` sets `signed_at`, stamps `client_since`, moves the company to `active` and calls
      the Service mock exactly once (the retry path calls it again, and only again).
- [x] A failed handoff does not roll back the signed contract and exposes `handoff.can_retry` + the
      retry endpoint; the retry activates the client.
- [x] `mark-churned`/`reactivate` are 403 for a salesrep and require a reason for churn; reactivation
      is rejected unless the client is churned, and it preserves `client_since`.
- [x] `signed` is unreachable by `PATCH /contracts/{id}`; a second `mark-signed` is 422; an illegal
      lifecycle or contract move is 422.
- [x] Billing is read-only, hidden from salesreps (403), and deterministic per company.
- [x] A company with a signed contract cannot be deleted (422); a draft/terminated one can.
- [x] A company with multiple contracts/opportunities keeps its status — signing an agreement on an
      `at_risk`/`dormant` client leaves the status alone.
- [x] The uploaded document is stored privately and only downloadable through the authorized endpoint.
- [ ] A contract stores its commercial header and rate cards; hourly/monthly/placement totals compute
  on read and a missing pay rate leaves margin `null`.
- [ ] A salesrep sees bill rates/client values but not pay rates, cost or margin; rate-card writes and
  full billing/AR are manager+.
- [ ] A renewal clones the previous header and rate cards without mutating the old contract.
- [ ] Delivery, timekeeping and Finance data is labelled read-only, carries source/`as_of`, offers no
  mutation action, and adds no CRM table.

## Open questions

- ~~Do we need `onboarding` as a distinct state, or collapse it into `active`?~~ — **Keep it:** it is the
  state a signed-but-not-yet-handed-off client waits in, which is what makes the failed handoff
  visible instead of silently "active".
- ~~Automatic aging to `at_risk`/`dormant` in Phase 1, or manual only?~~ — **Manual only** (ADR-0013
  §3); the follow-up/reminder module owns renewal reminders and ageing. The refresh adds computed
  health **flags**, not automatic moves (ADR-0023).
- ~~Is a region/currency assumption safe (PHP only)?~~ — **Yes** (ADR-0007); billing and rate cards are
  PHP only.
