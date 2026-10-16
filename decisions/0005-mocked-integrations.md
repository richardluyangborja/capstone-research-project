# 0005 — Mocked department integrations

- Status: Accepted
- Date: 2026-10-16

## Context

Other departments (service/operations, recruitment, finance, legal/documents, HR) are out of scope
for this repo but the CRM must demonstrate the handoffs and data it consumes.

## Decision

- Define each integration as an **interface** with a **fake/mock driver** returning **seeded** data.
- Toggle via a config flag (e.g. `INTEGRATIONS_MODE=mock`), so real drivers can replace mocks
  without changing callers.
- Define contract shapes with **OpenAPI** (consistent with the `/api/v1` convention). Do not use
  faker for seeds (see `../resources/system-tech.md`).

## Consequences

- System runs and demos end-to-end without external systems.
- Interfaces must be kept stable and documented; integration tests use the mock driver.
- See `../resources/business-flow.md` and `../resources/crm-flow.md` (integration contracts).
