# 12 — Follow-up Reminder System

Module 4 (ADR-0014). Entities: `Activity` (extended — `due_at`/`status` finally used, plus
`reminded_at` in S3 and `source_key` in S4), `Communication` (the reserved `scheduled` status, plus
`scheduled_for` in S2), `Lead` (`next_follow_up_at` finally maintained), `Company`, `Contact`,
`Opportunity`, `Contract`, `User`. ADRs: 0004, 0005, 0007, 0013, 0014, 0016, 0017, **0018**.

> **Slice S1 built; S2–S4 planned.** This is the contract for module 04, shipped one slice per PR
> (ADR-0016 §2). Its central choice is that **a follow-up is not a new table**: `00-data-model.md`
> already reserved `Activity.due_at`/`status` and `Lead.next_follow_up_at`, ADR-0017 §3 reserved
> `CommunicationStatus.scheduled` for exactly this module, and ADR-0018 §1 settles the pair. Nothing
> runs in the background — due work is pulled on demand, the same stance as the mocked inbound sync
> (ADR-0017 §4).

## Purpose

Keep a rep's appointments honest. A follow-up is a **commitment to do something by a time, on a
record**: call Sunrise back on Tuesday, chase the signed contract by Friday, send the rate sheet
tomorrow morning. Today the CRM can log what was *done* (`Activity`, `Communication`) but nothing
holds what is *owed*; the three columns that were meant to — `activities.due_at`,
`leads.next_follow_up_at`, `communications.status = scheduled` — are unused, and `00-data-model.md`
says so in passing.

This module makes both shapes first class (a task and a scheduled message), gives them **one
worklist** ordered by what is due, keeps each lead's `next_follow_up_at` truthful, notifies the
people who owe something, and generates the reminders nobody thinks to write down — contract renewals
and clients gone quiet.

## What a follow-up is

Two existing records, one list (ADR-0018 §1). Neither is renamed and no table is added.

| Shape | Record | For | Carries |
| --- | --- | --- | --- |
| **Task** | `Activity` with `type = task` | internal work you owe: prepare a quote, chase a signature, call them back | `subject`, `body`, **`due_at`**, `status` (`open`/`done`/`cancelled`), `owner_id`, morphed subject |
| **Scheduled message** | `Communication` with `status = scheduled` | an outbound message that will leave at a time | channel (email only), `subject`, `body`, **`scheduled_for`**, participants, owner, morphed subject |

- A task is **not** a logged touch: it stays `open` until someone completes or cancels it, and it is
  the timeline entry itself — there is no second record behind it.
- A scheduled message **has not happened yet**, so it carries no mirror activity and moves no clock
  (spec 11 §2/§4 extended). Its `occurred_at` is stamped when it actually leaves, and `scheduled_for`
  is kept afterwards, so "due 09:00, sent 11:40" stays readable.
- Only an **outbound email** can be scheduled: email is the one channel the mocked gateway can
  deliver (ADR-0017 §4). A "ring them at 3 pm" is a task, not a scheduled message.
- Both appear in `GET /follow-ups`. `kind` says which shape a row is.

**Due state is computed on read** from `due_at`/`scheduled_for` against the PH day boundary
(`Asia/Manila`, ADR-0007) — `overdue`, `today`, `upcoming` — plus the record's own `open`/`done`/
`cancelled`. Nothing is stored as "overdue", exactly as lead aging and deal staleness are computed
(spec 04 §3, spec 10 §6).

## Submodules & delivery slices

The submodules are facets of one capability, so — as in modules 02 and 03 — they are not one PR each.
The spec declares **four slices** (ADR-0016 §2); each is demoable end to end and leaves `main`
coherent.

| Slice | Branch | Submodule facet | Demo |
| --- | --- | --- | --- |
| **S1** | `feature/reminder-tasks` | Follow-up tasks & the worklist | schedule a call-back on Sunrise → it is in the worklist, overdue tomorrow, then completed |
| **S2** | `feature/scheduled-communications` | Scheduled outbound messages | write an email due tomorrow → worklist → **Send now**, or an admin dispatches what is due |
| **S3** | `feature/reminder-notifications` | Notifications & the due run | assign a follow-up to Liza → her bell; a due run notifies each owner once |
| **S4** | `feature/generated-reminders` | Generated renewal & check-in reminders | generate → a renewal task for the expiring Harbor contract and a check-in for a quiet account |

