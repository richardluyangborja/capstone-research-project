# 02 — Lead Qualification & Scoring

Submodule 2. Entities: `Lead`. ADRs: 0003.

## Purpose

Assess whether a lead is worth pursuing using a staffing-specific checklist, assign a
Hot/Warm/Cold rating, and record disqualification/nurture outcomes.

## Scope

- **In:** qualification fields, checklist completeness, rating (hot/warm/cold), optional score,
  disqualify (with reason), mark for nurture.
- **Out:** automated/ML scoring (AI service, later), BANT.

## Business rules

1. **Checklist fields** (see data model): `estimated_headcount`, `needed_roles`,
   `target_start_date`, `budget_rate`, `authority_confirmed`, `work_site`, `urgency`.
2. A lead may be marked `qualified` only when the **minimum criteria** are met:
   `estimated_headcount` set **and** `needed_roles` set **and** (`contact_email` or
   `contact_phone`). `authority_confirmed` and `work_site` are recommended, not required.
3. `rating` is set/derived: explicit Hot/Warm/Cold. Default `score` (optional) =
   weighted checklist completeness (document weights when implemented); store both.
4. **Disqualify** requires `disqualification_reason`; sets `status = disqualified`.
5. **Nurture** sets `status = nurture` (revisit later) — does not require a reason.
6. Qualification edits are allowed while `status ∈ {new, contacted, qualified, nurture}`; once
   `disqualified`/`converted`, fields are read-only unless the lead is re-opened (submodule 4).
7. `budget_rate` is sensitive and stripped from salesrep responses (ADR-0004).

## Permissions

Same ownership rules as submodule 1. `manager`+ may qualify any record in scope.

## API endpoints

| Method | Path | Description |
| --- | --- | --- |
| `PATCH` | `/api/v1/leads/{id}/qualification` | Update checklist fields |
| `POST` | `/api/v1/leads/{id}/rate` | Set rating (`hot|warm|cold`) and/or score |
| `POST` | `/api/v1/leads/{id}/qualify` | Validate minimum criteria → `qualified` |
| `POST` | `/api/v1/leads/{id}/disqualify` | Body: `reason`, `notes?` → `disqualified` |
| `POST` | `/api/v1/leads/{id}/nurture` | → `nurture` |

`POST /leads/{id}/qualify` returns 422 with the missing criteria when the minimum is not met.

## UI

- **Qualification panel** on `/leads/:id` — checklist inputs in a `FormCard`, completeness
  indicator, rating `StatusBadge` (hot=orange, warm=amber, cold=blue/grey per token mapping).
- **Disqualify** modal — requires reason; shows in activity timeline.

## Events

- `LeadQualified` — lead becomes eligible for conversion (submodule 8).

## Acceptance criteria

- [ ] `/qualify` fails with a clear 422 when minimum criteria are missing.
- [ ] Disqualifying without a reason fails validation.
- [ ] Rating persists and is filterable in the list endpoint.
- [ ] `budget_rate` is absent from salesrep responses but present for manager+.

## Open questions

- Exact score weights (or leave score unused for Phase 1)?
- Should the recommended fields be surfaced as a completeness percentage?
