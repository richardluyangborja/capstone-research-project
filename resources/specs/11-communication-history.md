# 11 — Communication History Management

Module 3 (ADR-0014). Entities: `Communication` (new), `Attachment` (new, S3),
`CommunicationTemplate` (new, S5), `Activity`, `Lead`, `Company`, `Contact`, `Opportunity`, `Contract`,
`User`. ADRs: 0004, 0005, 0007, 0011, 0013, 0016, 0017.

> **S1–S5 built.** This is the contract for module 03, which is complete; the slices landed one PR at a
> time (ADR-0016 §2). It adds the `communications` table that `00-data-model.md` reserved, implements its
> deferred polymorphic `attachments` table (S3), groups messages into conversations (S4), adds reusable
> templates (S5), and keeps `Activity` as the lightweight quick-log (ADR-0017 §1). Email is a
> **mocked** integration (ADR-0017 §4) — nothing leaves the machine.

## Purpose

Give every record a truthful, channel-aware history of what was said: log and read emails, calls,
messages and meetings against a lead, account, contact, deal or contract; search and filter the
history; (from slice 2) compose, send and retry an email through the mocked gateway; (from slice 3)
attach the documents that went with it; (from slice 4) read an exchange as one conversation; and (from
slice 5) start from a reusable template rendered with the record's own details —
so a rep can see "what did we last tell BPO Solutions?", a
manager can read the trail behind a deal, and the rate sheet or signed page is where the conversation
is.

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
| **S1** *(built)* | `feature/communication-log` | Communication log & history | log a call on a deal → read it in the book and on the deal timeline |
| **S2** *(built)* | `feature/communication-email` | Email compose, send & retry (mocked gateway) | compose → send → status `sent` with the mocked source, force a failure → retry |
| **S3** *(built)* | `feature/communication-attachments` | Attachments on a communication | attach a PDF to an email, download it through the authorized endpoint |
| **S4** *(built)* | `feature/communication-threads` | Threading (conversations) | reply into a thread, see the conversation grouped |
| **S5** *(built)* | `feature/communication-templates` | Reusable templates + merge fields | quick-log from a template rendered with the contact's details |

S1 is the foundation everything else attaches to. S2 before S3/S4 because sending is what makes a
history worth reading; S3 implements the polymorphic `attachments` table `00-data-model.md` had already
reserved; S4 groups what S1/S2 write into conversations and files a pulled reply into the one it
answers; S5 keeps the two write paths and gives them the house's wording to start from.

**Not built here:** real mail transport/inbound sync, open/click tracking, VoIP/CTI and call recording,
campaigns, satisfaction surveys (module 05), reminder scheduling (module 04) and analytics (the AI
module).

## API endpoints

Static paths precede resource routes (project convention, `routes/api.php`). Each slice adds its own.

**S1 — log & history**

| Method | Path | Description |
| --- | --- | --- |
| `GET` | `/api/v1/communications` | History book — `filter[channel\|direction\|status\|subject_type\|subject_id\|company\|contact\|owner\|date_from\|date_to]` + `search`, paginated, `meta` summary. `company` matches the record's account (including messages on its deals), `contact` matches the person; `owner` takes an id or `me`/`team` |
| `POST` | `/api/v1/communications` | Log a communication against a subject |
| `GET` | `/api/v1/communications/{id}` | Detail (participants, body, linked record) |
| `PATCH` | `/api/v1/communications/{id}` | Edit a `draft`/`logged` record — its author or a manager+ in scope |
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
| `POST` | `/api/v1/communications/sync-inbound` | Pull the mocked mailbox — admin+; the same job the command below runs |

Both send endpoints answer 200 either way: a rejection is a delivery outcome the record carries, not an
HTTP error. `POST`/`PATCH` accept `draft` for an outbound email only (S2), on top of S1's four.

Inbound is pulled, not pushed: `php artisan communication:sync-inbound` asks the mocked gateway for
"received" messages and records them. No mailbox connection and no scheduler — nothing runs on a timer
in this phase (scheduled work is module 04). Sending/retrying requires a recipient: the address is taken
from the linked contact when none was typed, and a message with no destination is 422.

**S3 — attachments** *(implements the deferred polymorphic table)*

