# 17 — Module 01 tracking adjustments

Post-refresh adjustments to Module 01. Extends `16-module-01-refresh.md`, `09-client-account-lifecycle.md`
and `00-data-model.md`. ADRs: **0027** (contracts are read-only), **0028** (the lead journey is derived),
plus 0004, 0013, 0015, 0024 and 0025 where they remain relevant.

> **Status: T1–T2 implemented; T3 planned.** T1 ships the read-only contract projection on
> `feature/module-01-contracts-read-only`; T2 makes the pending-timesheet metric single-source on
> `feature/module-01-delivery-metrics-consistency`; T3 adds the lead journey.
> The contract-write rules in specs `09` and `16`, and the Service-handoff trigger in `ADR-0013`/
> `ADR-0015`, are superseded by ADR-0027 for the surfaces described here.

## Purpose

The Module 01 refresh made the CRM a coherent workspace, but three tracking problems remain:

1. **Contracts have the wrong owner.** The CRM still uploads, edits, signs and renews contracts even
   though Core Transaction 3 (Document & Contract Management / Legal & Compliance) owns those records.
   The CRM should display contract status and commercial information read-only.
2. **The delivery metric is duplicated.** The dashboard and the deployment book each calculate
   “pending timesheets” independently, so the same scope can show different numbers.
3. **The lead lifecycle is not self-explanatory.** The state machine correctly requires a lead to be
   contacted before it can be qualified, but the detail page does not show that path or the next action.

## Scope

- **In:** contract read-only projection and feed seam; removal of contract writes, document storage
  and signed-contract activation/handoff; read-only contract UI; one shared pending-timesheet
  calculation; a derived lead journey and next-action CTA.
- **Out:** contract creation/amendment/e-signature/renewal in the CRM; storing contract documents;
  automatic account activation or service handoff; changing the lead state machine; adding a stored
  lead journey; a full delivery operations module; automatic client aging or dedup/merge.

## Decisions in force

| ADR | Decision |
| --- | --- |
| **0027** | Core Transaction 3 owns contracts; CRM keeps a read-only projection; no contract writes, documents or signing trigger |
| **0028** | The lead journey is a derived read-only view; the state machine is unchanged |

---

## T1 — Contracts are read-only

**Branch:** `feature/module-01-contracts-read-only`

### Boundary

Core Transaction 3 is the source of truth for contract creation, terms, signatures, renewals,
documents and signatories. The CRM tracks the client and consumes a projection of that record.

The projection exists for a practical capstone reason: communications, activities, survey
invitations, deployment/billing mocks, health checks and renewal reminders already reference contract
ids. Replacing the table with a pure external DTO would force a large relational refactor and is not
part of this adjustment. The projection is **never edited through the CRM**.

### Data model

`contracts` and `contract_rate_cards` remain. Add projection metadata:

| Field | Type | Notes |
| --- | --- | --- |
| `external_id` | string, nullable, unique | Core Transaction 3 identifier; the CRM id remains the local ULID route key |
| `synced_at` | timestamp, nullable | last successful projection refresh |

The document fields (`file_path`, `file_name`, `file_size`, `file_mime`) and the private-disk contract
configuration are deprecated. T1 stops reading/writing them and may drop them in the same migration.
The CRM does not accept or serve contract files.

The existing commercial fields and rate-card columns stay because they are read-only tracking data.
`client_monthly_value`, `internal_monthly_cost`, `gross_margin`, `gross_margin_percent` and
`estimated_total_value` remain derived on read. A missing pay rate leaves margin `null`.

### Feed seam

Add `ContractFeed` under `backend/app/Integrations/` with a `MockContractFeed` binding in
`AppServiceProvider`, following ADR-0005/ADR-0025. For T1 the mock reads the seeded projection; a real
driver can later refresh the projection from Core Transaction 3. Controllers/resources must treat the
feed as read-only and expose provenance.

### API surface

Keep:

| Method | Path | Notes |
| --- | --- | --- |
| `GET` | `/api/v1/contracts` | Scoped cross-account book; status/expiry/owner/company/renewal/search filters |
| `GET` | `/api/v1/contracts/{id}` | Detail with company, opportunity, renewal chain, rate cards and computed commercial summary |
| `GET` | `/api/v1/companies/{id}/contracts` | Account contract list |
| `GET` | `/api/v1/contracts/{id}/rate-cards` | Current read-only rate-card set |

Remove:

| Removed surface | Reason |
| --- | --- |
| `POST /companies/{id}/contracts` | Contract creation is external |
| `PATCH /contracts/{id}` | Terms/status edits are external |
| `DELETE /contracts/{id}` / restore | The CRM does not retire external contracts |
| `POST /contracts/{id}/mark-signed` | Signing is external |
| `PUT /contracts/{id}/rate-cards` | Rate-card edits are external |
| `GET /contracts/{id}/file` | Documents stay in Core Transaction 3 |
| `POST /companies/{id}/handoff/retry` | The signing-triggered handoff is retired by ADR-0027 |

Contract responses carry provenance (`source: "Contract Management (mocked, read-only)"`,
`read_only: true`, `as_of`). Remove write affordance flags (`can_update`, `can_delete`,
`can_mark_signed`, `can_manage_rate_cards`, `allowed_transitions`) from contract payloads; retain the
commercial visibility flags (`can_view_margin`, etc.).

### Permissions

| Capability | salesrep | manager | admin / superadmin |
| --- | --- | --- | --- |
| View contracts on a visible account | own | team | all |
| View bill rates / client values | own | team | all |
| View pay rates / margin | — | team | all |
| Create/edit/sign/renew/delete/rate-card write | — | — | — |
| Download a contract document from the CRM | — | — | — |