S1 is the foundation: the task, its state machine and the worklist everything else lands in. S2 adds
the second shape to that list and finally uses the reserved `scheduled` status. S3 makes the list
*reach* people instead of waiting to be opened. S4 fills it from the records themselves — renewals
(which ADR-0013 §3 parked here) and clients gone quiet.

**Not built here:** automatic client aging (ADR-0013 §3 — still deferred, re-pointed by ADR-0018 §6),
recurring/repeating reminders, reminder lead time ("an hour before"), task priority (no column
exists), calendar/ICS export, email/SMS/push notification delivery (in-app only, like module 03's in-app
sync), scheduled **replies** or any non-email scheduling, satisfaction surveys (module 05), analytics
(the AI module).

## API endpoints

Base `/api/v1`; JSON; `Authorization: Bearer <jwt>` (ADR-0008). Standard conventions per
`README.md` — `?page=`, `?per_page=`, `?sort=`, `?filter[…]`, `?search=`.

| Method | Path | Slice | Description |
| --- | --- | --- | --- |
| `GET` | `/follow-ups` | S1 | The worklist — what is owed, ordered by due time (slice S1 lists the tasks; scheduled messages join them in S2) |
| `POST` | `/tasks` | S1 | Create a follow-up task on a record |
| `GET` | `/tasks/{id}` | S1 | One task |
| `PATCH` | `/tasks/{id}` | S1 | Edit it (title, notes, due time, assignee) — a reschedule re-arms the reminder |
| `PATCH` | `/tasks/{id}/status` | S1 | The task state machine (`open` ⇄ `done`/`cancelled`) |
| `POST` | `/communications` | S2 | Accepts `status = scheduled` + `scheduled_for` for an outbound email |
| `GET` | `/communications` | S2 | `filter[due]=overdue\|today\|upcoming` narrows scheduled messages by when they are due |
| `POST` | `/communications/{id}/send` | S2 | **Send now** — the existing endpoint, extended to a scheduled message |
| `POST` | `/communications/dispatch-due` | S2 | **Admin+** — send every scheduled email that has fallen due (the in-app twin of the command) |
| `POST` | `/follow-ups/dispatch-due` | S3 | **Admin+** — notify the owner of every open task that has fallen due |
| `POST` | `/follow-ups/generate` | S4 | **Manager+** — create the reminders the records imply (renewals, check-ins, stale leads) |

Artisan twins, so a demo or an operator never needs the browser: `php artisan communication:send-due`
(S2), `php artisan follow-ups:notify-due` (S3), `php artisan follow-ups:generate` (S4). The existing
`GET /users/assignable` (spec 03) is reused for the assignee picker.

**Not built:** no `DELETE /tasks/{id}` — a task is **cancelled**, never deleted (rule 9). No
`POST /follow-ups` — a follow-up is created as the record it actually is (`/tasks` or
`/communications`), so the worklist stays a read model and there is no polymorphic create.

## Payloads

**Create a task — `POST /tasks`**

```json
{
  "subject_type": "company",
  "subject_id": "01J…",
  "subject": "Send the revised rate sheet",
  "body": "Cover the two supervisor roles we discussed.",
  "due_at": "2026-10-21T09:00:00+08:00",
  "owner_id": 4
}
```

`subject_type` must be one of `lead`/`company`/`contact`/`opportunity`/`contract` and the record must
exist and be visible; `subject` is required (max 255); `due_at` is **required** — a reminder without
a time is not a reminder; `owner_id` defaults to the caller (a salesrep may only name themselves,
managers+ their team — ADR-0004).

**Edit — `PATCH /tasks/{id}`** takes any of `subject`, `body`, `due_at`, `owner_id`. Moving `due_at`
clears the reminder marker so the task can be flagged again when it next falls due (rule 3).

**Transition — `PATCH /tasks/{id}/status`** takes `{ "status": "done" }` and runs
`ActivityStatus::allowedTransitions()`: `open → done|cancelled`, `done|cancelled → open`. A self-move
or `done → cancelled` is **422** (the same shape as every other lifecycle in this CRM).

**The worklist row — `FollowUpResource`**

