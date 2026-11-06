# 03 — Lead Assignment & Ownership

Submodule 3. Entities: `Lead`, `User`, `Team`, `Activity`. ADR: 0004.

## Purpose

Give every active lead a responsible salesrep, support team-scoped ownership, and provide a shared
unassigned queue.

## Prerequisites

- `teams` table and `users.team_id` / `teams.manager_id` (see `README.md` permissions model).

## Business rules

1. Leads are created unassigned (`owner_id = null`) unless created by a salesrep (self-owned).
2. **Assign** to a single salesrep. Allowed targets:
   - salesrep → self only (can claim from queue).
   - manager → any salesrep **in their team**.
   - admin/superadmin → any salesrep.
3. **Round-robin** (optional) distributes queued leads across a team's salesreps, skipping inactive
   users and balancing by open-lead count.
4. Reassignment records an `Activity` (`type = system`, subject "Owner changed A → B") and notifies
   the new owner.
5. **Ownership transfer rules:** on offline/leave, a manager may bulk-reassign via transfer; history
   is preserved in activities.
6. `GET /leads?filter[owner]=me|unassigned` powers "My Leads" and "Unassigned" views. Unassigned is
   visible to manager+.

## Permissions

| Action | salesrep | manager | admin / superadmin |
| --- | --- | --- | --- |
| Claim from queue (self) | ✓ | ✓ | ✓ |
| Assign to others | — | team | any |
| Bulk assign / transfer | — | team | any |

## API endpoints

| Method | Path | Description |
| --- | --- | --- |
| `POST` | `/api/v1/leads/{id}/assign` | Body `owner_id` |
| `POST` | `/api/v1/leads/{id}/claim` | Assign to the caller |
| `POST` | `/api/v1/leads/bulk-assign` | Body `lead_ids[]`, `owner_id` |
| `POST` | `/api/v1/leads/bulk-transfer` | Manager: reassign all of user A → user B |

## UI

- **Owner control** on lead list (inline) and detail.
- **Bulk action bar** on the `DataTable` (select rows → Assign).
- **Views:** "My Leads" / "Unassigned" / "Team" tabs with counts (`StatCard`, blue = counts).
- **Notifications** for newly assigned owner (`AlertItem`).

## Events

- `LeadAssigned` — notify new owner.

## Acceptance criteria

- [ ] salesrep cannot assign a lead to another user (403).
- [ ] manager can only assign within their team (403 otherwise).
- [ ] Unassigned leads appear in the queue for manager+ and can be claimed by a salesrep.
- [ ] Reassignment writes an activity entry and notifies the new owner.

## Open questions

- Is round-robin needed for Phase 1, or manual assignment only?
- Team membership editable via admin UI in Phase 1, or seeded?