`ContractPolicy` becomes view-only (with the account's existing scope). Contract API writes are absent,
not merely hidden in the UI.

### Lifecycle and handoff

- `Company.status` stays the CRM lifecycle source and remains manager-managed through
  `PATCH /companies/{id}/lifecycle` and `POST /companies/{id}/reactivate`.
- A signed contract no longer activates a company, changes `client_since` or triggers
  `Company::handoffToService()`.
- Remove `ContractSigned`, `HandoffContractToService` and the handoff retry route. Existing
  `handoff_*` fields may remain as read-only historical/status data; no CRM action mutates them.
- Account journey “Contract signed” reads the projection. Its hint names the external source rather
  than “signing activates the client”.
- A signed projected contract still blocks deleting its company.
- Renewal reminders, account health (`contract_expiring`, `renewal_overdue`), dashboard renewals and
  delivery/billing mocks continue to read the projection. They do not write it.

### UI

- **Account 360 → Commercial:** contract table and rate-card summary are read-only; upload, renew,
  sign, edit and retire actions are gone. The contract deep-link from a won deal no longer opens an
  upload form.
- **`/contracts`:** the scoped contract book keeps filters, summary cards, commercial values and
  links. The row document/open action becomes a contract-detail link only.
- **`/contracts/:id`:** header, commercial terms, read-time totals, rate-card table and renewal chain
  remain. No document download, edit, sign, renew or rate-card editor. A “Contract Management
  (mocked, read-only)” source/as-of line is visible.
- **Opportunity detail / pipeline:** a won deal shows **View linked contract** when the projection has
  one, otherwise “Contracts are managed in Contract Management.” Remove **Create contract from deal**.
- **Account 360 → Delivery:** keep the handoff display if useful, but remove the retry action; label
  it as tracked status, not a CRM workflow.
- Communications may still be logged against a projected contract; the per-contract “Log” action
  remains part of module 03.

### Seeds and tests

- Seed the contract projection and rate cards; stop writing placeholder document files.
- Give the seeded contracts a deterministic `external_id`/`synced_at` (or leave them nullable while
  the mock builds provenance at read time).
- Rewrite contract write tests as read-only/scoping tests. Add assertions that the removed routes are
  not registered.
- Assert that a signed projected contract still blocks account deletion, still appears in health and
  renewals, and that no account status/handoff changes occur from a contract read.
- Keep the demo story: Harbor signed renewal chain, BPO signed/failed handoff display, Sunrise sent,
  Greenfield terminated, Greenfield Cebu draft.

### Acceptance

- [x] No contract write route or document endpoint is registered.
- [x] Contract reads are role-scoped and carry source/read-only/as-of metadata.
- [x] A salesrep sees bill/client values but not pay/margin; a manager+ sees both.
- [x] Rate cards are read-only; the commercial summary still computes on read.
- [x] No contract file is stored or served; seeded documents are removed.
- [x] Contract signing does not move `Company.status`, does not fire a handoff and does not create a
      retry action.
- [x] Signed projected contracts still block company deletion and feed health/renewal reads.
- [x] Communications/activities/surveys still resolve their contract reference.
- [x] The contract book, Account 360 Commercial and contract detail show no write action.
- [x] Backend and frontend contract tests are updated and pass.

---

## T2 — One pending-timesheet metric

**Branch:** `feature/module-01-delivery-metrics-consistency`

### Problem

The dashboard's `commercial.pending_timesheets` and the deployment book's
`summary.deployments_pending` are calculated separately. They currently use the same predicate on
the seeded database, but duplicated logic allows the two screens to drift. The UI label is also
ambiguous: the number counts deployments lacking a current timesheet, not timesheet hours.

### Definition

> **Pending timesheets** = the number of deployment rows whose timekeeping summary is absent or whose
> status is not `current` (including `missing`), within the caller's scope. It is a deployment count,
> not a submission count and not an hour total.

The summary is scope-wide; table filters narrow the table, not the summary.

### Implementation

- Add one shared `DeliverySnapshot`/`DeliveryMetrics` read service under `backend/app/Http` (or the
  existing delivery read layer). It composes the Deployment and Timekeeping feeds once and exposes
  the scope rows plus totals (`total`, `active`, `pending`, `ended`, `fill_rate`,
  `pending_timesheets`, approved/pending hours).
- `DashboardManager::commercial()` and `DeploymentBookController` consume the same snapshot. The
  account Delivery tab uses the same definitions for its per-account cards.
- Add `filter[timesheet]=pending|current|missing` to `GET /api/v1/deployments`.
  - `pending` means not current.
  - `missing` is the subset with no current submission.
- Keep `summary.deployments_pending` as the compatibility field and add
  `summary.pending_timesheets` as the clear name (same value). The frontend reads the clear field.
- The dashboard card links to `/deployments?timesheet=pending`, so the rows behind the number are
  inspectable.
- Rename the visible label to **“Deployments without current timesheet”** (or equivalent) with a hint
  explaining that it counts deployments, not hours.

### Acceptance

- [x] Dashboard and deployment book use one shared calculation.
- [x] For the same user/scope, `commercial.pending_timesheets` equals the deployments
      `summary.pending_timesheets`/`deployments_pending`.
- [x] Applying table filters does not change the summary.
- [x] `filter[timesheet]=pending` shows exactly the rows counted by the card.
- [x] The dashboard card deep-links to the filtered deployment book.
- [x] A regression test fixes the equality, including a filter case.
- [x] Labels/hints say deployments, not hours or submissions.

### Note on the reported 6 vs 3

The current seeded database reports 3 from both endpoints; the discrepancy was not reproducible
during planning. The shared calculation is still the fix for the structural risk. If 6 vs 3 persists
after T2, the remaining investigation is data/role/filter specific and needs the session role and
filters (or a screenshot) captured with the report.

---

## T3 — Lead journey

**Branch:** `feature/module-01-lead-journey`

### Placement

The journey sits above the qualification checklist on `/leads/:id`, using the shared read-only
`JourneyStepper` with `label="Lead journey"`. The existing right-hand Lifecycle card stays as the
complete legal-transition control.

### Steps

**Captured → Contacted → Qualified → Converted**

The steps are derived from the existing `LeadResource` and timeline. No constructor/API/migration is
needed.

| Current status | Captured | Contacted | Qualified | Converted | Journey message / action |
| --- | --- | --- | --- | --- | --- |
| `new` | done | current | pending | pending | Log an outbound activity or **Mark contacted** first |
| `contacted` | done | done | current | pending | Name missing criteria; **Mark qualified** only when complete |
| `qualified` | done | done | done | current | **Convert to client** |
| `converted` | done | done | done | done | Open the account / first deal |
| `nurture` | done | done | pending | pending | “Paused in nurture — re-engage or qualify when ready” |
| `disqualified` | done | per timeline | pending | pending | “Stopped — re-open through Contacted” |
| `archived` | done | per timeline | pending | pending | “Archived — restore to New to restart” |

For branch states, the timeline decides whether Contacted actually happened; the journey must not
invent a completed step. The branch callout is separate from the linear stepper.

### Criteria and action rules

- Map `estimated_headcount`, `needed_roles` and `contact` to “Estimated headcount”, “Needed roles” and
  “Contact channel”. Keep the raw keys in the API.
- `new → qualified` stays illegal. The CTA uses the existing `PATCH /leads/{id}/status` with
  `contacted`; the existing log-activity path can also advance a new lead.
- `contacted → qualified` is offered only when `missing_criteria` is empty. If criteria are missing,
  the CTA says “Complete the checklist” and points at the checklist fields.
- `qualified → converted` opens the existing conversion wizard. Do not duplicate conversion logic.
- `nurture`, `disqualified` and `archived` show only the transitions the existing Lifecycle panel
  already offers.
- The journey is read-only: it never updates status by itself.

### Acceptance

- [ ] Every lead status renders the correct step states and branch message.
- [ ] A `new` lead explains that Contacted comes first and offers **Mark contacted**.
- [ ] A `contacted` lead names missing criteria and only offers qualification when complete.
- [ ] A `qualified` lead links to conversion; a `converted` lead links to the account and deal.
- [ ] Nurture/disqualified/archived show a clear re-entry path and do not fake history.
- [ ] Missing criteria use human-readable labels.
- [ ] The existing Lifecycle dropdown/notes/archive controls remain available.
- [ ] Frontend tests cover all states and the CTA calls.
- [ ] No backend migration or new lead endpoint is added.

---

## Delivery order

| Order | Slice | Branch | Dependency |
| --- | --- | --- | --- |
| 1 | T1 contracts read-only | `feature/module-01-contracts-read-only` | ADR-0027 |
| 2 | T2 delivery metric | `feature/module-01-delivery-metrics-consistency` | none; can follow T1 |
| 3 | T3 lead journey | `feature/module-01-lead-journey` | none; can follow T2 |

T1 is intentionally kept as one reviewable slice because removing the API writes and hiding the UI
actions must land together; a backend-only merge would leave the current UI calling missing routes.
The docs branch is separate and may merge before T1.

## Documentation and bookkeeping

When T1 lands, update:

- `AGENTS.md` — contract boundary, read-only projection, new spec/ADR links, module 01 adjustment note.
- `resources/crm-flow.md` — Contract object, department feeds, won-deal wording, roadmap/decisions.
- `resources/business-flow.md` — contract ownership and the end-to-end flow.
- `resources/specs/00-data-model.md` — projection metadata, deprecated documents, rate-card reads.
- `resources/specs/09-client-account-lifecycle.md` — mark the contract-write/sign/handoff rules
  superseded; lifecycle rules remain.
- `resources/specs/14-dashboard-management-reports.md` — the shared pending-timesheet definition.
- `resources/specs/16-module-01-refresh.md` — note that S6–S8 write surfaces are retired by spec 17;
  do not rewrite their historical status.
- `resources/specs/README.md` — index spec 17 and update the documents/event conventions.
- Historical ADRs 0013/0015/0024/0025 — amend/supersede the affected sections.

## Impact on other specs

- **09 / 16:** contract ownership, signing and handoff wording is superseded; lifecycle, rate-card
  read models, health, renewals and account UI remain.
- **10 (pipeline):** `won` still records the deal; it no longer prepares a contract. The deal links to
  the projection.
- **11 (communications):** contract subjects continue to resolve the projected id; no channel change.
- **12 (follow-ups):** renewal tasks still read contract end dates; no write path.
- **13 (surveys):** the optional contract selector still reads the projection; no document upload.
- **14 (dashboard):** T2 changes only the definition/source of the pending-timesheet commercial metric.
- **00:** contract/rate-card fields remain, with projection metadata and no document serving.
