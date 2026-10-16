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

- [x] Illegal transitions return 422 and do not change state.
- [x] Logging a first outbound activity moves `new` → `contacted`.
- [x] Converted leads reject further status changes (read-only).
- [x] `?filter[stale]=true` returns leads past the configured inactivity threshold.
- [x] Archiving hides the lead from default lists without deleting it.

## Open questions

**Resolved** (see ADR-0009):

- Aging thresholds are **configurable** — `backend/config/crm.php`, defaults 14 / 30 / 60
  (`CRM_STALE_DAYS` / `CRM_AT_RISK_DAYS` / `CRM_ARCHIVE_DAYS`). Computed on read.
- Status changes take an **optional note**; only disqualification requires text (`reason`).

## Implementation notes

- Transitions are applied by `Lead::transitionTo()`: it validates against the table above, stamps
  `status_changed_at`, refreshes `last_activity_at`, and dispatches `LeadStatusChanged`, whose
  listener writes the `system` activity.
- `PATCH /leads/{id}/status` cannot bypass submodule 2 — qualifying without the minimum criteria and
  disqualifying without a reason both return 422. `POST /leads/{id}/qualify|disqualify|nurture`
  remain as wrappers over the same transition.
- `POST /leads/{id}/activities` logs an activity; contact types (`call`, `email`, `meeting`,
  `walk_in`) advance a `new` lead to `contacted`. This is a minimal slice of the deferred
  communication-history module, added only to satisfy the rule above.
- `converted` is not offered by `PATCH /status` — lead→client conversion (submodule 8) owns it.
- The submodule-3 `GET /leads/{id}/activities` endpoint was replaced by
  `GET /leads/{id}/timeline` (same rows, plus a `category` and status/aging `meta`).
- `archived` leads are excluded from the list, summary counts and report views unless
  `filter[status]=archived` (or `filter[archived]=true`) is passed; `summary` gained an `archived`
  count.