| Method | Path | Description |
| --- | --- | --- |
| `POST` | `/api/v1/communications/{id}/attachments` | Multipart upload — pdf/png/jpeg, ≤10 MB, private disk; the uploader needs the subject's `update` right |
| `GET` | `/api/v1/attachments/{id}/file` | Authorized download (streamed, under the uploader's filename) |
| `DELETE` | `/api/v1/attachments/{id}` | Remove — the same scope as uploading |

Files live on a private disk and are never addressed by URL: the resource carries no path, and the bytes
come only from the authorized download, exactly as a contract document does. The disk, size ceiling and
per-record cap are config (`crm.attachments.*`), sharing the contract allow-list. Attaching is refused
with **422** while a message has left or arrived — a `sent`/`received` message keeps the files it carried
— and an upload that fails leaves the logged record untouched.

The config ceiling is what *the app* enforces; the runtime has its own (`post_max_size` /
`upload_max_filesize`), which on the dev machine is lower (8 MB / 2 MB), so a demo file should stay
small — the seeded scans are tiny on purpose. Those runtime values are an environment setting, not a
project decision.

**S4 — threads**

| Method | Path | Description |
| --- | --- | --- |
| `GET` | `/api/v1/communications/threads` | Conversations the caller may see, newest first — `filter[channel\|subject_type\|subject_id\|company\|contact\|owner\|date_from\|date_to]` + `search`, paginated |
| `GET` | `/api/v1/communications/threads/{threadId}` | The conversation itself, oldest message first |
| `POST` | `/api/v1/communications/{id}/reply` | Answer a message; the reply joins its conversation |

A message nobody has answered has no `thread_id` and is not a conversation: it appears in the book and on
the record, but not in the thread list. Thread endpoints are static paths declared **before** the
`communications/{communication}` resource route, or the resource would swallow `threads` as an id.

**Reply request:** the record is *inherited, never accepted* — the payload has no `subject_type`/`subject_id`
(a request that names one is 422, so a conversation never changes hands). Everything the parent does not
settle is the caller's: the channel defaults to the parent's (an email thread stays email), the direction to
`outbound` (the person using the app is the one answering), the contact and the title line to the parent's,
and the status to the pair's normal default. A reply may be saved as a `draft` for an outbound email exactly
as a logged message can, and then sent (S2) or replied to (S4) from its own page.

Despite the table above reading `{id}`, the send/retry/upload/reply routes bind the model as `{communication}`
(project convention) — the path is what the client sees, the parameter name is internal.

**S5 — templates**

| Method | Path | Description |
| --- | --- | --- |
| `GET` | `/api/v1/communication-templates` | Templates — `filter[channel\|active]` + `search`, paginated |
| `POST` | `/api/v1/communication-templates` | Author one (manager+) |
| `GET` | `/api/v1/communication-templates/{id}` | One template |
| `PATCH` | `/api/v1/communication-templates/{id}` | Edit it (manager+) |
| `DELETE` | `/api/v1/communication-templates/{id}` | Retire it (manager+, soft) |
| `POST` | `/api/v1/communication-templates/{id}/restore` | Bring it back (manager+) |
| `POST` | `/api/v1/communication-templates/{template}/render` | Render it against a record |
| `GET` | `/api/v1/communication-templates/fields` | The merge-field vocabulary |

`fields` is a static segment, declared before the resource route for the same reason `threads` is:
otherwise `{template}` would swallow it as an id.

**Render request:** `{ subject_type, subject_id, contact_id? }` — the record it is being written about,
and (optionally) the person it is being written to. Rendering reads the record's own details, so it needs
the subject's `view` right. **Render response:** `{ data: { template_id, channel, subject, body,
unresolved: [...] } }` — what the composer should hold, plus every placeholder that could not be filled.

**Errors:** 422 — missing/invalid subject, unknown channel or direction, editing a sent message, sending
a message that is not a draft (or retrying one that is not `failed`), sending with no recipient,
uploading a wrong-typed/oversized file or more than the per-record cap, changing the files on a message
that has left or arrived, answering a message that never happened (`draft`/`scheduled`/`failed`) or a
reply that names another record, a template whose name a live one already uses, restoring a retired
template whose name has since been taken; 403 — subject outside scope, editing someone else's record,
reading or removing a file outside scope, restore or a mailbox pull without the right role, answering a
record the caller cannot work, authoring/editing/retiring/restoring a template without manager+, rendering
against a record outside scope; 404 — missing communication, a conversation outside scope or unknown, a
retired or unknown template, or a file missing from the disk.

## Payloads

**`Communication` resource:** `id`, `reference` (`COM-YYYY-NNNN`), `subject_type` (`lead`/`company`/
`contact`/`opportunity`/`contract`) + `subject_id` and a `record` block (`{ type, id, label }` — the app
builds the link from the type), `contact`, `channel`, `direction`, `status`, `subject` (title line),
`body`, `from_name`/`from_address`, `to_name`/`to_address`, `occurred_at`, `duration_minutes`, `outcome`,
`external_id`, `failed_reason`, `thread_id` (S4), `in_reply_to_id` + an `in_reply_to` block
(`{ id, reference, subject }`, S4), `attachments[]` (S3, loaded on the detail) and
`attachments_count` (S3, counted on the book rows), `owner`, plus `is_editable` and `can_update`,
`can_delete`, `can_restore`, `can_send` (S2), `can_attach` (S3), `can_reply` (S4 — false on a message
that never went out, or on a record the caller cannot work).

**Thread resource (S4):** `{ thread_id, title, record, channel, message_count, first_at, last_at,
last_message: { id, reference, direction, status, from_name, occurred_at } }`. The title is the original
message's subject line; `last_message` names who had the last word, so the list says who is waiting on
whom. The thread detail adds `messages: Communication[]`, oldest first.

**`Attachment` resource (S3):** `id`, `name` (the uploader's filename, for display), `mime_type`, `size`
(bytes — the client formats it), `uploaded_by` + `uploader`, `can_delete`, `created_at`. The disk and the
stored path are deliberately **absent**: the only handle is the attachment's own id, and the bytes come
from `GET /attachments/{id}/file`.

**`CommunicationTemplate` resource (S5):** `id`, `name`, `channel`, `subject`, `body`, `is_active`,
`created_by` + an `author` block (`{ id, name }`), `updated_at`, plus `can_update`, `can_delete`,
`can_restore` — so the book offers only the actions the caller actually has.

**Merge fields (S5):** `{{token}}`, doubled braces with optional spaces. The vocabulary (also served by
`/communication-templates/fields`, so the UI and the renderer cannot drift):

| Token | Resolves to |
| --- | --- |
| `contact.name`, `contact.first_name`, `contact.position`, `contact.email` | the person the message is with — the linked contact, or the lead's own `contact_name`/`contact_position`/`contact_email` when the record is a lead |
| `company.name` | the account behind the record: a lead's `company_name`, a contact's account, a deal's or contract's account, or the account itself |
| `opportunity.name`, `contract.title`, `contract.reference` | only when the record is that deal or contract |
| `user.name`, `user.email` | the caller (whoever is writing) |
| `today` | today, PH-formatted (`DD MMM YYYY`, `Asia/Manila`) |

A token that is unknown, or known but empty for this record, is **left verbatim** and listed in
`unresolved` — the rep sees the gap instead of sending a silently blank line.

**History book:** `{ data: Communication[], meta: { current_page, per_page, total, last_page }, links }`
plus `summary` — `{ all, inbound, outbound, by_channel: { email, call, … }, last_contacted_at }` for the
header. Gateway-backed payloads carry `meta.source: "Email (mocked)"` so a mocked send is never
mistaken for a real one.

**`communications` table:** `reference`, `subject_type`/`subject_id` (morph, required), `contact_id`
(FK, nullable), `channel`, `direction`, `status`, `subject`, `body`, `from_name`, `from_address`,
`to_name`, `to_address`, `occurred_at` (required), `duration_minutes`, `outcome`, `external_id`,
`thread_id` (S4 — the root message's id; null until something answers), `in_reply_to_id` (S4 — the message
answered), `failed_reason`, `owner_id`, timestamps, `deleted_at`.
Indexes: `subject_type+subject_id`, `channel`, `direction`, `status`, `occurred_at`, `contact_id`,
`owner_id`, `thread_id`. Plus `activities.communication_id` (nullable, indexed).

**`attachments` table (S3 — the polymorphic table `00-data-model.md` reserved):**
`attachable_type`/`attachable_id` (morph, ULIDs), `disk`, `path`, `original_name`, `mime_type`, `size`,
`uploaded_by` (FK), timestamps. No `deleted_at`: a file exists for its row and for nothing else, so
removing the row removes the file (there is no "retired but kept" state for a file). Communications are
its first user; the single contract document can move onto it later without a second table.

**`communication_templates` table (S5):** `name`, `channel` (default `email`), `subject`, `body`,
`is_active` (default true), `created_by` (FK users, nullable), timestamps, `deleted_at`. Indexes: `name`,
`channel`, `is_active`. The name is **unique among live rows only**, and because a soft-deleted template
frees its name that uniqueness is a validation rule rather than a database constraint (`restore` re-checks
it, so bringing back an old template can never shadow a live one).

Enums (added to `00-data-model.md`): `CommunicationChannel` (`email, call, sms, whatsapp, meeting,
letter, other`), `CommunicationDirection` (`inbound, outbound`), `CommunicationStatus` (`draft,
scheduled, sent, received, failed, logged`), `CommunicationOutcome` (`connected, no_answer, voicemail,
busy, other`).

## Business rules

1. **No orphan messages** — every communication hangs off exactly one subject (lead / company /
   contact / opportunity / contract). There is no standalone "unfiled" history.
2. **Logging mirrors an activity and refreshes the subject.** The mirror activity is written by the
   `CommunicationLogged` listener, and `last_activity_at` is refreshed where the subject has one (leads,
   deals) so aging and deal staleness keep tracking real touches (ADR-0009, ADR-0015 §6). Deleting a
   communication removes its mirror; restoring rewrites it. The mirror is a pointer — the body lives only
   on the communication. A `draft` is the exception: it has not happened yet, so it carries no mirror and
   moves no clock (S2). Sending it writes the mirror and stamps `occurred_at` with the moment it left, so
   the timeline reads as the record of the send rather than of the writing.
3. **Contact history is a view, not a copy.** A message on a company or a deal belongs to that record;
   `filter[contact]` surfaces the ones addressed to a person. Nothing is duplicated per contact.
4. **Sent and received are immutable.** Only `draft` and `logged` records are editable, and only by
   their author or a manager+ within the record's scope. Deletion is soft; restore is manager+.
5. **`scheduled` is reserved for module 04** and is not settable in module 03. Follow-ups will create
   communications with a due time rather than a second scheduling table (ADR-0017 §3).
6. **Email is mocked.** Sending goes through the `EmailGateway` interface + `MockEmailGateway`
   (ADR-0005); `CRM_EMAIL_SIMULATE_FAILURE=true` forces the failure path exactly as
   `CRM_HANDOFF_SIMULATE_FAILURE` does for the Service handoff, and `meta.source` says so. The driver
   derives every id from the message reference, so a message always lands on the same id and a repeated
   sync cannot invent a second reply.
7. **Inbound is synced, never live.** There is no IMAP connection and no scheduler; the sync command is
   run to pull the mock's "received" messages. A reply is filed against the record its original message
   belongs to — a reply that answers nothing we hold is dropped, because there are no orphan messages
   (rule 1) — and it is filed **into that original's conversation** (S4): a pulled reply is a reply like
   any other, so it joins the thread instead of landing beside it.
8. **A communication is not a task.** No `due_at`, no assignee, no reminder — that is module 04.
9. **Attachments are optional** and never required to log a communication; a failed upload never
   discards the saved record. A message carries at most **5 files of 10 MB** (pdf/png/jpeg — the contract
   allow-list), stored on a private disk and readable only through the authorized download; the stored
   path is never sent to a client. Files follow the message's own rule: they may be added or removed
   while it is still editable (`draft`, `logged`) **or has never been delivered** (`failed` — a rejected
   send is still the rep's to fix before retrying), and a `sent`/`received` message keeps the files it
   carried, because they are part of the record of what was said. Removing a file removes the row and the
   bytes; retiring a message keeps them (it is still readable and restorable), while destroying a message
   outright takes its files with it.
10. **Scoping is inherited** from the subject: outside scope is 403, and a message never outlives its
    record's visibility.
11. **A reply joins the conversation it answers** (S4). The message being answered opens the thread when
    nothing has yet: it becomes the thread's **root** and carries **its own id** as `thread_id`, so one
    `thread_id` selects the whole exchange — the original included — and only an already-recorded
    message's index column is ever rewritten. The answer points back at the message it answers with
    `in_reply_to_id` (the direct parent, not the root), inherits the parent's record, channel and title
    line, and goes out, because the caller is the one answering. Nothing can answer a message that never
    happened — a `draft` has not left, a `scheduled` follow-up is still to come (module 04) and a
    rejected send went nowhere — so those are 422. A conversation is visible only while **every** message
    in it is: one message on a record the caller may not see hides the whole exchange (in practice the
    rule follows from the record being shared, since every reply inherits it — the check keeps that an
    enforced invariant rather than an assumption).
12. **A template is a starting point, not a third write path** (S5). Rendering one fills the composer's
    fields; what is saved is an ordinary communication that never references the template again, so
    editing or retiring a template changes nothing already logged. Only **active** templates are offered
    in the composer — a retired one is out of the picker, not out of the database — and only manager+
    author them, because a template is the house's wording rather than one rep's note.

## Permissions

| Action | salesrep | manager | admin / superadmin |
| --- | --- | --- | --- |
| View the book / a message / a nested history | Subject visible | Subject visible | All |
| Log a communication | Subject updatable | Subject updatable | ✓ |
| Edit a `draft`/`logged` record | Own only | Team | All |
| Soft delete | Own | Team | All |
| Restore | — | ✓ | ✓ |
| Send / retry an email (S2) | Subject updatable | ✓ | ✓ |
| Pull the mocked mailbox (S2) | — | — | ✓ |
| Upload / remove an attachment (S3) | Own record | Team | All |
| Read a conversation / answer a message (S4) | Own record | Team | All |
| Read a template / render it into the composer (S5) | ✓ | ✓ | ✓ |
| Author, edit, retire or restore a template (S5) | — | ✓ | ✓ |

"Subject visible / updatable" reuses the subject's own policy: a salesrep's lead, account, contact
(ADR-0011 §2) or deal (ADR-0012 §4) is theirs; a manager covers the team; an admin sees everything.

## UI

**S1 — Communication History**

- `/communications` — the book: a `DataTable` of rows (channel badge + direction badge, subject line
  and reference, the linked record, contact, status, who logged it) with `filter[channel|direction|
  status|subject_type|subject_id|company|owner|date range]` + `search`, pagination, and a header
  summary (all, inbound, outbound, last contacted — the per-channel breakdown rides along in
  `summary`). A `?subject_type=&subject_id=` deep link narrows it to one record.
- `/communications/:id` — the detail: participants, the full body, the linked record (deep link), and
  the metadata; edit when `draft`/`logged`, otherwise read-only, with delete/restore by role.
- A **Log communication** form — record picker (or a prefilled deep link), channel, direction, status,
  contact, subject, body, occurred at, and duration/outcome for calls — reachable from the book, from
  each record page, and deep-linkable
  (`/communications/new?subject_type=opportunity&subject_id=…`). Contracts are picked from the
  account's Contracts tab (there is no flat contract book to search).
- A **Communications** tab on `/companies/:id` (beside Contacts / Sites / Opportunities / Contracts /
  Activities), a communications section on the lead and deal detail pages, and a per-contract **Log**
  action on the account's Contracts tab. All three render the shared `CommunicationList` panel.
- The existing quick-log form stays where it is and is labelled **Quick log**; the new one is **Log
  communication** (ADR-0017 §7).
- Sidebar: module 03 group `Communication History Management` → `Communication Log & History`
  (`/communications`).

**S2 — the outbound email path**

- The log form offers **Draft** in its status list for an outbound email (and nothing else), submits with
  a **Save draft** button, and lands on the detail page; picking another channel drops the option again.
- `/communications/:id` carries the gateway in place of the composer: **Send now** on a draft, **Retry
  send** on a rejected one (both behind `can_send`), the rejection reason in a danger alert, and a
  **Simulated mailbox** note naming the mailbox id whenever the message really went through the mock — a
  hand-logged "sent" email has no id, so it makes no such claim. "Draft — not sent yet" explains why a
  draft is absent from the record's timeline.
- `/communications` gains a **Pull replies** action for admins (the in-app twin of
  `communication:sync-inbound`) and reports what arrived.

**S3 — the attachment list**

- `/communications/:id` carries an **Attachments** panel under the body: each file with its name (the
  download action), formatted size and uploader, a **Download** action, and a **Remove** action while the
  message still allows it. **Attach file** (a file picker + button, `accept` on the allow-list) appears
  only when `can_attach`, so a sent or received message shows "A sent message keeps the files it carried."
  instead of a control the API would refuse; a rejected upload reports why in place rather than losing
  what was logged.
- The book rows and the record history panel add a file count beside the reference, so a message with a
  document is visible without opening it.

**S4 — conversations**

- `/communications/threads` — the conversation list: a `DataTable` of exchanges (title line + message
  count, the record it hangs off, channel, who had the last word with its reference at, when), narrowed by
  `filter[channel|subject_type|subject_id|owner]` + `search`, paginated newest first. A message nobody has
  answered is not a conversation and is not listed, so the page's empty state says how one starts: answer
  a message.
- `/communications/threads/:threadId` — the exchange read as it happened: the original first, each answer
  after it, every message a card carrying its channel/direction/status badges, who it was with, who logged
  it, the message it answers and its file count, with a **Reply** action on each message the API says may
  be answered and an **Open message** link through to the full record. The aside names the record the
  conversation hangs off and when it started and last moved.
- `/communications/:id` carries the threading links — **In reply to** `COM-…` and **Part of a
  conversation** — and a **Reply** action while `can_reply`; the book marks a threaded message **In a
  conversation**. Both link into the thread.
- The reply form is the log form started from a message (`/communications/new?reply_to=…`): the record,
  channel, contact and title line come from the parent (an email is proposed `Re:`-prefixed), the record
  picker is replaced by a read-only "In reply to" line, and it submits through the reply endpoint with
  **Log reply** / **Save draft reply**. A **Reply** action never appears for a message that cannot be
  answered.
- Sidebar: the module 03 group gains `Threaded Conversations` (`/communications/threads`); the S1 entry
  is now matched **exactly**, so it no longer stays lit on the child.

**S5 — templates**

- `/communications/templates` — the template book: name, channel badge, title line, active state and
  author, narrowed by `filter[channel|active]` + `search`, with **New template** for manager+ and
  **Edit** / **Retire** / **Restore** on the rows the API allows.
- `/communications/templates/new` and `/communications/templates/:id` — the form (name, channel, title
  line, body, active) with the merge-field vocabulary as chips that insert at the caret of the field last
  used.
- The log/reply form gains **Start from a template**: choosing an active template renders its channel,
  title line and body against the chosen record and fills the form. A placeholder the record could not
  fill is named in an inline note rather than left as a silent blank.
- Sidebar: the module 03 group gains `Communication Templates`.

Channel/direction/status colors reuse `StatusBadge` tones (email = activity/purple, call = people/green,
inbound = counts/blue, failed = danger) — tokens only, no hardcoded hex.

## Events

| Event | Emitted by | Effect |
| --- | --- | --- |
| `CommunicationLogged` | S1 (and S4 for a reply) | listener writes the mirror `Activity` and refreshes `last_activity_at` |
| `CommunicationSent` | S2 | status `sent`, `external_id` from the gateway, `sent` activity mirror |
| `CommunicationFailed` | S2 | status `failed` + `failed_reason`, retryable |

## Demo data

A `DemoCommunicationSeeder` (deterministic, no faker) seeds a few weeks of history across the seeded
accounts and deals — an inbound website enquiry on a Sunrise Foods lead, an outbound proposal email on
the Sunrise `proposal_sent` deal, a connected call and a no-answer call on BPO Solutions PH, a
negotiation email on the Harbor Logistics deal, a send the gateway rejected on the BPO ramp deal (so the
retry path demos without touching config), and silence on Greenfield Hotels (so "last contacted" and the
stale filters have something to show) — owned across Sam/Liza/Maria.

Every seeded message that "went out" carries the id the mocked gateway assigns it, and the seeded BPO
reply carries the id the mock would give that reply, so a demo **Pull replies** recognises what is
already there and adds only what is new.

Slice S3 also seeds two placeholder scans (a real, if tiny, PNG so a demo download opens rather than
handing back a broken document): one on the Sunrise rate-sheet email — a `sent` message, so it shows the
locked side — and one on the Sunrise commissary call, which is `logged`, so the demo can add another file
live.

Slice S4 seeds **two conversations** rather than matching subject lines by hand: the BPO "commercial terms"
email and the procurement answer that came back, and the Sunrise rate-sheet email with the client's approval
and Sam's answer — three messages, `in_reply_to_id` chained from each to the one it answers, so the thread
view, the count and the "last word" all demo without answering anything first. The pulled replies keep the
id the mock would assign them, so a demo **Pull replies** still adds only what is genuinely new — and now
files what it does pull *into* the conversation it answers.

Slice S5 seeds the house's own wording: a rate-sheet follow-up and a site-visit confirmation (email), a
cold-call script (call) and a meeting agenda (meeting) — each written with `{{contact.first_name}}` and
`{{company.name}}` so the render demo fills them from the record it is pointed at — plus one retired
`Holiday greeting`, so the active filter has something to hide.

## Acceptance criteria

**S1**

- [x] A communication can be logged against a lead, account, contact, deal and contract; an unknown or
      missing subject is 422.
- [x] Logging it writes exactly one mirror `Activity` (correct `ActivityType` for the channel) linked
      via `communication_id`, and refreshes the subject's `last_activity_at`.
- [x] The mirror appears on the existing timelines (`/leads/{id}/timeline`, `/opportunities/{id}/timeline`,
      `/companies/{id}/activities`) with **no change** to those endpoints.
- [x] The book filters by channel, direction, status, company, contact, owner and date range, and
      searches the subject line/body; `summary` carries the per-channel counts and `last_contacted_at`.
- [x] The nested history endpoints return only that record's messages and 403 for a record outside scope.
- [x] A salesrep sees only messages on their own records; a manager covers the team; an admin sees all.
- [x] A `sent`/`received` record cannot be edited (422); only the author — or a manager+ in scope — may
      edit a `draft`/`logged` one; deleting is soft and restore is manager+.
- [x] `status=scheduled` is rejected in module 03 (reserved for module 04).
- [x] The demo seed shows a populated book with both directions and several channels.

**S2**

- [x] An outbound email can be saved as a `draft`; a `draft` on any other channel or direction is 422,
      and a draft writes no mirror activity and moves no `last_activity_at`.
- [x] `POST /send` hands the draft to the gateway, answering 200 `sent` with the gateway's `external_id`
      and `meta.source: "Email (mocked)"`; sending anything that is not a draft is 422.
- [x] `CRM_EMAIL_SIMULATE_FAILURE=true` lands the message in `failed` with `failed_reason`, still 200, and
      `POST /retry` sends it once the gateway is healthy again — retry on a message that is not `failed`
      is 422.
- [x] Sending and retrying need the subject's `update` right (403), and a message with no recipient — one
      not typed and not derivable from the linked contact — is 422.
- [x] A sent message joins the record's timeline and restarts its clock, exactly like a logged entry, and
      the timeline endpoints are untouched.
- [x] The sync command records the mock's reply as a `received` inbound message on the record its original
      belongs to, mirror included; it is idempotent across runs and drops a reply it cannot place.
- [x] `POST /communications/sync-inbound` is admin+ and reports how many replies it recorded.
- [x] The detail screen sends a draft, retries a rejected one, shows the gateway's id and a case where
      there is none (a hand-logged "sent" email never claims the mock carried it).

**S3**

- [x] A pdf, png or jpeg up to 10 MB attaches to a communication and is stored on the private disk under a
      generated path; the uploader's filename is kept for display only, and the payload exposes no path.
- [x] An oversized file, a wrong type or a sixth file on one message is 422 with the reason; nothing is
      stored, and the logged record is untouched.
- [x] The file downloads only through `GET /attachments/{id}/file`, under the name the uploader gave it;
      a file missing from the disk is 404, and a caller outside the subject's scope is 403.
- [x] A `sent`/`received` message takes no new files and gives none up (422 both ways); a `draft`, `logged`
      or `failed` one may still be worked.
- [x] Removing a file deletes the row and the bytes; retiring a message keeps its files, destroying it
      takes them with it.
- [x] The detail lists the files with size and uploader and offers the upload only when the API says
      `can_attach`; the book and the record history show the count.

**S4**

- [x] Answering a message joins the same conversation: the parent becomes the thread's root (carrying its
      own id as `thread_id`), the reply points back at the message it answers with `in_reply_to_id`, and
      one `thread_id` selects the whole exchange.
- [x] The reply inherits the parent's record, channel, contact and title line, goes out (default
      `outbound`), and may be saved as a `draft` for an outbound email; a payload naming another record is
      422.
- [x] Answering a message that never happened — `draft`, `scheduled` or `failed` — is 422, as is
      answering a record the caller cannot work (403) or one outside their scope.
- [x] A reply writes its timeline mirror and restarts the record's clock, exactly like any other logged
      message; a draft reply writes neither.
- [x] `GET /communications/threads` groups only answered messages, newest first, with the title, record,
      channel, message count, first/last timestamps and the last message; `GET /communications/threads/{id}`
      returns the conversation oldest-first. An unknown thread is 404.
- [x] A thread is only visible if every message's subject is visible: a conversation on a record outside
      the caller's scope is hidden (404, and absent from the list), and a hand-built thread mixing two
      records is hidden too.
- [x] The inbound sync files a pulled reply into the conversation its original belongs to.
- [x] The UI lists conversations, reads one back in order with the message each answer belongs to, offers
      Reply only where the API allows it, and answers through the reply endpoint with the record locked.
- [x] The demo seed shows two real conversations with chained replies.

**S5**

- [x] A template carries a name, channel, title line, body and active flag; only **active** ones are
      offered in the composer, while the book can filter on either state.
- [x] Manager+ author, edit, retire and restore templates; a salesrep may read and use them but not change
      them (403), and force-deleting stays admin.
- [x] A live duplicate name is 422; a retired template frees its name, and restoring the old one when the
      name has since been taken is refused rather than silently shadowing the live template.
- [x] `POST /communication-templates/{id}/render` renders the title line and body against a record with
      the caller's own details, and leaves any unknown or empty placeholder **verbatim** while listing it
      in `unresolved`.
- [x] The composer prefills from a rendered template (channel, title line, body) and never references the
      template again, so editing or retiring it changes nothing already logged.
- [x] The demo seed ships templates across channels, including one retired, so the active filter demos.

## Open questions

- Should `Communication` eventually replace `Activity` for contact types so there is one write path? —
  **No for now** (ADR-0017 §7): that would rewrite the module 01/02 timelines and their tests. Revisit
  if reps find two entry points confusing in the demo.
- Reference prefix `COM-YYYY-NNNN` sits close to the company `CO-` prefix — acceptable, or use
  `MSG-`? — **`COM-`**, and S1 shipped it: the formats differ (four-digit tail, `COM-` vs `CO-`).
- Attachment limits — **up to 5 files, 10 MB each**, same MIME allow-list as contracts (S3, config
  `crm.attachments.*`).
- May a file be added to a message that has already gone out? — **No** (S3): files are part of the record
  of what was said, so `sent`/`received` keeps what it carried; `draft`, `logged` and a never-delivered
  `failed` send may still be worked. Same shape as the body rule in ADR-0017 §6.
- Should the inbound sync be scheduled in production? — **No**: nothing runs on a timer in this phase
  (scheduled work is module 04); the command is run manually for the demo, and an admin can trigger the
  same job from the book.
- How is a conversation identified, and can a message change threads? — **The root's id** (S4): a message
  nobody answered has `thread_id = null`, the first reply stamps the root with its own id so one query
  selects the whole exchange, and `in_reply_to_id` names the immediate parent. A reply is created into its
  parent's thread and the record is prohibited in the payload, so a message never changes hands — moving
  one is not a supported operation, not merely refused.
- Which messages can be answered? — **Only ones that happened** (S4): a `draft` has not left, a
  `scheduled` follow-up is still to come (module 04) and a rejected send went nowhere. The rule is a state
  question (422, checked by the model) on top of the permission question (403, checked by the policy),
  the same split as sending a draft (ADR-0017 §5).
- Does a reply copy the parent's subject? — **It defaults to it, and the composer proposes `Re:` for an
  email** (S4): the API never rewrites a title line, so `Re:` is a UI convention over a verbatim default,
  not a threaded-header parser. Matching on subject lines is deliberately not how conversations are
  grouped.
- How are merge fields written, and what happens to one that does not fit? — **`{{token}}`** (S5), with the
  vocabulary served by the API so the composer's chips and the renderer cannot drift apart. A token that is
  unknown, or known but empty for this record, is **left in the text and reported** in `unresolved`: the
  server never invents a value, and the rep sees exactly what the template could not fill before sending.
- Are templates shared, and what happens to a message that used one? — **Shared, and nothing** (S5). They
  are the house's wording, so every role reads them and manager+ writes them; a rendered template is only
  ever a prefill, so a message keeps no link back and later edits to the template cannot rewrite history.
