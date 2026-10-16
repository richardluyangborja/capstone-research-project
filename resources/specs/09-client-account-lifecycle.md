# 09 — Client Account Lifecycle

Submodule 9. Entities: `Company`, `Contract`, `Opportunity`, `Contact`, `Activity`. ADRs: 0001, 0005.

## Purpose

Manage a company once it becomes a client: advance its lifecycle state, trigger the service-department
handoff on contract signing, support repeat business, and track dormancy/churn/win-back.

## Lifecycle states

```
prospect ──► onboarding ──► active ──► at_risk ──► dormant ──► churned
                 ▲                        │            │          │
                 └──── (reactivate) ──────┴────────────┴──────────┘  (win-back)
```

`Company.status` is the single source of truth; `client_since` is set when the first contract signs.

## Business rules

1. **Contract signed → active:** when a `Contract` reaches `status = signed`, the company moves
   `prospect|onboarding → onboarding|active`, `client_since` is set, and the **service handoff** fires.
2. **Service handoff (mocked, ADR-0005):** call the Service interface with client + contract +
   manpower requirement; store the handoff reference/status on the company. Failure leaves the
   company in `onboarding` and surfaces a retry (`AlertItem`) — never blocks the contract record.
3. **Repeat business:** a client may hold many opportunities/contracts over time; new deals enter the
   normal pipeline and do not reset lifecycle.
4. **`at_risk` / `dormant`:** a client with no active contract or no activity for the configured
   window (propose 60 / 90 days) is flagged `at_risk`, then `dormant`. Phase 1 may set these
   manually; automatic aging can be deferred.
5. **Churn / win-back:** `mark-churned` sets `churned` and records reason + date; `reactivate`
   returns the client to `active`. Both require manager+.
6. **Renewals:** contracts form a chain via `renewal_of_contract_id`; expiry dates drive the
   (separate) follow-up/reminder module.
7. **Billing visibility (mocked):** the client view shows read-only billing/AR status from Finance
   (ADR-0005); no billing writes from CRM.

## Permissions

| Action | salesrep | manager | admin / superadmin |
| --- | --- | --- | --- |
| View client | Own | Team | All |
| Upload contract | Own | Team | All |
| mark-churned / reactivate | — | ✓ | ✓ |
| Manual status override | — | ✓ | ✓ |
| View billing (read-only) | hidden | view | view |

## API endpoints

| Method | Path | Description |
| --- | --- | --- |
| `PATCH` | `/api/v1/companies/{id}/lifecycle` | Body `status` (+ `reason` for churn); manager+ |
| `POST` | `/api/v1/companies/{id}/contracts` | Upload contract (multipart PDF/PNG/JPEG) + terms |
| `POST` | `/api/v1/contracts/{id}/mark-signed` | Set `signed` → triggers client activation + handoff |
| `GET` | `/api/v1/companies/{id}/billing` | Read-only proxy to Finance mock |
| `POST` | `/api/v1/companies/{id}/handoff/retry` | Retry a failed service handoff |
| `POST` | `/api/v1/companies/{id}/reactivate` | Win-back → `active` |
| `GET` | `/api/v1/companies?filter[status]=active|at_risk|dormant|churned` | Lifecycle lists |

## UI

- **Lifecycle stepper** on `/companies/:id` (Overview tab) with allowed transitions only.
- **Contracts tab** — upload, status (`StatusBadge`), terms, renewals, expiry countdown.
- **Handoff status** banner (`AlertItem`) with retry when the mock call failed.
- **Billing panel** — read-only, marked "from Finance"; hidden for salesrep.
- **Clients list** — filter by lifecycle status/tier; cost-domain styling (orange) for billing.

## Events

- `ContractSigned` → `CompanyActivated` → **Service handoff** (mock).
- `CompanyChurned` → retention/win-back reporting.

## Acceptance criteria

- [ ] Marking a contract `signed` sets `client_since`, advances the company, and calls the Service
      mock exactly once.
- [ ] A failed Service handoff does not roll back the signed contract and exposes a retry.
- [ ] `mark-churned`/`reactivate` are rejected for salesrep (403) and require a reason for churn.
- [ ] Billing proxy is read-only and hidden from salesrep responses.
- [ ] A company with multiple contracts/opportunities shows full history without resetting status.

## Open questions

- Do we need `onboarding` as a distinct state, or collapse it into `active`?
- Automatic aging to `at_risk`/`dormant` in Phase 1, or manual only?
- Is a region/currency assumption safe (PHP only)?
