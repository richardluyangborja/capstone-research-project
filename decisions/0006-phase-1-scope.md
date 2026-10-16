# 0006 — Phase-1 scope for Lead & Client Tracking

- Status: Accepted
- Date: 2026-10-16

## Context

The Lead & Client Tracking module splits into nine submodules (`../resources/crm-flow.md`). We need
a working end-to-end loop for the demo before depth.

## Decision

Phase 1 covers the **full tracking loop**:

1. Lead capture & intake
2. Lead qualification & scoring
3. Lead assignment & ownership
4. Lead lifecycle & status
6. Company / account management
7. Contact management
8. Lead-to-client conversion
9. Client account lifecycle

**Deferred:** duplicate detection & merge (5) and advanced tiers/health scoring.

## Consequences

- Demo shows capture → qualify → convert → client.
- Dedup/merge must still be planned before real data import; conversion should guard against
  obvious duplicates in the meantime.