```json
{
  "kind": "task",
  "id": "01J…",
  "subject": "Send the revised rate sheet",
  "body": "Cover the two supervisor roles we discussed.",
  "record": { "type": "company", "id": "01J…", "label": "Sunrise Foods Inc" },
  "owner": { "id": 4, "name": "Sam Salesrep" },
  "due_at": "2026-10-21T01:00:00.000000Z",
  "due": { "state": "today", "label": "Due today", "days": 0, "overdue": false },
  "status": { "value": "open", "label": "Open" },
  "channel": null,
  "can_update": true,
  "can_transition": true,
  "can_send": false,
  "can_delete": false,
  "created_at": "2026-10-18T02:00:00.000000Z"
}
```

The row keeps the record's own field names (`subject`, `body`), so a task and a scheduled
message read the same in the feed. When the second shape lands (S2) a message row differs by
`kind: "message"`, a `channel`, a `due` measuring its `scheduled_for` and `status.value:
"scheduled"`; S4 adds `generated`. The `can_*` flags are what the row's actions are gated on —
`can_send`/`can_delete` are false for a task, which is cancelled rather than deleted (rule 9).

A `state` filter of `overdue`/`today`/`upcoming` implies `open`; `done`/`cancelled` list
finished tasks, and `all` shows everything.

**`GET /follow-ups`** returns `{ data: […], meta: { current_page, per_page, total, last_page },
counts: { overdue, today, upcoming, open, done } }` with
`filter[owner|state|record_type|record_id|due_from|due_to]` + `search` (subject/body).
`filter[owner]=me` is the shorthand the UI's **Mine** tab uses, `filter[owner]=team` narrows to
one team, and `filter[record_type]=&filter[record_id]=` is the deep link a record's **Follow-up**
action uses (`kind` joins the filter list in S2, when there are two kinds to choose between).
The feed is ordered by `due_at` ascending, then oldest first — what is most owed, first.

**`POST /follow-ups/generate`** returns the run's summary: the tasks it created (as
`FollowUpResource`s) and what it skipped, per source (rule 11).

**`TaskResource`** is the task read on its own (`POST /tasks`, `GET`/`PATCH /tasks/{id}`, the
status move): a single task with the same fields as a worklist row minus `kind`, plus `owner_id`,
`body` and `updated_at`. S4 adds `source_key` to a generated task.

## Business rules

1. **A follow-up has an owner, a due time and a record.** A task is an `Activity` with `type = task`;
   a scheduled message is a `Communication` with `status = scheduled`. Both hang off exactly one
   subject — there are no orphan reminders, the same rule as messages (spec 11 §1).
2. **Both shapes are one list, and one list is what the UI shows.** `GET /follow-ups` merges them by
   due time; nothing about a task or a scheduled message depends on the caller arriving through the
   worklist (each is readable, editable and actionable on its own route).
3. **A reschedule re-arms the reminder.** Changing `due_at` clears `reminded_at`, so a task pushed to
   next week is flagged again when next week comes instead of staying silent forever (S3, rule 10).
4. **A scheduled message has not happened yet.** It writes no mirror activity, moves no
   `last_activity_at` and is excluded from `last_contacted_at` (its `occurred_at` is null until it
   leaves) — the draft rule of spec 11 §2, extended: it *is* editable while `scheduled`, because
   nobody has read it yet. Sending stamps `occurred_at` and keeps `scheduled_for`, so lateness is
   visible rather than erased.
5. **Sending is one path whether it is due now or sent by hand.** `POST /communications/{id}/send`
   sends a `scheduled` message immediately (that is the **Send now** button), and the dispatch calls
   the same `sendThrough()` for everything due — a failure parks the record as `failed` with the
   gateway's reason and takes it out of the worklist, exactly as a rejected draft (spec 11 §S2,
   ADR-0017 §4).
6. **Nothing runs in the background.** There is no scheduler, cron or queue worker: due sends are
   dispatched by an admin in-app or by the artisan twin, and due reminders are raised the same way.
   The worklist already *shows* what is overdue — the dispatch exists to act, not to compute
   (ADR-0018 §4).
7. **Due state is derived, never stored.** `overdue`/`today`/`upcoming` are computed from
   `due_at`/`scheduled_for` and the `Asia/Manila` day, so thresholds and the clock can be reasoned
   about and tested without a migration (spec 04 §3 precedent).
