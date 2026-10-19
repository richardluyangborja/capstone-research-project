# 0013 — Client lifecycle, contracts & the integration seam

- Status: Accepted
- Date: 2026-10-17

## Context

Submodule 09 (`../resources/specs/09-client-account-lifecycle.md`) is the last Phase-1 piece: it
turns a `prospect` account into a paying client and keeps it there. Building it raised questions the
spec left open:

1. How much of submodule 09 ships — lifecycle only, contracts only, or both, and does it ship the
   renewal/pipeline machinery around them?
2. Are contract documents really uploaded, or is the record enough?
3. Is `at_risk`/`dormant` aging automatic in Phase 1?
4. The other departments are mocked (ADR-0005), but there is no home for those adapters yet.
5. How does a signed contract reach `active` without a real Service department, and what happens
   when the handoff fails?
6. Who may move a client along the lifecycle, terminate a contract, or read billing?

## Decision

1. **09 ships the `Contract` record, the client lifecycle and their UI together — and only those.**
   One PR: `Contract` (create/terms/sign/retire), the lifecycle state machine, the mocked Service
   handoff, the read-only billing proxy, the Contracts tab, the lifecycle panel and a `/clients`
   client book. **Not shipped here:** the deal pipeline (stage moves, won/lost, Kanban), contract
   *management* beyond status/terms (no e-signature, no clause versions), and automatic aging.
   Renewals are a **chain** (`renewal_of_contract_id`) created manually by uploading the next
   agreement — there is no scheduled renewal job.
2. **Contract documents are really stored.** Upload is multipart (`pdf, png, jpg, jpeg`, ≤ 10 MB from
   `crm.contracts.max_kb`) and written to the private `local` disk
   (`storage/app/private/contracts/…`) under a ULID name; the original filename, size and MIME are
   kept on the row. Reading is `GET /contracts/{id}/file`: authorized, then streamed — the storage
   path is never serialized (`ContractResource` exposes only `file { present, name, size, mime }`).
   A contract without a document cannot be created through the API; the demo seeder writes a small
   real PNG per contract so the download works in a demo. Swapping to object storage is a disk name
   (`CRM_CONTRACT_DISK`), not a new layer.
3. **Aging is manual in Phase 1.** `at_risk`, `dormant` and `expired` are set by a manager through
   `PATCH /companies/{id}/lifecycle` (or the contract edit); nothing is scheduled and no staleness is
   computed for clients. Renewal reminders and automatic aging belong to the deferred
   follow-up/reminder module. `Contract::isExpiringSoon()` (`crm.contracts.expiring_soon_days`,
   default 30) is a **read-time flag** the UI badges — not a background effect.
   *(Amended by ADR-0018 §6: renewal reminders land in that module as intended (spec 12 S4), but
   **automatic aging** was left out of it and stays a standalone deferral — it advances the client
   lifecycle rather than reminding anyone.)*
4. **Department integrations live in `app/Integrations/` — the one deliberate new base folder.**
   This is the documented exception to the backend rule "don't create new base folders without
   approval". Each mocked department is an interface (`ServiceHandoff`, `FinanceBilling`) plus a
   `Mock*` driver and `final readonly` DTOs (`HandoffResult`, `BillingSummary`), bound in
   `AppServiceProvider`; controllers depend on the interface only. Behaviour is **deterministic, not
   random**: the handoff acknowledges unless `CRM_HANDOFF_SIMULATE_FAILURE=true`
   (`crm.integrations.service_handoff.simulate_failure`) — the switch that demos and tests the retry
   path — and billing figures are derived from `crc32($company->reference)` so a snapshot never
   changes between runs. Replacing a mock with a real client touches the binding, not the CRM.
