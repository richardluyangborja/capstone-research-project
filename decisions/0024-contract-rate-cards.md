# 0024 — Contract rate cards & commercial terms

- Status: Accepted
- Date: 2026-10-27

## Context

The existing `Contract` record carries one headline `value`, a term summary and dates. That is enough
to track a document, but not to explain what the client is paying for. Sales cannot see the roles,
headcount, units or bill rates behind an agreement; Finance has no structured commercial basis to
mirror in its read-only feed; and renewals cannot start from the previous agreement's terms.

## Decision

1. **A contract gets a structured commercial header.** New optional fields:
   `payment_terms`, `po_reference`, `auto_renew`, `notice_days`, `signatory_name` and
   `signatory_position`. The existing `value`, `currency`, `start_date`, `end_date`, `terms`, `notes`
   and document fields remain. `value` stays the negotiated headline; the rate cards explain it and
   do not overwrite it.

2. **Rate cards are CRM-owned contract rows.** A new `contract_rate_cards` table holds:
   `contract_id`, `site_id` (optional), `role_title`, `headcount`, `billing_unit`, `bill_rate`,
   `pay_rate` (nullable), `units_per_period`, `notes` and `position`. A contract may have zero or
   more rows. The rows are replaced as a set when edited.

3. **Three billing units cover the staffing model:**
   - `hourly` — bill/pay per hour; `units_per_period` is the expected hours in a month (default 176).
   - `monthly` — bill/pay per month; `units_per_period` is normally 1.
   - `placement` — a one-time fee; `units_per_period` is normally 1 and no monthly run rate applies.

4. **Commercial totals are computed on read, never stored.** The API derives per-line and contract
   values: `client_monthly_value`, `internal_monthly_cost`, `gross_margin`, `gross_margin_percent`,
   and `estimated_total_value` over the term. A placement line contributes once; an hourly/monthly
   line contributes per period. `headcount` multiplies the line. Missing pay rates leave margin
   fields `null`, never `0`.

5. **Visibility follows the sensitive-data rule.** A salesrep who can view the account sees the
   client-facing bill rate and client value. Pay rates, internal cost, gross margin and margin percent
   are manager+ only; their resource fields are omitted (or `null` with `can_view_margin = false`).
   Writing or replacing rate cards is manager+; uploading/editing the contract header stays with the
   account's existing `update` right.

6. **Renewals clone the commercial terms.** “Renew” creates a new contract prefilled with the prior
   contract's rate cards and header, linked by `renewal_of_contract_id`. The new agreement is edited
   and signed normally; the old contract is not mutated.

7. **API surface:**
   - `GET /contracts/{id}` includes `rate_cards[]` and the computed summary when loaded.
   - `GET /contracts/{id}/rate-cards` lists the current set.
   - `PUT /contracts/{id}/rate-cards` replaces the set (manager+).
   - The company contract list and global contract book carry a compact rate-card summary.
   - `GET /contracts` is the cross-account book (scoped, filterable by status/expiry/owner/company).

8. **Not built:** e-signature, clause versions, multiple documents per contract, automatic renewal,
   invoice generation or a stored “contract total” that goes stale when a rate card changes.

## Consequences

- New migration and `ContractRateCard` model/resource/policy checks; a contract without rate cards
  remains valid for backward compatibility.
- The contract form gains a commercial section and a repeatable rate-card editor; the account's
  **Commercial** tab and the global **Contracts** book render the summary.
- The mocked Finance feed may derive assumed invoices from rate cards, but Finance remains the owner
  of the invoice data (ADR-0025).
- Seeds add rate cards to every demo contract, including the Harbor renewal chain and the Sunrise
  agreement out for signature.
- Tests cover billing units, computed values, null margins, renewal cloning and the manager+ write
  gate.
