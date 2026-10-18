# 11 — Communication History Management

Module 3 (ADR-0014). Entities: `Communication` (new), `Activity`, `Lead`, `Company`, `Contact`,
`Opportunity`, `Contract`, `User`. ADRs: 0004, 0005, 0007, 0011, 0013, 0016, 0017.

> **Planned — not yet implemented.** This is the contract for module 03. It adds the `communications`
> table that `00-data-model.md` reserved, keeps `Activity` as the lightweight quick-log (ADR-0017 §1),
> and ships in five declared slices (ADR-0016 §2). Email is a **mocked** integration (ADR-0017 §4) —
> nothing leaves the machine.

## Purpose

Give every record a truthful, channel-aware history of what was said: log and read emails, calls,
messages and meetings against a lead, account, contact, deal or contract; search and filter the
history; and (from slice 2) compose, send and retry an email through the mocked gateway — so a rep can
see "what did we last tell BPO Solutions?", and a manager can read the trail behind a deal.

## Channels & records

Two records hold a touch, and they are deliberately different (ADR-0017 §1):

| Record | For | Carries |
| --- | --- | --- |
| `Activity` | the quick log: a call/email/meeting/note/task, or a system event | `type`, `subject`, short `body`, `status`, `occurred_at`, owner, morphed subject |
| `Communication` | the detailed history entry | channel, direction, participants, body, duration, outcome, external message id, attachments |

A logged communication **also writes an activity** (ADR-0017 §2) so the three existing timelines
(`/leads/{id}/timeline`, `/companies/{id}/activities`, `/opportunities/{id}/timeline`) stay the merged
feed with no endpoint change; the mirror is a pointer (`activities.communication_id`), not a copy.

Channel → mirror activity type: `email`→`email`, `call`→`call`, `meeting`→`meeting`,
`sms`/`whatsapp`/`letter`/`other`→`note`.

## Submodules & delivery slices

The submodules are facets of one capability, so — unlike module 01 — they are **not** one PR each. The
spec declares five slices (ADR-0016 §2); each is demoable end to end and leaves `main` coherent.

| Slice | Branch | Submodule facet | Demo |
| --- | --- | --- | --- |
| **S1** | `feature/communication-log` | Communication log & history | log a call on a deal → read it in the book and on the deal timeline |
| **S2** | `feature/communication-email` | Email compose, send & retry (mocked gateway) | compose → send → status `sent` with the mocked source, force a failure → retry |
| **S3** | `feature/communication-attachments` | Attachments on a communication | attach a PDF to an email, download it through the authorized endpoint |
| **S4** | `feature/communication-threads` | Threading (conversations) | reply into a thread, see the conversation grouped |
| **S5** *(candidate)* | `feature/communication-templates` | Reusable templates + merge fields | quick-log from a template rendered with the contact's details |

S1 is the foundation everything else attaches to. S2 before S3/S4 because sending is what makes a
history worth reading; S5 only if the two write paths prove tedious in the demo.

**Not built here:** real mail transport/inbound sync, open/click tracking, VoIP/CTI and call recording,
campaigns, satisfaction surveys (module 05), reminder scheduling (module 04) and analytics (the AI
module).

## API endpoints

Static paths precede resource routes (project convention, `routes/api.php`). Each slice adds its own.

**S1 — log & history**

| Method | Path | Description |
| --- | --- | --- |
| `GET` | `/api/v1/communications` | History book — `filter[channel\|direction\|status\|subject_type\|subject_id\|contact_id\|company_id\|owner\|date_from\|date_to]` + `search`, paginated, `meta` summary |
| `POST` | `/api/v1/communications` | Log a communication against a subject |
| `GET` | `/api/v1/communications/{id}` | Detail (participants, body, linked record) |
| `PATCH` | `/api/v1/communications/{id}` | Edit a `draft`/`logged` record — author only |
| `DELETE` | `/api/v1/communications/{id}` | Soft delete |
| `POST` | `/api/v1/communications/{id}/restore` | Restore — manager+ |
| `GET` | `/leads/{id}/communications` | Nested history for a lead |
| `GET` | `/companies/{id}/communications` | Nested history for an account |
| `GET` | `/contacts/{id}/communications` | Nested history for a person |
| `GET` | `/opportunities/{id}/communications` | Nested history for a deal |
| `GET` | `/contracts/{id}/communications` | Nested history for a contract |

**S2 — email (mocked gateway)**

| Method | Path | Description |
| --- | --- | --- |
| `POST` | `/api/v1/communications/{id}/send` | Send a `draft`; 200 `sent`, 422 if not a draft, `failed` + `failed_reason` when the gateway is forced to fail |
| `POST` | `/api/v1/communications/{id}/retry` | Re-send a `failed` message |

Inbound is pulled, not pushed: `php artisan communication:sync-inbound` asks the mocked gateway for
"received" messages and records them. No mailbox connection and no scheduler — nothing runs on a timer
in this phase (scheduled work is module 04).

**S3 — attachments** *(implements the deferred polymorphic table)*