5. **`signed` is only reachable through `mark-signed`, and the commit and the handoff are separate
   operations.** `POST /contracts/{id}/mark-signed` writes the contract in a transaction and
   dispatches `ContractSigned` **after** it commits; `HandoffContractToService` calls
   `Company::handoffToService()`, which is also exactly what `POST /companies/{id}/handoff/retry`
   calls, so both paths run one orchestration. Success ⇒ company `active`, `handoff_status = sent`,
   `handoff_reference = SVC-{contract} reference`. Failure ⇒ the contract stays `signed` (never
   rolled back), the company is parked/left `onboarding` with `handoff_status = failed` +
   `handoff_error`, and the UI surfaces a retryable `AlertItem`. `ServiceHandoff` returns a
   `HandoffResult`, never an exception. **Repeat business never resets an established client:** only
   a `prospect`/`onboarding` account advances, and only a `prospect` is parked by a failure — an
   `active`/`at_risk`/`dormant` client keeps its status while the handoff result is stored.
6. **The lifecycle is a state machine, enforced once and read everywhere.**
   `CompanyStatus::allowedTransitions()` is the single definition; `Company::transitionTo()` raises
   422, `UpdateCompanyLifecycleRequest` validates against it, and the UI renders only
   `allowed_status_transitions`. `PATCH /companies/{id}/lifecycle` is **manager+**
   (`CompanyPolicy::manageLifecycle`); a churn requires a `reason` (stored as `churn_reason` +
   `churned_at`); `POST /companies/{id}/reactivate` is the win-back and accepts **only** a `churned`
   client. The first move past `prospect` stamps `client_since`, and it is never reset — a churned
   client that is won back keeps its original client date. The direct `status` field on
   `PATCH /companies/{id}` stays **admin-only** (ADR-0010 §3) as the system override; the two paths
   do not overlap.
7. **Contract permissions follow the account.** Uploading, editing and signing a contract require
   `CompanyPolicy::update` on its company, so a salesrep maintains the agreements on their own
   accounts with the usual own/team/all scoping (`ContractPolicy` delegates to the company).
   Retiring/restoring a contract and terminating one are manager+ (`CompanyPolicy::manageContracts`,
   i.e. `mayRestructure`). Billing is `viewBilling` — manager+ and **hidden from salesreps**. A
   contract's `owner_id` is the **account owner**, so contract scoping and company scoping can never
   disagree.
8. **A signed contract blocks deleting the company.** `Company::deletionBlockers()` — the extension
   point ADR-0010 §6 left open — returns a 422 while any contract is `signed`, alongside the
   open-deal blocker from 08. Draft/sent/expired/terminated contracts block nothing.

## Consequences

- New table `contracts` (ULID, soft deletes, `CON-YYYY-NNNN` display reference — ADR-0007) and new
  `companies` columns `churn_reason`, `churned_at`, `handoff_status`, `handoff_reference`,
  `handoff_error`, `handoff_requested_at`. `counts.contracts` joins the company roll-up, and
  `CompanyResource` gains `churn_*`, `handoff { status, reference, error, requested_at, can_retry }`,
  `allowed_status_transitions` and `can_manage_lifecycle` / `can_view_billing` / `can_upload_contract`.
- Events: `ContractSigned`, `CompanyStatusChanged`, `CompanyActivated`, `CompanyChurned`. Listeners:
  `HandoffContractToService`, `RecordCompanyStatusChange` (the lifecycle appears on the account
  timeline as a `system` activity).
- `ContractStatus` (draft → sent → signed, then expired/terminated) is deliberately **separate** from
  the deal `OpportunityStage`: an agreement and a sale are different machines. `accessibleTransitions()`
  omits `signed` so a plain `PATCH` can never activate a client.
- Billing answers as `{ data, meta: { source: "Finance (mocked)", read_only: true, as_of } }` rather
  than a resource: it is an external snapshot, not a CRM row.
- Demo data covers every state (see the spec): a signed renewal chain with an accepted handoff, a
  signed contract whose handoff failed (the retry banner), one out for signature, a terminated
  churned account and a draft.
- Deferred and **not** stubbed: the pipeline module, contract versioning/e-signature, renewal
  reminders and automatic aging. `resources/specs/06-company-account-management.md` records that the
  Contracts tab now exists; `AGENTS.md` records the `app/Integrations/` seam.
