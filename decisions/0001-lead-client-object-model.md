# 0001 — Lead/client object model

- Status: Accepted
- Date: 2026-10-16

## Context

The CRM must track raw inquiries (leads) and the organizations they may become, without treating
every prospect as a client. Real CRMs separate the unqualified record from the reusable account.

## Decision

Adopt a Salesforce-style model:

- **Lead** is a separate record (not a client).
- Converting a lead creates a **Company/Account** + **Contact(s)** + **Opportunity** together.
- A Company becomes a **Client** once a contract is signed — modeled as a lifecycle state on
  Company, **not** a separate table.
- Companies support parent/child for multi-branch clients.

## Consequences

- Contacts/accounts are reusable across repeat deals; better reporting.
- Requires an explicit conversion workflow and duplicate detection before conversion.
- More tables/relations than a single-table design; conversion is a distinct code path.
- See `../resources/crm-flow.md` (object model, lead lifecycle).