| Method | Path | Description |
| --- | --- | --- |
| `POST` | `/api/v1/communications/{id}/attachments` | Multipart upload — pdf/png/jpeg, ≤10 MB, private disk |
| `GET` | `/api/v1/attachments/{id}/file` | Authorized download |
| `DELETE` | `/api/v1/attachments/{id}` | Remove — author or manager+ |

**S4 — threads**

| Method | Path | Description |
| --- | --- | --- |
| `GET` | `/api/v1/communications/threads` | Threads the caller may see (subject, message count, last at) |
| `GET` | `/api/v1/communications/threads/{threadId}` | The conversation |
| `POST` | `/api/v1/communications/{id}/reply` | Reply into the same thread |

**Errors:** 422 — missing/invalid subject, unknown channel or direction, editing a sent message,
sending a message that is not a draft; 403 — subject outside scope, editing someone else's record,
restore without the right role; 404 — missing communication.

## Payloads

**`Communication` resource:** `id`, `reference` (`COM-YYYY-NNNN`), `subject_type`/`subject_id` + a
`subject` display block (`{ type, id, label, link }`), `contact`, `channel`, `direction`, `status`,
`subject` (title line), `body`, `from_name`/`from_address`, `to_name`/`to_address`, `occurred_at`,
`duration_minutes`, `outcome`, `external_id`, `failed_reason`, `thread_id` (S4),
`attachments[]` (S3), `owner`, plus `can_update`, `can_delete`, `can_restore`, `can_send` (S2).

**History book:** `{ data: Communication[], meta: { current_page, per_page, total, last_page }, links }`
plus `summary` — `{ all, inbound, outbound, by_channel: { email, call, … }, last_contacted_at }` for the
header. Gateway-backed payloads carry `meta.source: "Email (mocked)"` so a mocked send is never
mistaken for a real one.

**`communications` table:** `reference`, `subject_type`/`subject_id` (morph, required), `contact_id`
(FK, nullable), `channel`, `direction`, `status`, `subject`, `body`, `from_name`, `from_address`,
`to_name`, `to_address`, `occurred_at` (required), `duration_minutes`, `outcome`, `external_id`,
`thread_id` (S4), `in_reply_to_id` (S4), `failed_reason`, `owner_id`, timestamps, `deleted_at`.
Indexes: `subject_type+subject_id`, `channel`, `direction`, `status`, `occurred_at`, `contact_id`,
`owner_id`, `thread_id`. Plus `activities.communication_id` (nullable, indexed).

Enums (added to `00-data-model.md`): `CommunicationChannel` (`email, call, sms, whatsapp, meeting,
letter, other`), `CommunicationDirection` (`inbound, outbound`), `CommunicationStatus` (`draft,
scheduled, sent, received, failed, logged`), `CommunicationOutcome` (`connected, no_answer, voicemail,
busy, other`).

## Business rules

1. **No orphan messages** — every communication hangs off exactly one subject (lead / company /
   contact / opportunity / contract). There is no standalone "unfiled" history.
2. **Logging mirrors an activity and refreshes the subject.** The mirror activity is written by the
   `CommunicationLogged` listener, and `last_activity_at` is refreshed where the subject has one (leads,
   deals) so aging and deal staleness keep tracking real touches (ADR-0009, ADR-0015 §6).
3. **Contact history is a view, not a copy.** A message on a company or a deal belongs to that record;
   `filter[contact_id]` surfaces the ones addressed to a person. Nothing is duplicated per contact.
4. **Sent and received are immutable.** Only `draft` and `logged` records are editable, and only by
   their author. Deletion is soft; restore is manager+.
5. **`scheduled` is reserved for module 04** and is not settable in module 03. Follow-ups will create
   communications with a due time rather than a second scheduling table (ADR-0017 §3).
6. **Email is mocked.** Sending goes through the `EmailGateway` interface + `MockEmailGateway`
   (ADR-0005); `CRM_EMAIL_SIMULATE_FAILURE=true` forces the failure path exactly as
   `CRM_HANDOFF_SIMULATE_FAILURE` does for the Service handoff, and `meta.source` says so.
7. **Inbound is synced, never live.** There is no IMAP connection and no scheduler; the sync command is
   run to pull the mock's "received" messages.
8. **A communication is not a task.** No `due_at`, no assignee, no reminder — that is module 04.
9. **Attachments are optional** and never required to log a communication; a failed upload never
   discards the saved record.
10. **Scoping is inherited** from the subject: outside scope is 403, and a message never outlives its
    record's visibility.

## Permissions

| Action | salesrep | manager | admin / superadmin |
| --- | --- | --- | --- |
| View the book / a message / a nested history | Subject visible | Subject visible | All |
| Log a communication | Subject updatable | Subject updatable | ✓ |
| Edit a `draft`/`logged` record | Own only | Team | All |
| Soft delete | Own | Team | All |
| Restore | — | ✓ | ✓ |
| Send / retry an email (S2) | Subject updatable | ✓ | ✓ |
| Upload / remove an attachment (S3) | Own record | Team | All |

"Subject visible / updatable" reuses the subject's own policy: a salesrep's lead, account, contact
(ADR-0011 §2) or deal (ADR-0012 §4) is theirs; a manager covers the team; an admin sees everything.

