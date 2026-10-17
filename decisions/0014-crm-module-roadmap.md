# 0014 — CRM module roadmap & spec numbering

- Status: Accepted
- Date: 2026-10-17

## Context

Module 01 (Lead & Client Tracking) is complete (`specs/01`–`09`, ADR-0006). `resources/system-modules.md`
lists four more CRM modules, and `resources/specs/README.md` / `resources/crm-flow.md` had started
calling them "Phase 2" — the same label used for the dedup/merge and advanced-tier deferrals *inside*
module 01. That overloaded term makes "what is left?" ambiguous as we plan the next module.

## Decision

1. **The CRM has five modules** (from `resources/system-modules.md`). Module 01 is done; the roadmap
   order for the rest is:

   | Module | Name | Spec |
   | --- | --- | --- |
   | 01 | Lead & Client Tracking | `01`–`09` (done) |
   | 02 | Opportunity Pipeline Visualization | `10` |
   | 03 | Communication History Management | `11` |
   | 04 | Follow-up Reminder System | `12` |
   | 05 | Client Satisfaction & Survey | `13` |

   The AI service (dashboard + management reports + suggestions, `resources/system-ai-feature.md`) is
   **cross-cutting**, not a sixth module: it consumes the data modules 01–05 produce and is built last.

2. **Ordering rationale.** Module 02 goes first because submodule 08 deliberately created the
   `Opportunity` record but left its pipeline out (ADR-0012 §1) — it is the only dangling thread and
   the model is already complete. It is also the revenue/forecasting engine a real CRM is built
   around, and once it lands the later modules have a full object set (lead, company, contact,
   **opportunity**) to hang communications and follow-ups on. Modules 03 and 04 are tightly coupled
   (a follow-up *is* a scheduled communication) and are expected to be sequenced back-to-back;
   Satisfaction (05) is an end-of-loop retention layer and comes last.

3. **Spec numbering is a global index, not a submodule id.** `specs/NN-slug.md`: `00` is the shared
   data model, `01`–`09` are module 01's submodules, `10+` is **one file per subsequent module**, with
   that module's submodules as sections inside the file.

4. **"Phase 2" is retired as a name for the other modules.** Dedup/merge (submodule 5) and advanced
   tiers/health remain deferrals *inside* module 01 (ADR-0006) and are still unbuilt.

## Consequences

- `resources/specs/README.md` re-indexes the specs and drops the "Phase 2" wording; `crm-flow.md`
  gets a module roadmap instead of a single "Lead & Client Tracking" group.
- The sidebar groups by module (module 01 keeps its submodule children; module 02 adds a new group).
- This ADR changes only planning/terminology — no code. The pipeline itself is ADR-0015.
