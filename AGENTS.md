# AGENTS.md

## Repository state

Scaffolded monorepo (**no commits yet**): three separate apps — `backend/`, `frontend/`, `ai/` — plus
spec/decision docs. There is no shared package; `frontend/` talks to `backend/` only over the
`/api/v1` REST API. `resources/*.md` is the source of truth for requirements; update it when
decisions change.

`decisions/` holds decisions made as development progresses (see `decisions/README.md` for the
`NNNN-title.md` + Proposed/Accepted convention). Read existing ADRs before proposing design changes,
record new architectural/technical decisions there, and mirror anything that changes agent workflow
into this file.

## Stack & layout (from `resources/system-tech.md`)

- `frontend/` — React 19 + Vite + TypeScript SPA (Tailwind, `react-router-dom`, `axios`, `lucide-react`)
- `backend/` — Laravel 13 API; versioned REST under `/api/v1` (`routes/api.php`)
- `ai/` — Python FastAPI service (lightweight) for AI/ML reports (`resources/system-ai-feature.md`)
- `docker-compose.yml` at the root — local Postgres only (`db` service); the apps run on the host
- Single git repo at the root tracks all three; remote `origin` is set

## Commands

Run from the repo root unless a directory is noted; start the DB first, or let the stack scripts do
it for you.

- **Whole stack (from root):** `./up.sh` starts Postgres + backend + AI + frontend in the background
  (logs/PIDs in `.run/`); `./down.sh` stops every tracked process, sweeps leftover listeners and
  removes the DB container (the data volume is kept); `./status.sh` prints the health table and exits
  non-zero if anything is down.