## UI

**S1 — Communication History**

- `/communications` — the book: a `DataTable` of rows (channel badge + direction arrow, subject line,
  account/contact, owner, `occurred_at`, status badge) with `filter[channel|direction|status|company|
  owner|date range]` + `search`, pagination, and a header summary (total, inbound/outbound, per-channel
  counts, last contacted).
- `/communications/:id` — the detail: participants, the full body, the linked record (deep link), and
  the metadata; edit when `draft`/`logged`, otherwise read-only, with delete/restore by role.
- A **Log communication** `FormCard`/dialog — channel, direction, contact, subject, body, occurred at,
  duration and outcome for calls — reachable from the book, from each record page, and deep-linkable
  (`/communications/new?subject_type=opportunity&subject_id=…`).
- A **Communications** tab on `/companies/:id` (beside Contacts / Sites / Opportunities / Contracts /
  Activities), and a communications section on the lead and deal detail pages.
- The existing quick-log form stays where it is and is labelled **Quick log**; the new one is **Log
  communication** (ADR-0017 §7).
- Sidebar: module 03 group `Communication History Management` → `Communication History`
  (`/communications`).

**S2** adds the composer (draft → send → retry), a `Sent via mocked email` note, and a "Sync inbound"
action for the demo. **S3** adds the attachment list + upload + download on the detail. **S4** adds the
thread view and reply. **S5** adds the template picker.

Channel/direction/status colors reuse `StatusBadge` tones (email = activity/purple, call = people/green,
inbound = counts/blue, failed = danger) — tokens only, no hardcoded hex.

## Events

| Event | Emitted by | Effect |
| --- | --- | --- |
| `CommunicationLogged` | S1 | listener writes the mirror `Activity` and refreshes `last_activity_at` |
| `CommunicationSent` | S2 | status `sent`, `external_id` from the gateway, `sent` activity mirror |
| `CommunicationFailed` | S2 | status `failed` + `failed_reason`, retryable |

## Demo data

A `DemoCommunicationSeeder` (deterministic, no faker) seeds a few weeks of history across the seeded
accounts and deals — an inbound website enquiry on a Sunrise Foods lead, an outbound proposal email on
the Sunrise `proposal_sent` deal, a connected call and a no-answer call on BPO Solutions PH, a
negotiation email on the Harbor Logistics deal, and silence on Greenfield Hotels (so "last contacted"
and the stale filters have something to show) — owned across Sam/Liza/Maria.

## Acceptance criteria

**S1**

- [ ] A communication can be logged against a lead, account, contact, deal and contract; an unknown or
      missing subject is 422.
- [ ] Logging it writes exactly one mirror `Activity` (correct `ActivityType` for the channel) linked
      via `communication_id`, and refreshes the subject's `last_activity_at`.
- [ ] The mirror appears on the existing timelines (`/leads/{id}/timeline`, `/opportunities/{id}/timeline`,
      `/companies/{id}/activities`) with **no change** to those endpoints.
- [ ] The book filters by channel, direction, status, company, contact, owner and date range, and
      searches the subject line/body; `summary` carries the per-channel counts and `last_contacted_at`.
- [ ] The nested history endpoints return only that record's messages and 403 for a record outside scope.
- [ ] A salesrep sees only messages on their own records; a manager covers the team; an admin sees all.
- [ ] A `sent`/`received` record cannot be edited (422); only the author may edit a `draft`/`logged` one;
      deleting is soft and restore is manager+.
- [ ] `status=scheduled` is rejected in module 03 (reserved for module 04).
- [ ] The demo seed shows a populated book with both directions and several channels.

**S2** — a draft sends through the mocked gateway to `sent` with `meta.source`; `CRM_EMAIL_SIMULATE_FAILURE`
lands it in `failed` with a reason; retry succeeds and is not offered on an already-sent message; the
sync command records an inbound reply.

**S3** — a PDF/PNG/JPEG up to 10 MB attaches to a communication, is stored on the private disk, downloads
only through the authorized endpoint, and an oversized or wrong-typed file is 422.

**S4** — a reply joins the same thread; the thread endpoint returns the conversation in order; a thread is
only visible if every message's subject is visible.

**S5** — a template renders with the subject record's merge fields and prefills the composer; templates
are manager+ to manage.

## Open questions

- Should `Communication` eventually replace `Activity` for contact types so there is one write path? —
  **No for now** (ADR-0017 §7): that would rewrite the module 01/02 timelines and their tests. Revisit
  if reps find two entry points confusing in the demo.
- Reference prefix `COM-YYYY-NNNN` sits close to the company `CO-` prefix — acceptable, or use
  `MSG-`? — **`COM-`** proposed; it is the conventional abbreviation and the formats differ (four-digit
  tail, `COM-` vs `CO-`).
- Attachment limits — **up to 5 files, 10 MB each**, same MIME allow-list as contracts (S3).
- Should the inbound sync be scheduled in production? — **No**: nothing runs on a timer in this phase
  (scheduled work is module 04); the command is run manually for the demo.
