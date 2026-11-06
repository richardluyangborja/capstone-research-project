# 0027 — Contracts are a read-only CRM projection

- Status: Accepted
- Date: 2026-11-05

## Context

The CRM currently owns the contract record and its write workflow: upload, commercial-header edit,
rate-card replacement, `mark-signed`, renewal creation, retirement and document download. Signing
also activates the account and triggers the mocked Service handoff (`ADR-0013`, `ADR-0024`).

That is the wrong system boundary. Core Transaction 3 owns **document and contract management** and
**legal and compliance** (`../implementation/list-of-departments.md`). The CRM's job is to track the client
relationship, not to author, sign, store or renew the legal agreement. Keeping a second contract
workflow in the CRM creates duplicate ownership and makes the CRM look like the source of truth when
it is not.

The CRM still needs contract information for account health, renewals, commercial context, surveys,
communications and delivery tracking. Removing the record entirely would break those relationships.

## Decision

1. **Core Transaction 3 is the source of truth for contracts.** Contract creation, amendments,
   signatures, renewals, term changes, documents and signatories are handled in the external
   contract-management system. The CRM never writes those records.

2. **The CRM keeps a read-only projection of contracts.** The existing `contracts` and
   `contract_rate_cards` tables remain as a projection/snapshot for the capstone so existing
   relationships (activities, communications, surveys, deployments, billing, health and renewals)
   keep a stable local key. `ContractFeed` plus `MockContractFeed` in `backend/app/Integrations/`
   is the integration seam; a real driver can refresh the projection later. The projection is not
   an editable CRM record.

3. **Only read endpoints remain.** The CRM exposes:
   - `GET /api/v1/contracts`
   - `GET /api/v1/contracts/{id}`
   - `GET /api/v1/companies/{id}/contracts`
   - `GET /api/v1/contracts/{id}/rate-cards`

   The following are removed: contract upload/create, edit, delete/restore, `mark-signed`, rate-card
   replacement, renewal creation and document download. Contract responses carry
   `meta.source`, `meta.read_only` and `meta.as_of` (or an equivalent resource-level provenance
   block).

4. **Documents live outside the CRM.** The CRM does not store contract files or stream downloads.
   A future feed may provide a safe external document link, but the initial implementation exposes
   no document action. Existing file columns and the private-disk config are deprecated and may be
   dropped by the implementation slice.

5. **Commercial information stays visible, read-only.** The projection may expose reference, status,
   dates, value, payment terms, renewal chain and rate cards. Client-facing bill rates and values
   remain visible to a salesrep who can see the account; pay rates, internal cost and margin remain
   manager+ (`ADR-0024` visibility rule, retained). There is no rate-card editor.

6. **Contract status is informational; it does not move the CRM lifecycle.** The CRM no longer
   activates an account or triggers the Service handoff when a contract becomes signed. `Company.status`
   remains manager-managed through the existing lifecycle panel/endpoint. The `ContractSigned` event,
   its handoff listener and `POST /companies/{id}/handoff/retry` are retired. Existing handoff fields
   may remain as read-only historical/status data until a separate decision gives them a new owner.

7. **A won deal no longer creates a contract.** The deal page links to the synced contract when one
   exists; otherwise it states that contract work happens in Core Transaction 3. The CRM does not
   offer a contract-upload prefill.

8. **The projection keeps the existing relations.** A communication, activity, survey invitation or
   follow-up may still reference a contract's projected id. Signed contracts continue to block account
   deletion, drive renewal reminders/health flags and provide delivery/commercial context; these are
   reads over the projection, not contract writes.

9. **Read scoping is unchanged.** A salesrep sees contracts on accounts they may view, a manager the
   team's, an admin all. The projection never widens account visibility.

## Consequences

- The contract book, account Commercial tab and contract detail become read-only screens with source
  labelling; upload/sign/renew/rate-card/document actions disappear.
- The backend removes the contract write controllers/routes/policies, the signing event/listener and
  the handoff retry route. Contract tests become read-only/scoping tests.
- The account journey's “Contract signed” step reads the projection; it no longer implies a CRM
  signing action.
- Seeds stop writing placeholder contract documents and seed the projection/commercial terms instead.
- The mock feeds that derive from contracts continue to work; replacing the projection with a real
  sync is a driver change, not a UI change.
- Historical `ADR-0013`, `ADR-0015`, `ADR-0024` and `ADR-0025` are amended/superseded where they
  describe CRM-owned contract writes or signing-triggered handoff. `../specs/17` is the current
  implementation contract.
