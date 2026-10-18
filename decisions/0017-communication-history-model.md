# 0017 — Communication history model (module 03)

- Status: Accepted
- Date: 2026-10-18

## Context

Module 03 (Communication History Management) is the next module (ADR-0014 §1) and the first that has to
sit *alongside* something module 01 already built. `specs/00-data-model.md` reserved the shape early —
"Full **communication history** (rich email/phone bodies) is module 03 with its own `communications`
table" — and spec 04 §Implementation notes admits that `POST /leads/{id}/activities` is "a minimal
slice of the deferred communication-history module".

So there are two records that can hold a touch (`Activity`, `Communication`) and three timelines that
already render touches (`/leads/{id}/timeline`, `/companies/{id}/activities`,
`/opportunities/{id}/timeline`). Module 03 also has to decide how far email goes: the CRM is a
capstone running on a local Postgres with no mail infrastructure, and the project fakes external
systems behind an interface + mock driver rather than pretending (ADR-0005, ADR-0013 §4).

Module 04 (follow-up reminders) is sequenced immediately after and will need to *schedule* a
communication, so the model has to leave room for that without building it.

## Decision

1. **`Activity` stays the lightweight quick-log; `Communication` is the detailed record.** An activity
   is a touch or a task (call/email/meeting/note/task, `subject` + `body`, drives `last_activity_at`
   and aging). A communication is channel-aware and detail-rich: direction, participants, body,
   duration, outcome, external message id, attachments. Both exist; neither is renamed.
2. **A logged communication also writes an `Activity`** — the convention already used by
   `LeadStatusChanged` and `OpportunityStageChanged`. This keeps the three existing timelines correct
   with **no change to their endpoints**, and `activities.communication_id` (nullable) links the mirror
   back to the detail record. The mirror carries a short `subject` (e.g. "Email sent — revised rate
   card") and no body copy, so the body is stored once and the timeline links through to it.
3. **Channels and direction are enums.** `CommunicationChannel`: `email`, `call`, `sms`, `whatsapp`,
   `meeting`, `letter`, `other`. `CommunicationDirection`: `inbound`, `outbound`.
   `CommunicationStatus`: `draft`, `scheduled`, `sent`, `received`, `failed`, `logged` — where `logged`
   is a manually recorded offline touch (a call that happened in person), `sent`/`received`/`failed`
   are electronic outcomes, and `scheduled` is **reserved for module 04** and not settable in module 03.
4. **Email is a mocked integration, following the existing pattern.** An `EmailGateway` interface plus
   a deterministic `MockEmailGateway` in `backend/app/Integrations/` (the one approved base folder),
   bound in `AppServiceProvider`, with `CRM_EMAIL_SIMULATE_FAILURE=true` to force the failure path the
   way `CRM_HANDOFF_SIMULATE_FAILURE` forces the Service handoff. No SMTP, no IMAP, no mailbox sync:
   inbound mail is **logged**, and the demo simulates a reply through the gateway rather than reading a
   real inbox.
5. **Scoping is inherited from the subject.** A communication is visible if and only if its subject
   (lead / company / contact / opportunity / contract) is visible to the caller, and logging one
   requires the same right as updating that subject. Contacts inherit their account's rights
   (ADR-0011 §2); deals are own/team/all (ADR-0012 §4). There is no separate communication-sharing
   model, because a message about a record must never outlive the record's own visibility.
6. **Sent and received communications are immutable.** Only `draft`/`logged` records can be
   edited — by their author, or by a manager+ within the record's scope, and an attempt to edit
   a delivered one is a **422** (a state error, not a permission error). Deletion is a soft
   delete scoped the same way, with restore for manager+. A record of what the company was told
   is not editable history.
7. **The overlap with quick-log is deliberate and is labelled in the UI.** Timelines stay the merged
   feed; the two entry points are named for what they are ("Quick log" writes an activity, "Log
   communication" writes a communication). Merging them would mean rewriting module 01/02 timeline
   endpoints and their tests for no user-visible gain.
8. **Attachments arrive as their own slice**, implementing the polymorphic `attachments` table that
   `specs/00-data-model.md` deferred; until then a communication has no files and the detail screen says
   nothing about them.
9. **Threading arrives as its own slice** (`thread_id` + `in_reply_to` on the communication), because a
   flat merged feed is genuinely useful on its own and grouping is a display concern over the same rows.

## Consequences

- Two rows per logged communication (the communication plus its mirror activity). The mirror is a
  pointer, not a copy, so there is no content duplication; the cost is one extra insert and the rule
  that the mirror must not be deleted independently.
- Module 03 needs no change to `/leads/{id}/timeline`, `/companies/{id}/activities` or
  `/opportunities/{id}/timeline` — they keep working, and merged history keeps showing up in the aging /
  staleness calculations that read `last_activity_at`.
- Email is honest rather than simulated-in-name: the mock is visible in the payload (`meta.source`), the
  failure path is forced by config, and no user is told a message left the building when it did not.
- `scheduled` sits unused until module 04, which is the intended seam — follow-ups will create
  communications with a due time rather than inventing a second scheduling table.
- The `attachments` slice is the first thing in the project to need several files on a record, so it
  unblocks that deferral for contracts and contacts too.
- Module 03 ships as several slices rather than one PR (ADR-0016), which is the point: the log/history
  slice is demoable before email sending exists, and the delivery order is declared in spec 11.
- S1 and S2 are built. S2 settled three seams the decision left open: `draft` is settable **only** for an
  outbound email (it is the one record that is written and sent later) and it carries no timeline mirror
  until it goes out; sending stamps `occurred_at` so the timeline records the send, not the writing; and
  the mocked driver derives every id from the message reference, which is what makes a repeated inbound
  sync idempotent instead of duplicating the same reply.
