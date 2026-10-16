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
- Won triggers the service-department handoff and the contract/upload step.
- See `../resources/crm-flow.md`.