- **DB:** `docker compose up -d --wait db` (Postgres :5432; credentials in `backend/.env`)
- **Backend (`backend/`):** `composer install` · `php artisan migrate` · `php artisan serve`
  (API at http://localhost:8000; health `/api/v1/health`)
  - Test: `php artisan test` (single: `php artisan test --filter=Name`); format: `vendor/bin/pint`
- **Frontend (`frontend/`):** `npm install` · `npm run dev` (http://localhost:5173)
  - Verify: `npm run lint` · `npm run test` · `npm run build`; format: `npm run format`
- **AI (`ai/`):** `pip install -r requirements-dev.txt` · `uvicorn app.main:app --reload --port 8001`
  (health `/health`; reports `POST /reports/dashboard`)
  - Test: `.venv/bin/pytest`; the Groq key lives only in `ai/.env`, and `AI_SERVICE_TOKEN` must
    match the value in `backend/.env`

`backend/` has its own `AGENTS.md` (Laravel Boost guidelines) and `opencode.json` MCP config — read
it before backend work. Backend asset tooling (`backend/package.json`, `backend/vite.config.js`) is
Laravel's own and is separate from `frontend/`.

**Implemented:** JWT auth (`php-open-source-saver/jwt-auth`, `api` guard — ADR-0008) with
`POST /api/v1/auth/login`, `GET /api/v1/auth/me`, `POST /api/v1/auth/logout`; teams + roles on
`users` (seeded, no faker).

- **Submodule 01 — Lead Capture & Intake:** `/api/v1/leads` (index/store/show/destroy +
  `POST /leads/{id}/restore`), advisory duplicate check, role/ownership scoping (ADR-0004).
- **Submodule 02 — Lead Qualification & Scoring:** `PATCH /leads/{id}/qualification`,
  `POST /leads/{id}/rate|qualify|disqualify|nurture`. Minimum criteria (headcount + roles + a
  contact channel) are enforced on `/qualify` (422 lists what's missing); a weighted checklist
  score (0–100) and derived hot/warm/cold rating are stored; edits are blocked once
  disqualified/converted. `filter[status|rating|urgency]` accept comma-separated values.
- **Submodule 03 — Lead Assignment & Ownership:** `POST /leads/{id}/assign|claim`,
  `POST /leads/bulk-assign|bulk-transfer`, `GET /leads/summary`, `GET /users/assignable`; database
  notifications + `GET /notifications`, `POST /notifications/{id}/read|read-all`. Salesrep → self
  only, manager → their team, admin → any (ADR-0004). The unassigned queue is a **shared, claimable
  pool** visible to all roles; a salesrep must claim before editing. Reassignment writes an
  `Activity` (type `system`) and notifies the new owner. Round-robin distribution is **deferred**
  (spec 03 §3).
- **Submodule 04 — Lead Lifecycle & Status:** `PATCH /leads/{id}/status`,
  `POST /leads/{id}/archive`, `POST /leads/{id}/activities`, `GET /leads/{id}/timeline`,
  `GET /leads?filter[stale]=true|at_risk|archive`. A state machine (`LeadStatus::allowedTransitions`)
  rejects illegal moves with 422; every change stamps `status_changed_at`, refreshes
  `last_activity_at`, and records a `system` activity via the `LeadStatusChanged` event. The status
  endpoint **cannot bypass submodule 02** (qualifying still needs the minimum criteria,
  disqualifying a reason); `qualify|disqualify|nurture` are wrappers over `Lead::transitionTo()`.
  `converted` is terminal and is only reached by the submodule-08 conversion flow (ADR-0009).
  Aging (stale/at-risk/archive-due) is **computed on read** from `config/crm.php`
  (`CRM_STALE_DAYS` 14 / `CRM_AT_RISK_DAYS` 30 / `CRM_ARCHIVE_DAYS` 60). `archived` is a status, not a
  delete: archived leads are hidden from default lists and counts unless
  `filter[status]=archived` (or `filter[archived]=true`). The old
  `GET /leads/{id}/activities` was replaced by `/timeline`.
- **Submodule 06 — Company / Account Management:** `/api/v1/companies` (index/store/show/update/
  destroy + `POST /companies/{id}/restore`), nested `companies/{id}/sites` (list/store/update/delete)
  and `companies/{id}/activities` (list + log). `reference` is `CO-YYYY-NNNN`; `name` is soft-unique
  (a name collision returns 201 with a `duplicates` advisory array). Parent/child links form
  branches — cycles are rejected (422), a branch inherits nothing, and the parent exposes a branch
  *count* only (no roll-up). One site per company is `is_primary`; the first site added becomes
  primary and deleting the primary promotes the next. `counts` reports
  `{ sites, branches, contacts, opportunities, activities }`; the contract count arrives with 09.
  **Permissions (ADR-0010):** every role may create manually (a salesrep's company is theirs and
  starts as `prospect`); profile updates are own/team/all; **delete/restore, sites and branch links
  are manager+**; `status` may be set at creation by manager+ and changed later only by an admin.
  Activity payloads are shared with leads via `ActivityResource`.
- **Submodule 07 — Contact Management:** nested `GET/POST /companies/{id}/contacts` plus the flat
  book — `GET /contacts` (paginated, `filter[company_id|role_type|status]` + `search`),
  `GET/PATCH/DELETE /contacts/{id}`, `POST /contacts/{id}/restore` and
  `POST /contacts/{id}/make-primary`. Contacts carry `role_type` (hiring manager / HR / procurement /
  finance / executive / other), a single `email`/`phone`/`mobile`, `status` and `is_primary` (ADR-0011
  §6). **Exactly one primary per company among active contacts**: the first contact added is primary,
  `make-primary` moves the flag, deleting or deactivating the primary promotes the oldest remaining
  active contact, and an inactive contact can never lead. A repeated email on the same company is an
  advisory (`duplicates` on the 201/200, never a block); `company_id` is not updatable, and contacts
  are never hard deleted (`status=inactive` for pickers, soft delete + restore for retired rows).
  **Permissions (ADR-0011 §2):** a contact inherits its company's `update` rights, so a salesrep
  maintains the people on their own accounts — the manager+ gate that covers sites/branches does
  **not** apply. `ContactPolicy` also gates `makePrimary`/`restore` and reserves force-delete for
  admins. Events: `ContactCreated`, `ContactPrimaryChanged`.
- **Submodule 08 — Lead-to-Client Conversion:** `GET /leads/{id}/conversion-preview` (suggested
  company/contact/opportunity mapping + duplicate candidates), `POST /leads/{id}/convert` (atomic:
  company + contact(s) + first deal, then `lead.status = converted` with `converted_company_id` /
  `converted_opportunity_id`) and the read-only `GET /companies/{id}/opportunities`
  (`filter[stage|open]`, `meta { total, open }`). The `Opportunity` model uses `OPP-YYYY-NNNN`
  references, `stage`/`probability` defaults from `OpportunityStage` (`qualified` = 20) and a
  `scopeVisibleTo` (owner/team/all). Only `qualified` leads convert (422 otherwise); a second
  attempt is 409; `link_company_id` must be a visible company (422) — a company in the caller's
  scope is reused instead of creating one, and its status is left alone. A salesrep-opened deal has
  `amount = null` (the wizard's amount is ignored); a manager+ conversion defaults the amount to the
  lead's sensitive `budget_rate`. `LeadPolicy::convert` delegates to `update`, so a salesrep must own
  the lead. A converted lead leaves the qualified worklist and gains a `system` `Converted into …`
  activity. Events: `LeadConverted`, `OpportunityCreated`. **Not built here:** the deal pipeline
  (stage moves, won/lost, Kanban) and contracts — a deal is only created and viewed.
- **Submodule 09 — Client Account Lifecycle:** `/companies/{id}/contracts` (list + multipart
  upload), `/contracts/{id}` (show/update/delete/restore), `POST /contracts/{id}/mark-signed`,
  `GET /contracts/{id}/file` (authorized download), `PATCH /companies/{id}/lifecycle`,
  `POST /companies/{id}/reactivate`, `POST /companies/{id}/handoff/retry`,
  `GET /companies/{id}/billing`. `Contract` is a `CON-YYYY-NNNN` record with a private-disk document,
  a renewal chain (`renewal_of_contract_id`) and a state machine (`draft → sent → signed →
  expired|terminated`); **`signed` is only reachable via `mark-signed`** (a `PATCH` cannot set it) and
  termination is manager+. Signing commits first and then activates the client + runs the mocked
  Service handoff (`ContractSigned` → `HandoffContractToService` → `Company::handoffToService()`, the
  same path the retry endpoint uses); a failed handoff leaves the contract signed and the client in
  `onboarding` with `handoff_status = failed` + a retry, and repeat business never resets an
  established client. `CompanyStatus::allowedTransitions()` drives the lifecycle (manager+,
  churn requires a reason, `reactivate` only from `churned`, `client_since` never reset); aging is
  **manual** in Phase 1. A `signed` contract blocks deleting the company. `ContractPolicy` inherits
  the account's rights; billing is manager-only. Events: `CompanyStatusChanged`, `ContractSigned`,
  `CompanyActivated`, `CompanyChurned`. **Not built here:** the deal pipeline, contract
  versioning/e-signature, renewal reminders and automatic aging (ADR-0013).
- **Module 02 — Opportunity Pipeline Visualization:** the deal pipeline on the `Opportunity` record
  (ADR-0014, ADR-0015). Flat `/api/v1/opportunities` (index/store/show/update/destroy +
  `POST /{id}/restore`) with `filter[stage|owner|company|open|source|close_window]` + `search` and a
  `summary` block; a deal is created directly on a visible company (owner = the creator) or by
  conversion, and the book is role-scoped (salesrep own, manager team, admin all). `POST
  /opportunities/{id}/stage` moves **any-to-any with rules** from `OpportunityStage::allowedTransitions()`
  — a self-move and terminal→terminal are 422, `lost` requires a reason, entering `won`/`lost` stamps
  `actual_close_date` (reopening clears it + the lost reason), and every move stamps `stage_changed_at`,
  refreshes `last_activity_at` and writes a `system` activity (`OpportunityStageChanged`). `amount` and
  the owner stay manager+ (`can_set_amount`/`can_assign`). `GET /pipeline` returns the open stages as
  columns (count/value/weighted) plus the closed deals and a forecast `meta` (open & weighted value,
  win rate, average deal, stalled count from `crm.pipeline.stuck_days`, default 21).
  `GET /opportunities/{id}/timeline` + `POST /opportunities/{id}/activities` are the deal timeline
  (logging a touch refreshes `last_activity_at`, so staleness tracks real work). **`won` does not hand
  off to Service** — it surfaces **Create contract from deal**, which deep-links to the company contract
  upload prefilled with `opportunity_id`; the mocked Service handoff still fires only on `mark-signed`
  (ADR-0013 §5). Events: `OpportunityStageChanged`, `OpportunityWon`, `OpportunityLost`
  (`OpportunityCreated` now also fires on manual creation). The dashboard read endpoints are
  covered under module 06 below, and AI Management Reports (spec `15`) under module 07.
- **Module 03 — Communication History Management (slices S1–S5 — log & history, email, attachments,
  threads, templates):** the detailed,
  channel-aware history record beside `Activity` (ADR-0017). Flat `/api/v1/communications`
  (index/store/show/update/destroy + `POST /{id}/restore`) with `filter[channel|direction|status|
  subject_type|subject_id|company|contact|owner|date_from|date_to]` + `search` and a `summary` block
  (per-channel/direction counts + `last_contacted_at`), plus the nested history endpoints
  (`/leads|companies|contacts|opportunities|contracts/{id}/communications`, no pagination). A record
  block `{ type, id, label }` replaces the raw morph columns; `reference` is `COM-YYYY-NNNN`. A logged
  communication writes exactly one mirror `Activity` (`communication_id` links it, channel → activity
  type) and refreshes the record's `last_activity_at`, so the module 01/02 timelines need no endpoint
  change. A `draft` is the exception — it has not happened yet, so it carries no mirror and moves no
  clock. S1 accepts `sent|received|failed|logged`; S2 adds `draft` for an **outbound email only**, and
  module 04 slice S2 adds `scheduled` on the same terms; the default status is inbound → `received`,
  electronic outbound → `sent`, else `logged`. Visibility is inherited from the subject, so the
  `CommunicationPolicy` **only** delegates; editability is a state rule raised in the controller: a
  `sent`/`received` entry is immutable (422), a `draft`/`logged` one is the author's (or a manager+ in
  scope) to correct. Delete is soft by the author/manager+, restore is manager+. Each record's timeline
  keeps the merged feed. Events: `CommunicationLogged`, `CommunicationSent`, `CommunicationFailed`.
- **Module 03 slice S2 — the outbound email path (mocked):** `POST /communications/{id}/send` (200
  either way — a rejection is a delivery outcome, not an HTTP error), `POST /communications/{id}/retry`,
  and `POST /communications/sync-inbound` (**admin+**, the in-app twin of
  `php artisan communication:sync-inbound`). Email is mocked behind `EmailGateway` + `MockEmailGateway`
  (ADR-0005/0017 §4): `CRM_EMAIL_SIMULATE_FAILURE=true` forces the failure path, every id is derived from
  the message reference (so a repeated sync is idempotent), `meta.source: Email (mocked)` marks every
  gateway-backed payload, and the send response carries the record as it ended up (`sent` with
  `external_id`, or `failed` with `failed_reason`). Sending needs the subject's `update` right; a
  recipient is taken from the linked contact when none was typed, and no destination is 422. Sending
  stamps `occurred_at`, writes the timeline mirror and restarts the record's clock. Inbound is **pulled,
  never pushed**: the sync records the mock's reply as a `received` message on the record its original
  belongs to, drops a reply it cannot place, and never records one twice.
- **Module 03 slice S3 — attachments (the deferred polymorphic table):** `POST
  /communications/{communication}/attachments` (multipart), `GET /attachments/{id}/file` (authorized,
  streamed download), `DELETE /attachments/{id}`. The `Attachment` model fills the polymorphic
  `attachments` table `00-data-model.md` reserved (`attachable_type/id`, `disk`, `path`, `original_name`,
  `mime_type`, `size`, `uploaded_by`; no `deleted_at` — a file exists only for its row, and deleting the
  row deletes the bytes). Limits come from `config/crm.php` (`crm.attachments.disk|max_kb|max_files` —
  private disk, 10 MB, 5 files) with the contract MIME allow-list (pdf/png/jpeg). The stored path is
  **never** in the payload; the client only holds the attachment id. `AttachmentPolicy` inherits the
  record's rights (`view` delegates to the subject's `view`, `create`/`delete` to its `update`), so a
  salesrep works their own record's files, a manager the team's, an admin any. Whether a message still
  *takes* files is a **state** rule, not a permission (raised as 422 by `StoreAttachmentRequest` and the
  controller, via `Communication::allowsAttachmentChanges()`/`attachmentLockReason()`): files may change
  while the message is `draft`/`logged` **or** `failed` (a rejected send never left), and a
  `sent`/`received` message keeps what it carried. `CommunicationResource` carries `attachments[]`
  (when loaded), `attachments_count` (when counted, on the book and nested rows) and `can_attach`.
- **Module 03 slice S4 — threading (conversations):** `GET /communications/threads` (groups the caller may
  see, newest first: title, record, channel, `message_count`, `first_at`/`last_at` and a `last_message`
  block) and `GET /communications/threads/{threadId}` (the conversation, oldest first), plus
  `POST /communications/{id}/reply`. The thread id is the **root message's own id**: a message nobody
  answered has `thread_id = null` and is not a conversation, the first reply stamps the root, and
  `in_reply_to_id` names the immediate parent — so one indexed `thread_id` selects the whole exchange,
  original included. A reply is created into its parent's thread; the record is **inherited and
  prohibited** in the payload (`subject_type`/`subject_id` → 422), as are the channel (defaults to the
  parent's), the contact and the title line, while the direction defaults `outbound` (the caller is the
  one answering). A reply is an ordinary history entry otherwise: it reuses the store path's mirror
  activity + clock refresh, and may be saved as a `draft` for an outbound email. Answering needs `view`
  on the message **and** the subject's `update` right (`CommunicationPolicy::reply`); a message that never
  happened (`draft`/`scheduled`/`failed`) is a **422 state error** (`Communication::hasHappened()` /
  `replyLockReason()`), not a permission one — the same split as sending. A thread is visible only while
  **every** message in it is (a hand-built mixed record 404s; by construction a reply inherits its
  parent's record, so this equals the record's own visibility) and an unknown thread is 404. The S2 sync
  now files a pulled inbound reply **into** its original's conversation. `CommunicationResource` gains
  `in_reply_to_id` + an `in_reply_to` block and `can_reply`; the book/detail mark a threaded message.
- **Module 03 slice S5 — reusable templates + merge fields:** `/api/v1/communication-templates`
  (index/store/show/update/destroy + `POST /{id}/restore`), `POST /{template}/render` and
  `GET /communication-templates/fields` (the static segment declared before the resource route).
  A template carries `name`, `channel` (default `email`), `subject`, `body`, `is_active` and `created_by`;
  the name is unique among **live** rows — a validation rule rather than a DB constraint, because a soft
  delete frees it, and `restore` re-checks it (422) so an old template can never shadow a live one.
  **Every role reads and renders them; manager+ author/edit/retire/restore** (force-delete stays admin),
  the book exposing `can_update`/`can_delete`/`can_restore`. `render` takes
  `{ subject_type, subject_id, contact_id? }`, needs the subject's `view` right, and returns
  `{ channel, subject, body, unresolved[] }` — merge fields are `{{token}}` (`contact.name|first_name|
  position|email`, `company.name`, `opportunity.name`, `contract.title|reference`, `user.name|email`,
  `today`), resolved server-side from the record and the caller. A token that is **unknown or empty for
  the record is left verbatim and listed in `unresolved`** — the server never invents a value. Rendering
  is a **prefill only**: the saved message never references the template, so editing or retiring one
  changes nothing already logged, and only active templates enter the composer's picker. Seeded templates
  cover email/call/meeting plus one retired. **Not built here:** template categories, per-team libraries
  and scheduled sending (module 04).
- **Module 04 — Follow-up Reminder System (slice S1 — tasks & the worklist):** `GET /follow-ups`
  (read-only, paginated, `filter[owner|state|record_type|record_id|due_from|due_to]` + `search`,
  `counts` at the root), `POST /tasks`, `GET|PATCH /tasks/{id}`, `PATCH /tasks/{id}/status`. A task is
  an **`Activity` with `type = task`** — the module adds **no table** — carrying a `due_at` and the
  reserved `open`/`done`/`cancelled` status; `due` (`overdue|today|upcoming`, `days`, `overdue`) is
  **computed on read** against the PH business day (`FollowUpDueState`, `config/crm.php` `crm.timezone`)
  and never stored. `ActivityStatus::allowedTransitions()` drives the moves (a self-move and
  done→cancelled are 422; tasks are **cancelled, never deleted** — there is no delete route), and every
  write keeps the record's own `Lead::syncNextFollowUpAt()` honest so the lead list shows one
  `next_follow_up_at` column. `FollowUpResource` wraps `TaskResource` with a `kind`; a task's record is
  fixed once created (`subject_type`/`subject_id` are create-only) and `channel` is always null (rule 13).
  `TaskPolicy` = readable with its subject, editable/movable by **one person** (a salesrep their own, a
  manager the team's, an admin anyone's — ADR-0004); creating needs the **subject's** update right and a
  salesrep may only owe themselves (owner_id for another person is 403). The three record timelines
  (`/leads|companies|opportunities/{id}/…`) order still-owed tasks ahead of what happened
  (`scopeTimelineOrder`) and `ActivityResource` marks each with `is_follow_up`/`due_at`/`due`. **Not
  built here:** the scheduled message (S2), the due dispatch + notifications (S3) and the generated
  reminders (S4).
- **Module 04 slice S2 — scheduled outbound messages:** a follow-up's second shape is a
  `Communication` with `status = scheduled` (the status ADR-0017 §3 reserved) carrying a
  `scheduled_for` — `occurred_at` became **nullable** and is stamped only when the message really
  leaves, so a scheduled message writes **no** mirror `Activity`, moves no `last_activity_at` and is
  excluded from `last_contacted_at`. Only an **outbound email** can be scheduled (422 otherwise) and
  it needs a future `scheduled_for` (`after:now`), a subject and a recipient (typed address or a
  contact with an email); a `draft` keeps its old stamp, only `scheduled` is left null. A **reply can
  be a draft but never scheduled** (`StoreReplyRequest` filters it out — a conversation is answered,
  not put off), and the composer offers the status only while not replying. `POST
  /communications/{id}/send` now takes a scheduled message too (**Send now**, keeping
  `scheduled_for`), `POST /communications/dispatch-due` (**admin+**, twin of
  `php artisan communication:send-due`) sends what has fallen due through the mocked gateway once —
  `Communication::dispatchDue()` returns `{attempted, sent, failed}` and a second run sends nothing —
  and `GET /communications` gained `filter[due]=overdue|today|upcoming` plus a `summary.scheduled`
  count. `GET /follow-ups` is now the **one read-only worklist over both shapes**: a SQL **union** of
  open tasks and scheduled messages (`filter[kind]=task|message`, state per half, `counts` over the
  open union), rows shaped by `FollowUpResource` (tasks via `TaskResource`, messages via the new
  `ScheduledMessageResource`), and `Lead::syncNextFollowUpAt()` takes the **sooner** of the two.
  Messages are sent or **soft-deleted** (cancel), never completed (`can_transition` is always false);
  `CommunicationPolicy::dispatch` is admin+.
- **Module 04 slice S3 — notifications & the due run:** `activities.reminded_at` is the reminder
  marker. `FollowUpAssigned` fires when a task is **created for someone else or reassigned**
  (`TaskController`), and `NotifyFollowUpOwner` tells the new owner — nothing is sent when it is the
  actor's own work. `Activity::notifyDue()` (over `scopeDueForReminder`: `status = open`, `due_at`
  passed, `reminded_at` null) raises `FollowUpDue` per task and **stamps** it, so
  `POST /follow-ups/dispatch-due` (**admin+**, `TaskPolicy::dispatchDue`, twin of
  `php artisan follow-ups:notify-due`) returns `{notified}` and a second run flags nothing; a
  **reschedule clears `reminded_at`** (`TaskController::update`), re-arming the task. A settled task
  is never flagged. Every database notification now carries a **`url`** the bell opens (a lead, a
  follow-up, a message) — `NotificationController` passes it through and `NotificationBell` prefers it
  over `lead_id`; `LeadAssignedNotification` gained one too. `CommunicationFailed` gained an
  **`actor`** (the user who pressed send, or null on an unattended dispatch) and
  `NotifyAuthorOfFailedSend` tells the message's author only when that actor is somebody else —
  a rep staring at their own rejected send is not notified twice.
- **Module 04 slice S4 — generated reminders:** the reminders the records imply, raised on demand.
  `Activity::generateFollowUps()` (twin of `php artisan follow-ups:generate`, exposed as
  `POST /follow-ups/generate`, **manager+** via `TaskPolicy::generate`) creates three kinds of task —
  a **renewal** for each signed contract inside `crm.contracts.expiring_soon_days` (due a week before
  `end_date`, 09:00 PH), a **check-in** for each client with no communication since it came aboard
  (older than the new `crm.follow_ups.check_in_days`, default 45, measured from `client_since` when
  never contacted; churned clients included) and a **stale lead** (`crm.aging.stale_days`) — each
  owned by the record's own owner and due the next morning, 09:00 PH. `FollowUpSource` names the three
  and builds the key. A new nullable-unique `activities.source_key` (`renewal:{contract}`, no bucket;
  `check_in:{company}:{ISO-week}`, `stale_lead:{lead}:{ISO-week}`) makes a repeat run a no-op while
  the next ISO week may raise the next check-in. It **never invents an owner** and never piles onto
  work already in hand: a record with no owner, one whose key already stands, or one somebody has an
  **open hand-written task or scheduled message** on is skipped and reported (its own generated tasks
  never block the next week). The run returns
  `{created, skipped, sources:[{key,label,created,skipped,tasks,skips:[{record,reason}]}]}`, each
  created task dispatches `FollowUpAssigned` with `actor = null` so its owner is told, and
  `TaskResource` gained `source`/`source_key` (`ScheduledMessageResource` carries them as null for
  shape parity).
- **Module 05 slice S1 — the survey library:** `/api/v1/surveys` (index/store/show/update/destroy +
  `POST /surveys/{id}/restore|duplicate`) holds reusable `Survey` templates and their ordered
  `SurveyQuestion` set — five types (`rating` 1–5, `nps` 0–10 with **at most one per survey**,
  `single_choice`, `multi_choice`, `text`; the choice types carry 2–10 options and the others take
  none). A survey is a **draft** (`is_active` false) until activated and cannot be active without a
  question; questions arrive with the survey and are replaced wholesale on edit, the array order being
  the asking order (S2 freezes the set once an invitation exists). The name is unique among **live**
  surveys — a validation rule, because retiring is a soft delete — and `restore` re-checks it (422);
  `duplicate` copies the questions into a new inactive survey under the next free "… (copy n)" name,
  the way to version one. **Manager+ authors/edits/retires/restores/duplicates; every role reads**
  (ADR-0019 §8). Events: none of its own.
- **Module 05 slice S2 — invitations & the tokenized public form:** `GET /survey-invitations`
  (index/show, `filter[state|survey|company]` + `search`, scoped by the account),
  `POST /surveys/{survey}/invitations`, `POST /survey-invitations/{id}/cancel|resend`,
  `GET /companies/{company}/survey-invitations`, and the CRM's one unauthenticated surface —
  `GET /public/surveys/{token}` + `POST /public/surveys/{token}/responses` (throttled, outside
  `auth:api`). Sending needs the **account's** `update` right, not the survey's, and **rides module
  03**: the mocked `EmailGateway` hands the message out and a `Communication` keeps it, so there is no
  `SurveyInvitationSent` event, a gateway rejection is a `failed` invitation (still 201) that `resend`
  retries, and the account's clock/mirror move through `CommunicationSent` as for any logged email.
  One `Communication` per invitation, refilled with a fresh window on `resend`. The token is 48 chars,
  single-use and **never the ULID**; the link origin is `crm.surveys.public_url` (`CRM_SURVEY_URL` →
  `FRONTEND_URL` → localhost). Stored statuses are `sent|failed|submitted|cancelled`; `expired` is
  derived from `expires_at` (`isAnswerable()`), and cancel/resend are 422 on a submitted or cancelled
  row (withdrawing is the sender's, or manager+). An invitation is **history — cancelled or resent,
  never deleted**. The client's page returns only that survey's questions (rating 1–5, NPS 0–10,
  options as offered); a submission is validated per question type (required, ranges, offered options;
  errors keyed `answers.{questionId}`), stored **once** by `SurveyResponse::record()`, scored there
  (CSAT = mean rating, NPS = the promoter answer, band 9–10/7–8/0–6) and flagged `needs_attention` at
  or below `crm.surveys.attention_csat_max` (3) or `attention_nps_max` (6) — the flag S4 acts on. Then
  the token dies: a second submit is 410, an unknown token 404, submitted/expired/cancelled/failed
  410. `SurveyResponseSubmitted` writes a `system` activity on the account. The question set freezes
  the moment an invitation exists (422 when `questions` is sent at all — name/description/active still
  save), and `SurveyResource` carries `invitations_count` + `questions_editable`. The closed loop that
  acts on the flag is slice S4 (below).
- **Module 05 slice S3 — the response book & the satisfaction view:** `GET /survey-responses`
  (`filter[survey|company|band|attention|date_from|date_to]` + `search`, scoped by the account's
  visibility), `GET /survey-responses/{id}` (the answers in asked order, each with its question),
  `GET /satisfaction/summary` and `GET /companies/{id}/satisfaction`. A response is immutable — no
  update, no delete route (ADR-0019 §7). Nothing is stored twice: `SurveyResponse::metrics()` derives
  CSAT (the mean of the stored per-response scores), NPS (promoter share − detractor share over the
  answers that carried the promoter question, rounded), the band counts and the attention count in
  SQL, and the controller pairs in the **response rate** — submitted answers ÷ answerable sends,
  where a failed or withdrawn invitation is not in the denominator (an `expired` one is: it was
  answerable while its window was open). A response with no NPS answer is excluded from NPS and the
  bands but still counts for CSAT and the book. The `SurveyResponsePolicy` only delegates to the
  account, so the detail is 403 outside the caller's scope and the book lists what they may see (a
  salesrep their own accounts, a manager the team's, an admin all). `SurveyResource` gained
  `responses_count`.
- **Module 05 slice S4 — closed-loop follow-through:** an unhappy answer raises the loop inside
  `SurveyResponse::record()`: `SurveyResponseFlagged` → `RaiseSurveyFollowUp` sends **one** database
  notification to the account owner (type `survey_attention`, carrying a `url` to the response) and
  creates **one** `Activity` task on the company, owned by that owner, due next morning 09:00 PH, with
  `source_key satisfaction:{response}`. A repeated flag is a no-op (the key guards it), an account
  with no owner is left alone, and the task subject names the score
  (`Call back Sunrise Foods Inc — CSAT 2 / 5`). `POST /survey-responses/{id}/resolve` (owner or
  manager+, `SurveyResponsePolicy::resolve` delegating to the account's `update` right) requires a
  `note`; a second resolve and a response that did not need attention are 422. Resolution records
  `resolved_at` / `resolved_by` / `resolution_note` without clearing `needs_attention`, and
  `SurveyResponseResolved` writes a `system` activity with the note. **Resolving does not complete the
  task** — the call may happen later, so the worklist stays honest. `attention` now means
  **unresolved**: the summary count and `filter[attention]=true` use `scopeUnresolvedAttention`,
  `/satisfaction` carries `attention_responses` (the unresolved call-back list), and
  `SurveyResponseResource` exposes `resolved`, `resolved_at`, `resolved_by`, `resolution_note` and
  `can_resolve`.

- **Module 06 — Dashboard & Management Reports (D1 + D2 — built):** the cross-cutting read layer
  over modules 01–05 (`resources/specs/14-dashboard-management-reports.md`, ADR-0020), shipped as
  D1 after module 05 S3 and before S4 for the presentation, then D2 in five phases. D2's read
  endpoints — `GET /dashboard` (scope, KPIs, charts, alerts, activity, rules-based `ai`),
  `GET /dashboard/worklist` and `GET /dashboard/staff-performance` — are role-scoped (salesrep →
  own book, manager → team, admin → org), derived at read time, and add no table. The frontend
  calls the three in parallel and formats raw values in `frontend/src/lib/dashboard.ts`; the D1
  fixture file is deleted. The header carries a working period toggle (30 days / quarter / year to
  date) and the scope line. Blocks: 4 primary + 4 secondary KPI cards (all clickable, trend + hint),
  a line chart (leads/qualified/won), a donut (open pipeline by stage), a **needs-attention
  worklist** (severity, subject + detail, type, owner, action), alerts, recent activity, a **staff
  scorecard** pairing CRM output with **core2 HR** figures (attendance, leave, late arrivals via
  `HrGateway`/`MockHrGateway`; `hr_available: false` reads `—`, never `0`), and the rules-based
  **AI management summary** (headline, narrative, 3 insights, 4 actions — all linked). The page
  handles loading, empty, error/retry and missing-HR states. Shared components: `ChartCard`,
  `LineChart`, `DonutChart`; `StatCard` gained optional `to`/`icon`/`hint`/`trend`. The dashboard
  AI card links to Module 07; it does not call a provider.
- **Module 07 — AI Management Reports (S1 + S2 built):** a read-only presentation module over the
  dashboard data (`resources/specs/15-ai-management-reports.md`, ADR-0021, ADR-0022), with its own
  `/reports` page and **AI Reports** sidebar group. **S1** ships the rules-based Executive, Pipeline
  and Client Health variants through `GET /reports/management` (`variant`, `range`; defaults
  executive/30 days), scoped like the dashboard, with no provider call. The response always carries
  `source`, `model`, `generated_at`, `cached` and `fallback`, plus `meta.ai_available` and the
  available variants; each report has a headline, narrative, exactly three insights and four
  actions, all linking to existing pages. **S2** adds `POST /reports/management/generate`: Laravel
  builds an aggregated, PII-free context and calls the `ai/` service (`POST /reports/dashboard`),
  which calls Groq's free tier (`openai/gpt-oss-20b`, JSON mode, 2.5 s provider timeout) and returns
  fixed insight/action IDs that Laravel maps to real routes. The 10-second Laravel timeout (one
  retry on a connection timeout, because a public AI service may cold-start), 15-minute
  context-hash cache, 5-minute per-user/variant refresh cooldown, 30/user/day and 800/org/day caps
  are cache counters, not a table; `refresh: true` bypasses the cache and a second try inside the
  cooldown is 429. Every failure (disabled switch, missing service token or key, 429, timeout,
  provider error, invalid JSON/IDs) returns a complete rules report with a `fallback.reason`, and
  the page shows an info alert. A rejected service token is `service_unauthorized`; an unreachable
  service is `service_unreachable`; both are logged upstream. `GET` returns cached model prose when
  it exists, otherwise rules, and never calls the provider. No names, emails, notes or message
  bodies leave Laravel. The deployed `ai/` service may be public, but `POST /reports/dashboard`
  requires the shared `AI_SERVICE_TOKEN` bearer credential (constant-time compare; `/health` stays
  public); a missing service token falls back with `not_configured` rather than calling anonymously. No table, no
  migration, no module-01–05 rule change. `Team Performance` is deferred; record catch-up summaries
  and message drafting are later submodules, not S1/S2.

Frontend routes: `/dashboard` (the role-aware home: greeting + scope + period toggle, 8 KPI cards,
line + donut charts, needs-attention table, alerts/activity, staff scorecard with core2 HR figures
and the rules-based **AI management summary**, reading the D2 endpoints with no in-app simulation
badge; the AI card links to `/reports`), `/reports` (Module 07: Executive / Pipeline / Client Health
tabs, period toggle, standard report with provenance, **Generate with AI**, the cached **Refresh AI
report** path, the cooldown notice and the fallback info alert),
`/login`,
`/leads` (views: All / My Leads / Unassigned / Team / Archived, inline
owner control, bulk assign, status/rating/aging columns, "Stale only" filter), `/leads/new`,
`/leads/:id` (qualification panel + lifecycle control + ownership + activity form + timeline +
"Convert to client" when convertible, converted banner), `/leads/:id/convert` (3-step conversion
wizard), `/qualification` (worklist), `/assignment` (unassigned queue, team workload, bulk transfer),
`/lifecycle` (aging / stale triage worklist), `/conversion` (qualified leads awaiting conversion),
`/clients` (client book: accounts past prospect, lifecycle/tier filters, contract counts, links to
the account), `/companies` (account book: status/tier filters and
pagination), `/companies/new` (manual creation), `/companies/:id` (Overview / Contacts / Sites /
Opportunities (row links + "Add deal", engagements summary links to the board) / Contracts /
Communications / Satisfaction / Activities tabs; the Satisfaction tab carries the account's card
(latest CSAT/NPS, the four numbers, the promoter split, a CSAT trend, **Read every answer**) and
lists its survey sends (state, recipient, sent/closes, **Copy link**, **Resend**, **Withdraw**, the
score once answered) and, for whoever may update the account, **Send survey** opens the dialog — active survey,
contact (or a typed address), optional contract and note — and reports a mocked rejection; sites and branch links are manager-only,
contacts are editable by whoever owns the account, the Overview carries the client-lifecycle panel,
the failed-handoff retry banner and the manager-only billing snapshot), `/contacts` (global contact
book: search + role/status/
company filters, rows link back
to their account), `/pipeline` (Kanban board: open-stage columns with count/value/weighted totals,
native drag-and-drop + a keyboard "Move to…" menu per card, a Lost-reason dialog, a Closed column for
won/lost, owner/close-window/search filters and a forecast header), `/opportunities` (deal book:
filters, pagination, summary), `/opportunities/new` (deal created directly on an account),
`/opportunities/:id` (edit form, stage control, deal timeline + log-activity, linked contracts and
"Create contract from deal" when won). `/companies/:id?tab=contracts&opportunity_id=…` opens the
contract upload prefilled from a won deal. `/communications` (history book: summary cards, channel/
direction/status/**due**/record-type/owner/date filters — the status picker names the scheduled count
— a `?subject_type=&subject_id=` deep link and an admin
"Pull replies" action), `/communications/new` (log form — a **Draft** status for an outbound email,
prefilled when a record links to it, plus **Schedule for later** on an outbound email: the "When"
becomes a **Goes out** moment and the button reads **Schedule message**) and `/communications/:id`
(detail, inline correction of what the
author logged — a scheduled message is rescheduled, not back-dated — **Send now** on a draft or a
scheduled message, **Retry send** on a rejected one, the simulated-mailbox note,
an **Attachments** panel — list, **Attach file**, download and remove, offered only while the API says
`can_attach` — and retire/restore), `/communications/threads` (conversation book: title, record, channel,
message count and the last word, narrowed by channel/record/owner + search) and
`/communications/threads/:threadId` (the exchange read back in order, each message a card naming the
message it answers, with **Reply** where the API allows it and **Open message** through to the record);
`/communications/new?reply_to=…` starts the log form from a message (record/channel/contact/title
inherited, picker locked, **Log reply** / **Save draft reply**); the composer (log and reply alike)
carries **Start from a template**, which renders an active template's channel, title line and body
against the chosen record and names anything it could not fill; `/communications/templates` is the
template book (name, channel, title line, active state, author; search + channel/active filters) with
**New template** / **Edit** for manager+, **Retire** on live rows and **Restore** on the retired ones, and
`/communications/templates/new` + `/communications/templates/:id` the form, its merge-field chips
inserting at the caret of the field last used; the message detail carries **Reply**,
**In reply to** and **Part of a conversation** links; the book and the record history panel mark a message
that carries
files; the account detail gains a
Communications tab with a per-contract "Log" action, and leads and deals carry a Communications
section. `/follow-ups` (the worklist: four stat cards — Overdue / Due today / Upcoming / Completed —
over a table of due state, subject, **kind** (Task / Email), record link, owner and status, with
**Complete** /
**Reschedule** / **Cancel** on an open task row, **Reopen** on a finished one, and **Send now** /
**Open** / **Cancel** on a scheduled message row (which names its recipient); Everyone / Mine / My
team
tabs, state + kind + record-type + search filters and a `?subject_type=&subject_id=` deep link; the
manager header action **Generate reminders** with its per-source summary panel (created, skipped and
why) and the admin ones **Dispatch due reminders** and **Dispatch due sends** with the no-timer note;
a generated row carries a **Generated** badge naming its source),
`/follow-ups/new` (put something owed on a lead, account, contact or deal: record picker, title, notes,
due moment defaulting to tomorrow 09:00, and an assignee picker for manager+) and `/follow-ups/:id`
(the same form in edit mode — the record shown, not re-pointed — plus the status control). The lead
list carries a **Next follow-up** column, leads/accounts/deals carry a **Follow-up** quick action, the
contracts tab offers a per-contract one, and the three timelines mark an owed task with its due state
and due moment. The topbar bell lists the assignment and due notifications, each opening the item it
is about (the follow-up, the message, or the lead it hangs off). `/surveys` (the library: search +
active/draft/retired filters, question and **send** counts, author per row, **Duplicate** / **Retire** /
**Restore**, **New survey** for manager+ and an **Invitations** link), `/surveys/new` and
`/surveys/:id` (the form plus the **question builder** — add/move/remove, type, prompt, help,
required, options for the choice types — with a live **Preview** of the client's page; a retired
survey opens read-only and a **sent** one shows its questions locked with *Duplicate into an editable
copy* and still saves its wording), `/survey-invitations` (the book: state/survey filters + search,
**Copy link**, **Resend**, **Withdraw**), `/satisfaction` (the summary: four cards — CSAT, NPS,
response rate, needs attention — the promoter split, the unresolved call-back list and the latest
answers), `/satisfaction/responses`
(the book: survey/band/attention/date filters, search and a `?company=` deep link; a resolved answer
is badged) and
`/satisfaction/responses/:id` (one answer read back in the asked order, with its score block, the
send, the attention notice and the **Resolve** panel — a required note for the owner/manager+, then
when/by whom/by note), and `/survey/:token` — the client's page, **outside the
dashboard shell and without login**, rendering the questions by type (1–5 row, 0–10 NPS row, radios,
checkboxes, textarea) with a thank-you state and a plain reason for a dead link. The sidebar
is **grouped by module** — module 01's submodules under `Lead & Client Tracking`, module 02's screens
under `Opportunity Pipeline Visualization`, module 03's slices under `Communication History
Management`, module 04's under `Follow-up Reminder System`, module 05's under `Client Satisfaction &
Survey`, and module 07's under `AI Reports`. Account + logout live in the sidebar footer;
the brand is
"Primepower Manpower Services / Customer Relationship Management".

**Module 03 is complete — S1–S5 built.** Module 03's five slices: **S1 log &
history**, **S2 email compose/send/retry through a mocked gateway**, **S3 attachments
(the deferred polymorphic `attachments` table)**, **S4 threading (conversations, replies
and the thread view)** and **S5 templates + merge fields (a prefill for the composer, never a link back
from the message)**.
`Activity` stays the
lightweight quick log; `Communication` is the detailed record and every logged communication also writes
an activity, so the existing timelines stay the merged feed. Still deferred: contract
versioning/e-signature, **automatic client aging** (ADR-0018 §6 — once assumed to land with module 04,
it does not), duplicate detection & merge (a
module-01 deferral, ADR-0006). `/leads/{id}/status` cannot reach `converted`; conversion is the only
path (ADR-0009). `Company::deletionBlockers()` carries both guards (open deal, signed contract) —
ADR-0010 §6.

**Module 04 is complete — S1–S4 built.** `resources/specs/12-follow-up-reminders.md` declares four
slices (S1–S4, ADR-0018) and all four have landed: **S1 (follow-up tasks + the worklist), S2
(scheduled outbound messages + the due dispatch), S3 (notifications & the due run) and S4 (generated
reminders)**. The module adds
**no table**: a follow-up is an `Activity`
(`type=task` + `due_at` + `open/done/cancelled`) or a `Communication` with the reserved
`scheduled` status (`scheduled_for`; `occurred_at` is nullable and is stamped when it really
leaves), and `GET /follow-ups` is one read-only worklist over both — a union of the two, narrowed by
`filter[kind]`.
Nothing runs in the background — due sends, due reminders and the generated reminders are dispatched
on demand (`communication:send-due`, `follow-ups:notify-due`, `follow-ups:generate`) exactly like
the mocked inbound sync, and `reminded_at`/`source_key` keep repeated runs idempotent.

**Demo accounts** (password `password`, via `php artisan migrate:fresh --seed`):
`salesrep@primepower.test`, `salesrep2@primepower.test` (same team), `manager@primepower.test`,
`admin@primepower.test`, `superadmin@primepower.test`. Seeded client data covers every lifecycle
state: Harbor Logistics (signed renewal chain, handoff accepted), BPO Solutions PH (signed, handoff
failed → retry banner), Sunrise Foods Inc (contract out for signature), Greenfield Hotels
(terminated/churned) and Greenfield Hotels Cebu (draft). The seeded pipeline places deals in **every**
stage (e.g. Sunrise `discovery`/`proposal_sent`, BPO `negotiation`/`on_hold`, Harbor `negotiation` and
`won`, Greenfield `lost`, Greenfield Cebu `new_inquiry`/`qualified`) owned across Sam/Liza/Maria, so
the board, the forecast header and the win rate demo without faking. The seeded communication book
spans email/call/meeting in both directions across Sunrise, BPO and Harbor, and leaves Greenfield
Hotels silent so "last contacted" and the stale worklists have something to show. The seeded book also
carries one send the mocked gateway rejected, so the retry path demos without touching config, and every
seeded message that went out holds the gateway id — so an admin **Pull replies** (or the artisan command)
adds only what is genuinely new. Two placeholder scans (a valid tiny PNG, so a demo download opens) ride
on the book: one on the Sunrise rate-sheet email — a `sent` message, so it shows the locked side of S3 —
and one on the Sunrise commissary call, which is `logged`, so another file can be attached live. S4 seeds
**two real conversations** — the BPO "commercial terms" email with the procurement answer that came back,
and the Sunrise rate-sheet email with the client's approval and Sam's reply (three messages, each pointing
at the message it answers) — so the conversation list, the thread view and the "last word" demo without
answering anything first. S5 seeds the **house's wording** — a rate-sheet follow-up and a site-visit
confirmation (email), a cold-call script (call) and a meeting agenda (meeting), written with
`{{contact.first_name}}`/`{{company.name}}` — plus an **inactive** `Holiday greeting` (kept but out of the
picker) and a **retired** `Q3 rate increase notice` (soft-deleted), so the book's active / inactive /
retired states and **Restore** all demo from real rows. Module 04 S1 seeds **six follow-up tasks** across
every state it can show — a Sunrise rate-sheet task overdue, a BPO 40-seat start-date task due today
(Liza's, on a deal Sam owns, so "visible with the record" and the team's workload both show), a BPO
contract chase and a Harbor forklift-shortlist task upcoming (the latter on the lead, so the lead list's
**Next follow-up** column and the lead timeline demo unseeded), plus one done and one cancelled task on
Sunrise — so the worklist's counts, its overdue/today/upcoming tabs and the complete/cancel/reopen paths
need no setup. Module 04 **S2 seeds two scheduled emails** — a Sunrise rate-sheet follow-up due
tomorrow 09:00 PH (Sam's) and a BPO contract chase **overdue** since yesterday (Liza's, on Sam's
account, so the "visible with the record, owed by someone else" split shows too) — both with no mirror
activity, so **Send now** demos on one and an admin **Dispatch due sends** sends the overdue one
through the mocked gateway live. Module 04 **S3 hands two of those tasks over** the way the API does
it — Liza's two seeded tasks are dispatched from Sam — so her bell opens with two real assignments
while Sam's holds the survey attention item (below), and an admin **Dispatch due reminders** on the worklist flags what has come
due without a second run adding anything. Module 04 **S4 seeds nothing of its own** — it reads the
data already there, so a manager's **Generate reminders** on a fresh database reports **3 created,
2 skipped**: a renewal on the signed Harbor contract (Liza), a check-in on Greenfield Hotels and a
nudge on its stale lead (both Sam), while the unowned Metro Builders lead and the Harbor lead (which
already owes S1's forklift task) are reported as skipped. A second run creates nothing, so the
worklist demos both the generator and its idempotence. **Module 05 S1 seeds the survey library** —
three active surveys (post-deployment check-in, quarterly account review, exit survey), a **draft**
pilot and a **retired** referral pulse — so the book's state filters, **Duplicate** and **Restore**
demo from real rows. **Module 05 S2 seeds five invitations and two responses** the way the API
writes them (a real `Communication` each, responses through `SurveyResponse::record()`, dates
backdated): a submitted check-in for Harbor (CSAT 5 / NPS 10) and for Sunrise (CSAT 2 / NPS 4, the
attention item S4 acts on, flagged at submit), an **open** one for BPO — its link is the live demo
of the public form — an **expired** one for Greenfield Hotels and a **cancelled** quarterly review for
Sunrise, so every state is on the page. S3 reuses those two answers for its summary; S4 marks the
Sunrise one as unresolved work through the real path — its answer raises the owner's bell and the
`Call back Sunrise Foods Inc — CSAT 2 / 5` task — so resolving it is the demo.

## Conventions that differ from framework defaults

- **Auth is JWT, not cookies.** Required because frontend and backend are on different origins —
  do not fall back to Laravel Sanctum's cookie/session auth.
- **Git:** branches are named `feature/<name>` and merge into `main` (`feature -> main`); there is
  no `develop`. Commit messages follow **Conventional Commits** (`feat:`, `fix:`, etc.).
- **Delivery unit is the slice, not the spec file** (ADR-0016). From module 02 on, one spec file covers a
  module and declares its **delivery slices** (each demoable end to end, with its own acceptance
  criteria). Planning docs (spec + ADRs) land first; then **one PR per slice**, merged and the branch
  deleted. Module 01 predates this: one file and one PR per submodule. A module whose work is genuinely
  one increment says so in the spec instead of splitting (module 02 = one slice; module 03 = five).
- **Seeds:** do **not** use faker. Data is seeded for a production/demo proposal, not real business.
- **Other-department integrations are mocked in `backend/app/Integrations/`** (ADR-0005, ADR-0013 §4):
  an interface + a `Mock*` driver (bound in `AppServiceProvider`) + `final readonly` DTOs. This is the
  one deliberate new base folder under `backend/app/` — do not add others without approval. Driver
  behaviour is deterministic, never faker: `CRM_HANDOFF_SIMULATE_FAILURE=true` makes the Service
  handoff fail (that is how the retry path is demoed and tested), and the Finance billing snapshot is
  derived from the company reference.
- **Icons:** use `lucide` (lucide-react) only.
- **Locale / currency is PH-based:** `en-PH`, `Asia/Manila` (store UTC), dates `DD MMM YYYY`, PH
  address/phone formats, and **single currency PHP (₱)** — no multi-currency.
- **Ownership scoping** is enforced server-side on every lead query and policy check; the shared
  unassigned queue is the one documented exception to salesrep "own records only" (ADR-0004).
- **IDs are non-sequential ULIDs** on every URL/list-exposed entity — never expose
  auto-incrementing IDs in the API/URL (ADR-0007). (`users.id` is the one exception: the seeded
  staff table uses bigint ids, never routed as URL-exposed resources.)

## Mandatory design system

Announced in `resources/departamental-design-instruction.md` (agreed default, pending final client
sign-off). Full color values: `resources/design-tokens.md`.

- Tokens live in `tailwind.config.js` and `index.css`. **No hardcoded hex or arbitrary px** —
  tokens/utility classes only. Light + dark mode values are both defined. Tokens are hex `var()`s,
  so opacity modifiers (`bg-counts/15`) resolve through `color-mix` — see the `withAlpha` helper in
  `tailwind.config.js` (Tailwind v3 cannot alpha a plain `var()` colour).
- Fixed color mapping per data domain: **blue = counts, green = people, purple = activity,
  orange = cost.** Brand logo text uses `logo-brand`; lead rating uses `rating-hot|warm|cold` — all
  documented in `resources/design-tokens.md`.
- Shared components live in `frontend/src/components/common/` (required list in its `README.md`)
  and `frontend/src/components/layout/`. Reuse before creating; anything used by 2+ subsystems/roles
  belongs in `common/` — no duplicate implementations. Built so far: DashboardShell, Sidebar, Topbar,
  PublicLayout, PageHeader, StatCard, StatusBadge, AlertItem, FormCard, EmptyState; the rest are
  added as features need them.
- Layouts: public site uses `PublicLayout` (no sidebar); **all** dashboards (User/Admin/Superadmin)
  share one `DashboardShell` differentiated only by a `role` prop. Per-role layouts are not permitted.
- Standard dashboard home rows: 4 primary StatCards, then 4 secondary StatCards, then a chart
  section (line + donut), then activity list + alerts list.
- When generating/editing UI, supply the design-system context so output stays aligned.

## Roles (`resources/system-roles.md`)

`superadmin` (low-level/system access) > `admin` (app settings) > `manager` (oversees salesreps,
view-heavy) > `salesrep` (main user, heavy operations; sensitive data hidden and must escalate).

## Scope

This repo implements the **Customer Relationship Management** subsystem (see
`resources/system-modules.md`): lead/client tracking, communication history, client satisfaction &
survey, follow-up reminders, opportunity pipeline visualization. `resources/list-of-departments.md`
lists the wider org systems for context — they are **not** in scope here.

Design docs an agent should read before touching CRM features:

- `resources/business-flow.md` — business context and end-to-end flow
- `resources/crm-flow.md` — object model, pipelines, submodules, integrations, role visibility
- `resources/specs/` — per-submodule implementation specs (data model, API, UI, acceptance criteria)
- `decisions/` — accepted design decisions (ADRs)
- `DEPLOYMENT.md` — local vs deployed environment variables, Neon Postgres, per-app build/start
  commands, persistent storage and CORS

Before implementing a submodule, read `resources/specs/README.md` (conventions) and
`resources/specs/00-data-model.md` first; each submodule spec builds on them.

**Modules 01 and 02** are built: module 01 (Lead & Client Tracking) covers lead capture,
qualification, assignment, lifecycle, company/contact, lead→client conversion and client lifecycle;
module 02 (Opportunity Pipeline Visualization) covers the deal book, the Kanban board, stage
moves/won-lost and the board forecast. **Module 03** (Communication History) is **complete** — its five
declared slices (S1 log & history, S2 mocked email send/retry, S3 attachments, S4 threading, S5 templates)
are all built. **Module 04** (Follow-up Reminders) is **complete** — `resources/specs/12-follow-up-reminders.md`
declares four slices (ADR-0018) and all four are built: **S1 (tasks + the worklist), S2 (scheduled
outbound messages + the due dispatch), S3 (notifications & the due run) and S4 (generated renewals,
check-ins and stale-lead nudges)**; its worklist
reuses `Activity` and the reserved
`CommunicationStatus.scheduled` rather than adding a table. **Module 05** (Client Satisfaction &
Survey) is **complete** — `resources/specs/13-client-satisfaction-survey.md` declares four slices
(ADR-0016, ADR-0019): **S1 survey library, S2 invitations + the tokenized public form, S3 responses
& scoring, S4 closed-loop follow-through** — and all four are built. The module adds
five tables (`surveys`,
`survey_questions`, `survey_invitations`, `survey_responses`, `survey_answers`) and reuses module 03
for delivery and module 04 for the follow-up an unhappy answer raises. The module roadmap is
**ADR-0014**; AI reports are **ADR-0021**.
**The dashboard layer** (`specs/14`, ADR-0020) is **D1 + D2 built** — a role-aware `/dashboard` over
modules 01–05 with KPI cards, line + donut charts, a needs-attention worklist, alerts/activity, a
staff scorecard carrying core2 HR figures through the `HrGateway` mock, and the rules-based **AI
management summary**. D2 replaced the D1 fixtures with `/dashboard`, `/dashboard/worklist` and
`/dashboard/staff-performance`; the fixture file is deleted and the page handles loading, empty,
error/retry and missing-HR states. **Module 07** (`specs/15`, ADR-0021, ADR-0022) has **S1 + S2
built**: the `/reports` page, `GET /reports/management`, the three rules variants, the dashboard
link and `POST /reports/management/generate` behind the authenticated `ai/` Groq seam (shared
`AI_SERVICE_TOKEN`) with cache, cooldown, daily caps and the rules fallback. S3/S4 (record catch-up
summaries, message drafting) are future submodules.
Dedup/merge and advanced tiers are module-01 deferrals
(ADR-0006), and automatic client aging stays one too (ADR-0018 §6). Other departments are consumed via
**mocked integrations** (ADR-0005), not built here.
