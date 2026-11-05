# Business Flow — Primepower Manpower Service

> Business context for the CRM subsystem. Technical/system flow lives in `crm-flow.md`.
> Items marked *(assumed)* are reasonable defaults inferred from the docs — confirm or correct.

## The business

Primepower Manpower Service is a **manpower / staffing agency**. It recruits workers and **deploys
them to client companies**. Its hiring is limited to **rank-and-file / non-managerial roles** — it
supplies workers, it does not place executives or managerial staff.

Revenue model *(assumed)*: service/placement fees and/or ongoing contract-staffing margin billed to
the client company; deployed workers are the agency's employees but work at the client site.
Contracts themselves are owned by Contract Management / Legal (Core Transaction 3). Everything after
a signed contract (deployment, timesheets, pay, billing) is owned by other departments — the CRM's job
is to **win, track, and retain the client**, reading contract status read-only.

## Departments / stakeholders

| Department | Owns | Relation to this system |
| --- | --- | --- |
| **CRM / business development (marketing)** | Leads, opportunities, client relationships, follow-ups, satisfaction | **Primary user** of this subsystem |
| **Service / operations** | Deployment & assignment of manpower after a contract is won | Deployment/handoff status is read-only here; the CRM no longer triggers the handoff |
| **Recruitment** | Candidate sourcing/screening against job orders | Consumes won deals; mocked here |
| **Finance** | Billing, invoices, AR/collections | Source of billing status shown to CRM; mocked here |
| **Legal / compliance & documents** | Contracts, government compliance, archiving | **Owns contracts and documents**; the CRM consumes status/commercial data read-only (ADR-0027) |
| **HR** | Deployed employees, timekeeping, payroll, performance | Provides employee/performance data; mocked here |

## Pain points the client wants solved

- Manual work on **finance/billing**, **deployments**, and **contracts**
- No centralized way to **track and follow up leads** (email or walk-in)
- Lead → service-department **handoff is manual**
- Client **contracts and billings** are hard to track after signing
- No structured **client satisfaction** capture
- No **employee performance monitoring**

## End-to-end business flow

```
Inquiry/lead  →  Qualify & work opportunity  →  Deal won  →  Contract prepared & signed
   (CRM)              (CRM pipeline,                 (Core Transaction 3)
                       communication,                      |
                       follow-ups)                         v
                                                    Contract status/commercial info
                          +-----------------------  synced to CRM (read-only)
                          |  follow-ups,                |
                          v  renewals                   v
                     Contract expiry / churn      Client active: deployments,
                                                  billing (Finance), satisfaction,
                                                  upsell/new deals
                                                  (CRM tracks; Service + Finance own)
```

1. A lead/inquiry arrives (walk-in, email, phone, referral, website, or outbound prospecting).
2. CRM qualifies it and works it through the **opportunity pipeline**, logging every
   **communication** and scheduling **follow-ups**.
3. When the deal is **won**, the contract is created, signed and stored in **Core Transaction 3**
   (Contract Management / Legal). The CRM does not upload, sign or renew it.
4. The CRM reads the contract reference, status, terms and commercial detail; it does **not** activate
   the client or hand off to Service automatically. A manager moves the client lifecycle when the
   business is ready, and deployment/billing status comes from the read-only feeds.
5. Ongoing: contract & billing tracking, renewal/check-in follow-ups, client satisfaction, and
   **new opportunities** (upsell/cross-sell) against the same client.
6. Eventually a client may churn / go dormant — tracked for win-back.

## Scope of this repository

This repo implements **only the CRM subsystem** (see `system-modules.md`). The other departments
above are **external integrations that are mocked for now** so the system runs and can be demoed.
Integration contracts are defined in `crm-flow.md`.
