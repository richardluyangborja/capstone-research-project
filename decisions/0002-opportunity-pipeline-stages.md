# 0002 — Opportunity pipeline stages

- Status: Accepted
- Date: 2026-10-16

## Context

Staffing CRMs separate raw-lead qualification from the deal pipeline so unqualified names don't
distort forecasting.

## Decision

Run two pipelines:

- **Lead qualification:** New → Contacted → Qualified → Disqualified / Nurture / Archived
- **Opportunity (deal):** New/Inquiry → Qualified → Discovery/Needs Assessment → Proposal Sent →
  Negotiation → Won → Lost, plus **On Hold**.

## Consequences

- Each stage needs entry/exit criteria and a win probability for forecasting.
- **Won records the outcome and prompts the contract/upload step; the service-department handoff
  fires when the contract is signed**, not on `won` (refined by ADR-0013 §5 and ADR-0015 §4 — the
  original "won triggers the handoff" wording is superseded).
- See `../resources/crm-flow.md`; the pipeline itself is module 02 (ADR-0014, ADR-0015).