8. **A follow-up is seen with its record, and owes to one person.** Visibility follows the subject
   (ADR-0004): a salesrep sees follow-ups on their own records, a manager the team's, an admin all.
   Assignment is the lead-assignment rule (spec 03): a salesrep may only owe themselves, a manager
   may assign within their team, an admin anyone — the picker offers **you** plus `GET
   /users/assignable` (spec 03 §2), so a manager's own tasks are created by defaulting rather than by
   picking another manager.
9. **Tasks are cancelled, never deleted.** `activities` hold no soft delete and a quick log is a
   record of what happened; a task you no longer owe is `cancelled` (reopenable), which keeps the
   audit trail honest. This also means no restore endpoint and no `DELETE /tasks/{id}`.
10. **Notifications are per item and fire once.** Assignment notifies the new owner; the due run
    notifies the owner of each open task that has fallen due whose `reminded_at` is still null, then
    stamps it — a second run of the same day adds nothing. A failed send notifies the message's author
    (they are probably not watching a scheduled dispatch).
11. **Generated reminders are idempotent, and never invent an owner.** Each generated task carries a
    `source_key` — `renewal:{contract}`, `check_in:{company}:{ISO-week}`,
    `stale_lead:{lead}:{ISO-week}` — and the generator only adds a task where no live one holds that
    key, reporting `created` and `skipped` per source. The week bucket is what lets next month's
    check-in exist at all. A record with no owner is **skipped and reported**, not assigned to
    whoever happened to run it.
12. **`Lead.next_follow_up_at` is that lead's earliest open follow-up** — the sooner of its open
    tasks and its scheduled messages — recomputed on every create, reschedule, transition and send
    that touches a lead, and null when nothing is open. Slice S1 maintains it from the tasks (the
    only shape that exists yet); S2 takes the sooner of the two. The lead list and the stale/at-risk
    views can then read one column instead of joining two tables.
13. **A task is not a communication and a communication is not a task.** The two records stay
    distinct (ADR-0017 §1): a task has no channel, direction or participants, and a message has no
    completion. Completing a task does not write a communication, and sending a message does not
    complete a task.

## Permissions

| Action | salesrep | manager | admin / superadmin |
| --- | --- | --- | --- |
| The worklist, a task, a scheduled message | Record visible | Record visible | All |
| Create a task on a record | Record updatable | Record updatable | ✓ |
| Set the assignee | Self only | Their team | Anyone |
| Edit / reschedule a task | Own only | Team | All |
| Complete / cancel / reopen | Own only | Team | All |
| Schedule a message / **Send now** | Subject updatable | Subject updatable | ✓ |
| Dispatch due sends (S2) | — | — | ✓ |
| Dispatch due reminders (S3) | — | — | ✓ |
| Generate reminders (S4) | — | ✓ | ✓ |

"Record visible / updatable" reuses the subject's own policy (ADR-0004, ADR-0011 §2, ADR-0012 §4):
a salesrep's lead, account, contact or deal is theirs; a manager covers the team; an admin everything.

## UI

**S1 — follow-up tasks & the worklist**

- `/follow-ups` — the worklist: four `StatCard`s (Overdue, Due today, Upcoming, Completed)
  over a `DataTable` whose rows carry a state badge (Overdue / Due today / Upcoming / Done /
  Cancelled), a kind badge (Task / Email — one kind until S2 adds the other), the subject with its
  record as a deep link, the owner and the due moment in PH time. Filters: **Everyone / Mine / My
  team**, state, record type and `search`; a `?subject_type=&subject_id=` deep link narrows it to one
  record. The **Dispatch due** action joins the header in S3, with the simulated-clock note.
- Row actions per the API's `can_*`: **Complete**, **Reschedule** (a small due-date dialog →
  `PATCH due_at`), **Cancel**, **Reopen**. Inline, no page reload.
- `/follow-ups/new` — the create form: record picker (prefilled from the deep link), title, notes,
  due date **and time** (defaulted to tomorrow 09:00 PH), assignee (defaults to you; manager+ can
  pick a team member). Submits to `POST /tasks`.
- `/follow-ups/:id` — the same form for editing a task, plus the status actions.
- A **Follow-up** quick action on the lead, account, deal and contract detail pages, deep-linking to
  `/follow-ups/new?subject_type=…&subject_id=…` — the same pattern the composer uses.
- The three record timelines render a task with its due state (and a **late** mark once overdue);
  `ActivityResource` gains `due_at`, `status` and `is_follow_up` so existing panels need no rework.
