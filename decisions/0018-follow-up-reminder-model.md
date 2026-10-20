# 0018 — Follow-up reminder conventions

- Status: Accepted
- Date: 2026-10-19

## Context

Module 04 (Follow-up Reminder System, `specs/12`) is next. Three seams were left for it in earlier
work, and they do not agree on what a "follow-up" is:

- `00-data-model.md` gave `Activity` a `due_at` and an `open`/`done`/`cancelled` status
  ("tasks/reminders") and gave `Lead` a `next_follow_up_at` "used by follow-up module later".
- ADR-0017 §3 reserved `CommunicationStatus.scheduled` and said "follow-ups will create
  communications with a due time rather than inventing a second scheduling table".
- ADR-0013 §3 parked two deferrals here: contract **renewal reminders** and **automatic client
  aging** ("the thresholds return with the deferred follow-up module").

Nothing runs on a schedule in this capstone — module 03's inbound email is *pulled* by a command, never
pushed (ADR-0017 §4) — and the CRM has no queue worker, cron or scheduler.

## Decision

1. **A follow-up is one of two existing records, not a new table.** An internal to-do is an `Activity`
   with `type = task`, a `due_at` and `open`/`done`/`cancelled`; an outbound message that will leave
   later is a `Communication` with `status = scheduled` and a `scheduled_for`. `GET /follow-ups` is a
   read-only union of the two, ordered by what is due — one worklist, two record types. This honours
   both reserved seams and keeps `Activity` a quick log rather than a task manager with a second
   table behind it.
2. **Tasks are cancelled, never deleted.** `activities` keep no soft delete, a quick log is a record
   of what happened, and a reminder you no longer owe is a state, not a deletion. There is no
   `DELETE /tasks/{id}` and no restore.
3. **`scheduled` means an outbound email with a time, and it has not happened.** Only email, because
   the mocked gateway is the only channel that can send; a "call them at 3pm" is a task. A scheduled
   message writes no mirror activity and moves no clock, `occurred_at` becomes nullable and is stamped
   when it actually leaves, and `scheduled_for` is kept afterwards so lateness is visible.
4. **Nothing runs in the background — due work is pulled.** Due sends are dispatched by an admin
   in-app (`POST /communications/dispatch-due`) or by `communication:send-due`; due reminders the same
   way (`POST /follow-ups/dispatch-due`, `follow-ups:notify-due`). Repeated runs are idempotent by
   construction: a send only moves rows out of `scheduled`, and a reminder stamps `reminded_at` (which
   a reschedule clears, so the next due time raises it again).
5. **Due state is computed on read** from `due_at`/`scheduled_for` and the `Asia/Manila` day — no
   "overdue" column, the same stance as lead aging (ADR-0009) and deal staleness (ADR-0015 §6).
6. **Automatic client aging stays deferred.** ADR-0013 §3 attached it to this module; on planning it
   is not a reminder at all — it advances the client lifecycle (`active → at_risk → dormant`) and was
   deliberately left out of module 04. It remains a standalone deferral (ADR-0006) and this ADR
   re-points ADR-0013 §3's assumption. Renewal reminders *are* built here, as that ADR intended (S4).
7. **Generated reminders are keyed and idempotent.** Each carries an `activities.source_key`
   (`renewal:{contract}`, `check_in:{company}:{ISO-week}`, `stale_lead:{lead}:{ISO-week}`); the
   generator adds a task only where no live one holds that key, and reports created/skipped per
   source. The column is a **nullable unique index**, so two concurrent runs cannot both win the same
   key — a `check_in` key is unique for the life of the row, and the week bucket is what lets the next
   week's check-in exist at all. It never invents an owner — a record with none is skipped and
   reported — and it does not pile onto work already in hand: a record somebody has an **open
   hand-written task or scheduled message** on is skipped too (its own generated tasks never block
   the next week, the key governs those). "Quiet" for a check-in means **no `Communication`** since
   the client came aboard — not no `Activity`, which every seeded account would have — measured from
   `client_since` for an account never contacted, and **churned clients are included**, because an
   account gone silent is the one worth a win-back call.
8. **Assignment and visibility follow ADR-0004**, exactly like lead assignment (spec 03): a salesrep
   may only owe themselves, a manager may assign within the team, an admin anyone; the worklist shows
   what the record's own policy allows.

## Consequences

- `activities` gains `reminded_at` (S3) and `source_key` (S4) plus an index for the worklist query;
  `communications` gains `scheduled_for` and a nullable `occurred_at` (S2). No new table.
- `Lead.next_follow_up_at` is finally maintained (earliest open task or scheduled message) and
  `ActivityStatus` gets the transitions the endpoint runs, alongside the other lifecycles.
- The AI module inherits a real commitments feed (what is owed, late, by whom) instead of inferring it
  from timestamps.
- Two records now carry a due time; the rule that keeps them apart is that a task has no channel and a
  message has no completion (spec 12 §13).
- Automatic aging is explicitly *not* implied by "follow-up module" any more, so a future reader does
  not assume module 04 shipped it.
