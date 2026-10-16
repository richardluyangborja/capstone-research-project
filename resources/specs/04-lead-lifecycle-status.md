# 04 — Lead Lifecycle & Status

Submodule 4. Entities: `Lead`, `Activity`. ADRs: 0002, 0003.

## Purpose

Govern legal status transitions (a state machine), keep status current from activity, and flag/age
stale leads for triage.

## State machine

```
new ──► contacted ──► qualified ──► converted (terminal)
 │          │              │
 │          ├──► nurture ──┘ (re-qualify)
 │          │      │
 │          │      └──► contacted (re-engage)
 │          ├──► disqualified ──► contacted (re-open)
 │          └──► archived
 └──► disqualified / archived
nurture ──► qualified / contacted / archived
```

Allowed transitions (reject others with 422):

| From | To |
| --- | --- |
| `new` | `contacted`, `disqualified`, `archived` |
| `contacted` | `qualified`, `disqualified`, `nurture`, `archived` |
| `qualified` | `converted`, `disqualified`, `nurture` |
| `nurture` | `contacted`, `qualified`, `archived` |
| `disqualified` | `contacted`, `archived` |
| `converted` | *(none — terminal)* |
| `archived` | `new` (restore via soft-delete endpoint instead) |

## Business rules

1. Every status change sets `status_changed_at` and records an `Activity` (`type = system`,
   `from → to`, actor).
2. **Automatic updates:**
   - A logged outbound activity to a `new` lead sets `contacted` and updates `last_activity_at`.
   - A successful conversion sets `converted` (submodule 8).
   - `converted` is terminal; the lead becomes read-only.
3. **Aging / stale flag** (thresholds configurable, propose 14 / 30 / 60): a non-terminal,
   non-archived lead with no activity for 14 days is flagged **stale**; 30 days → **at risk**;
   60 days → suggested archive. Computed (not just stored) and listed via `?filter[stale]=true`.
4. **Archiving** is a status (kept, not deleted); `archived` leads are excluded from default lists.
5. Re-opening a `disqualified` lead requires `contacted` as the next state (forces re-engagement).

## Permissions

Ownership rules as before. salesrep may transition own leads; manager+ within scope.

## API endpoints

| Method | Path | Description |
| --- | --- | --- |
| `PATCH` | `/api/v1/leads/{id}/status` | Body `status`; validates transition |
| `POST` | `/api/v1/leads/{id}/archive` | Convenience → `archived` |
| `GET` | `/api/v1/leads?filter[stale]=true` | Stale/at-risk leads |
| `GET` | `/api/v1/leads/{id}/timeline` | Activities + status history |

## UI

- **Status control** on detail — only legal target states shown (dropdown/stepper).
- **Stale badges** (`StatusBadge`, orange/red per mapping) on list and detail.
- **Timeline** on `/leads/:id` — chronological status changes + activities (purple = activity).

## Acceptance criteria

- [ ] Illegal transitions return 422 and do not change state.
- [ ] Logging a first outbound activity moves `new` → `contacted`.
- [ ] Converted leads reject further status changes (read-only).
- [ ] `?filter[stale]=true` returns leads past the configured inactivity threshold.
- [ ] Archiving hides the lead from default lists without deleting it.

## Open questions

- Confirm aging thresholds (14/30/60) and whether they are fixed or per-tenant config.
- Should status changes require a reason (e.g., disqualification already does)?