- The lead list gains a **Next follow-up** column from `next_follow_up_at` (rule 12).
- Sidebar: module 04 group `Follow-up Reminder System` → `Follow-up Reminders` (`/follow-ups`).

**S2 — scheduled outbound messages**

- The composer (`/communications/new`) gains **Schedule for later**: offered only for an outbound
  email, revealing a due date + time; saving posts `status = scheduled` (the channel select keeps the
  draft/logged options unchanged for everything else).
- The worklist lists scheduled messages as its `message` rows with **Send now**, **Edit** and
  **Cancel** (soft delete — spec 11 §4), and the row names the recipient.
- The message detail shows a **Scheduled for …** banner with **Send now**, and after a send the
  banner adds when it actually left if that was later.
- The communications book's status filter carries the `scheduled` state, and a **Due** filter
  (`overdue`/`today`/`upcoming`) narrows it — the book stays the place a scheduled message is found
  by record, the worklist the place it is actioned.

**S3 — notifications & the due run**

- The topbar bell lists **follow-up assigned** ("Liza asked you to …" / "Assigned to you: …") and
  **follow-up due** ("Overdue: Send the revised rate sheet") notifications, each linking to the item,
  using the existing notification component.
- The worklist header carries **Dispatch due** (admin+) which runs the same work as
  `follow-ups:notify-due` and reports how many owners were notified; a failed **scheduled** send
  notifies its author in the same list.

**S4 — generated reminders**

- The worklist header carries **Generate reminders** (manager+), which runs the generator and shows
  its summary grouped by source (Renewals / Check-ins / Stale leads) with what was created and what
  was skipped — the skipped list naming the reason, so "no owner" is visible rather than silent.
- Generated rows are marked (a **Generated** badge from `source_key`) so a rep can tell the house's
  reminders from their own.

## Events

| Event | Emitted by | Effect |
| --- | --- | --- |
| `FollowUpAssigned` | creating a task, reassigning one, assigning a scheduled message | notifies the new owner (S3) |
| `FollowUpDue` | the due run (`POST /follow-ups/dispatch-due`, `follow-ups:notify-due`) | notifies the owner and stamps `reminded_at` (S3) |

Sending and failing a scheduled message reuse module 03's `CommunicationSent` / `CommunicationFailed`;
S3 adds the author notification on the failed path. The generator reuses `FollowUpAssigned` per task it
creates, so a generated reminder reaches its owner exactly like a hand-written one.

## Demo data

- **S1** seeds half a dozen tasks across every state so the worklist needs no setup: an **overdue**
  "Send the revised rate sheet" on Sunrise Foods (Sam), a **due today** "Confirm the 40-seat start
  date" on the BPO deal (Liza), an **upcoming** "Chase the signed contract" on BPO Solutions (Sam),
  a completed one and a cancelled one on Sunrise, and one on the **Harbor Logistics lead** ("Confirm
  the forklift operator shortlist with Dan", Liza) so `next_follow_up_at` shows up in the lead list
  and the stale view. Two owners is deliberate: the **Mine / My team** tabs and the manager's per-rep
  filter both demo without setup.
- **S2** seeds two scheduled emails: one due tomorrow on Sunrise (Sam) and one **overdue** on BPO
  Solutions (Liza) — so **Send now** demos on one and an admin **Dispatch due** sends the other
  through the mocked gateway live.
- **S3** needs no seed beyond S1's: the seeded task assigned to Liza is created (and dispatched) the
  way the API does it, so the bell has a real assignment to show; the due run is then triggered from
  the worklist during a demo.
- **S4** seeds nothing of its own: it works off the data already there — the seeded Harbor Logistics
  renewal chain (a contract inside `crm.contracts.expiring_soon_days`), a seeded account that has
  gone quiet (Greenfield Hotels stays silent through module 03's seed) and a stale lead. The seeder
  keeps at least one contract expiring inside the window so the renewal source demos on a fresh
  database.

## Acceptance criteria

**S1**

- [ ] A task can be created on any of the five subject types with a required due time; an unknown
      subject, a missing due time or a subject outside the caller's scope is 422/403.
- [ ] `PATCH /tasks/{id}/status` follows `ActivityStatus::allowedTransitions()` — `open → done`,
      `open → cancelled`, `done`/`cancelled → open`; a self-move, `done → cancelled` and an unknown
      status are 422.
- [ ] A salesrep may create and complete only their own; a manager the team's; an admin anyone's, and
      a salesrep cannot assign a task to another user (422/403).
- [ ] `GET /follow-ups` lists what is owed, ordered by due time, honours
      `filter[owner|state|record_type|record_id|due_from|due_to]` + `search`, and reports the
      counts block. (S2 adds the second shape and with it `filter[kind]`.)
- [ ] `overdue` / `today` / `upcoming` are computed from the PH day boundary and change with the
      clock, not with a stored column.
- [ ] A task appears in its record's timeline with its due state, and `ActivityResource` marks it.
- [ ] `Lead.next_follow_up_at` tracks the earliest open follow-up on a lead — from the tasks in S1,
      the sooner of tasks and scheduled messages once S2 lands — and is null once none is open.
- [ ] No delete endpoint exists; a task is cancelled and can be reopened.

**S2**

- [ ] Creating a communication with `status = scheduled` requires an outbound email, a
      `scheduled_for`, a subject and a recipient; another channel, an inbound direction or a missing
      `scheduled_for` is 422.
- [ ] A scheduled message writes no mirror activity and leaves `last_activity_at` /
      `last_contacted_at` untouched, and it is editable while scheduled.
- [ ] `POST /communications/{id}/send` sends a scheduled message immediately, stamping `occurred_at`
      and keeping `scheduled_for`; a gateway failure parks it as `failed` with the reason.
- [ ] `POST /communications/dispatch-due` (admin+) and `communication:send-due` send every scheduled
      email that is due, once: a second run sends nothing, and a scheduled message not yet due is left
      alone.
- [ ] `filter[due]=overdue|today|upcoming` narrows the book to scheduled messages by when they are due,
      and the worklist lists them with **Send now**.
- [ ] A salesrep cannot dispatch due sends (403); sending a message follows the subject's own rights.

**S3**

- [ ] Assigning a task (or a scheduled message) to another user notifies that user once, and assigning
      it to yourself does not.
- [ ] The due run notifies the owner of each open task that has fallen due and stamps `reminded_at`; a
      second run adds nothing, and a task that falls due later is picked up by the next run.
- [ ] Rescheduling a task clears `reminded_at`, so it is flagged again when it next falls due.
- [ ] A cancelled or completed task is never flagged by the due run.
- [ ] A failed scheduled send notifies its author, naming the gateway's reason.
- [ ] Only admin+ may dispatch (403 otherwise), and both the endpoint and `follow-ups:notify-due`
      return the number notified.

**S4**

- [ ] `POST /follow-ups/generate` (manager+) creates a renewal task for each signed contract expiring
      within `crm.contracts.expiring_soon_days`, a check-in task for each client whose last activity is
      older than `crm.follow_ups.check_in_days`, and a task for each stale lead
      (`crm.aging.stale_days`), each assigned to the record's own owner.
- [ ] A second run creates nothing (idempotent by `source_key`); a run in the next ISO week may create
      the next check-in.
- [ ] Each created task is reported with its source, and a record with no owner is skipped with a
      reason instead of being assigned to the caller.
- [ ] Generated tasks are notified to their new owners and marked as generated in the worklist.
- [ ] A salesrep cannot generate (403).

## Open questions

- **Recurring reminders** (a weekly check-in that re-arms itself) are deferred; the ISO-week
  `source_key` bucket is the interim answer for the generated ones, and there is no recurrence on a
  hand-written task.
- **Reminder lead time** — the due run flags a task once it is due, not "an hour before"; a
  `remind_at` column would be the seam if a client asks.
- **Task priority / effort** — no column exists and none is added; `00-data-model.md` has no
  `Activity` priority, and adding one is a data-model change of its own.
- **Notification delivery** is in-app only (the database notifications module 03 introduced): no mail
  or push, and no unread digest. A real deployment would add a channel, not a new concept.
- **Calendar/ICS export** and **mobile** are out of scope.
- **Automatic client aging** (ADR-0013 §3) is *not* part of this module; ADR-0018 §6 keeps it a
  standalone deferral rather than an implied follow-up feature.
- **A task that survives its record** — a task is visible with its subject, so retiring a record hides
  its open tasks from the worklist too. Worth revisiting only if a record type becomes deletable in
  bulk.
