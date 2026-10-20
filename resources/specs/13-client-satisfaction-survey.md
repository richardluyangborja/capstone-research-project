# 13 — Client Satisfaction & Survey

Module 5 (ADR-0014). Entities: `Survey`, `SurveyQuestion`, `SurveyInvitation`, `SurveyResponse`,
`SurveyAnswer` (new); `Communication`, `Activity`, `Company`, `Contact`, `Contract`, `User` (reused).
ADRs: 0004, 0005, 0007, 0013, 0014, 0016, 0017, 0018, **0019**.

> **Slices S1–S4 declared; S1–S3 built, S4 deferred behind the dashboard (ADR-0020).** The module
> ships one slice per PR (ADR-0016 §2). The client
> answers through a **tokenized public link** (no login) that the invitation emails, built from
> configuration so local and production differ only by an env value (ADR-0019 §4). A survey is a
> reusable template frozen once it has been sent; an unhappy answer closes the loop automatically
> through module 04 (task + bell). Nothing runs on a timer — sending is a deliberate act by a user,
> not a scheduler.

## Purpose

Close the loop after the sale. Modules 01–04 win the client, track the deal, log every touch and
keep the promises; none of them asks the client whether any of it was any good. A staffing agency
lives on renewals and referrals: a dissatisfied account that nobody hears from is a renewal lost
six months later, and a delighted one is the best new-lead source the agency has.

This module gives the CRM a **survey library**, a way to **send a survey to a client** with a link
they can open without an account, a **response record with CSAT/NPS scoring**, and a **closed loop**
—unhappy feedback becomes a task and a notification, not a row nobody reads. It feeds the AI module's
management reports (satisfaction trends per account and per team) and the account page's health
picture.

**Not built here:** automatic sending on contract signing / renewal / churn (an invitation is always
a deliberate act in Phase 1), recurring campaigns and reminder emails, per-question analytics
(word clouds, cross-tabs), incentives, multi-language surveys, anonymous responses (every response
belongs to its invitation and account), survey versioning (duplicate the survey instead) and
benchmarks across accounts.

## Delivery slices

The spec declares **four slices** (ADR-0016 §2); each is demoable end to end and leaves `main`
coherent.

| Slice | Branch | Facet | Demo |
| --- | --- | --- | --- |
| **S1** *(built)* | `feature/survey-library` | Surveys & the question bank | author a post-deployment check-in (rating + NPS + comment), activate it, retire it, restore it |
| **S2** | `feature/survey-invitations` | Invitations & the public form | send the check-in to a client → open the emailed link in a browser → answer → the invitation is `submitted` and the account history shows the email |
| **S3** | `feature/satisfaction-views` | Responses, scoring & the satisfaction view | the response book with CSAT/NPS bands, the account's satisfaction card and the org summary |
| **S4** *(deferred — ADR-0020)* | `feature/satisfaction-follow-through` | Closed-loop follow-through | an unhappy answer raises a task + a bell notification; the owner resolves it with a note |

S1 is the library every later slice reads from. S2 makes the round trip work and freezes a survey the
moment it goes out. S3 turns answers into the numbers a manager acts on. S4 makes an unhappy number
*somebody's job*; it is deferred behind the dashboard layer for the presentation (ADR-0020) and is
still fully specified below.

## Data model

New tables live beside the existing ones (`00-data-model.md` lists the fields); the common columns
(`id` ULID, `created_at`, `updated_at`, `created_by`) apply throughout. **No existing table changes**
except the new `satisfaction:{response}` form of `activities.source_key` (module 04 reuse).

```
Survey ──► SurveyQuestion
   │
   └──► SurveyInvitation ──► SurveyResponse ──► SurveyAnswer
              │  │                 │                 │
              │  │                 └── question ref ──┘
              │  └── communication_id ──► Communication (module 03)
              └── company_id ──► Company (module 01)
```

**Survey** — `name` (unique among live rows), `description`, `is_active` (draft until turned on),
`created_by`; soft delete = retired. A survey is a **template**: invitations and responses point at
it, never at a copy of it.

**SurveyQuestion** — `survey_id`, `type` (`rating`, `nps`, `single_choice`, `multi_choice`, `text`),
`prompt`, `help`, `required` (default true), `position` (1..n, the order asked), `options` (json, the
choices for the two choice types). A survey holds **at most one `nps` question**; `rating` is always
1–5 and `nps` always 0–10 (no per-question scale configuration in Phase 1).

**SurveyInvitation** — `reference` (`SRV-YYYY-NNNN`), `survey_id`, `company_id`, `contact_id`
(nullable), `contract_id` (nullable, the contract that prompted it), `communication_id` (nullable,
the logged email), `token` (unique, 48 random characters — never the ULID), `recipient_name`,
`recipient_email` (snapshot of who it went to), `message` (the note under the email body),
`status` (`sent`, `failed`, `submitted`, `cancelled`), `failed_reason`, `sent_at`, `expires_at`,
`submitted_at`. The **state** the API reports is derived on read: a `sent` invitation past
`expires_at` reads `expired` (like a task's due state, ADR-0018 §5 — nothing expires on a clock).

**SurveyResponse** — `invitation_id` (unique — one response per invitation), `survey_id`,
`company_id`, `contact_id`, `submitted_at`, `csat` (decimal 3,2 — mean of the rating answers),
`nps` (int 0–10, the NPS answer), `band` (`promoter` 9–10, `passive` 7–8, `detractor` 0–6),
`needs_attention` (bool, stamped at scoring time from `crm.surveys.attention_*`),
`resolved_at`, `resolved_by`, `resolution_note` (the closed-loop record). Answers are immutable.

**SurveyAnswer** — `response_id`, `question_id` (unique together), `value_number` (rating/NPS),
`value_text` (text), `value_options` (json — selected options; one entry for `single_choice`).

Enums are stored as lowercase strings, as everywhere else (`00-data-model.md`).

## API endpoints

Base `/api/v1`; JSON; `Authorization: Bearer <jwt>` **except the two public routes**. Standard
conventions per `README.md` — `?page=`, `?per_page=`, `?sort=`, `?filter[…]`, `?search=`.

| Method | Path | Slice | Description |
| --- | --- | --- | --- |
| `GET` | `/surveys` | S1 | The library — `filter[active|retired]`, `search`, with question and invitation counts (response counts arrive with S3) and `questions_editable` |
| `POST` | `/surveys` | S1 | Create a survey **with its questions** |
| `GET` | `/surveys/{id}` | S1 | One survey — questions in order, invitation/response counts |
| `PATCH` | `/surveys/{id}` | S1 | Name, description, active state and — until it has been sent — the question set |
| `DELETE` | `/surveys/{id}` | S1 | Retire (soft delete); no responses are touched |
| `POST` | `/surveys/{id}/restore` | S1 | Restore a retired survey (re-checks the live name) |
| `POST` | `/surveys/{id}/duplicate` | S1 | Copy the questions into a new inactive survey (the way to version one) |
| `GET` | `/survey-invitations` | S2 | The invitation book — `filter[survey|company|state]`, `search` |
| `GET` | `/survey-invitations/{id}` | S2 | One invitation, with the public link while it can still be answered |
| `POST` | `/surveys/{id}/invitations` | S2 | Send a survey to a company (+ contact, contract, message) |
| `POST` | `/survey-invitations/{id}/cancel` | S2 | Withdraw an unanswered invitation |
| `POST` | `/survey-invitations/{id}/resend` | S2 | Hand the same invitation to the gateway again (failed or still open) |
| `GET` | `/companies/{id}/survey-invitations` | S2 | The account's sends — the Satisfaction tab |
| `GET` | **`/public/surveys/{token}`** | S2 | **Unauthenticated** — the questions, for the client's page |
| `POST` | **`/public/surveys/{token}/responses`** | S2 | **Unauthenticated** — submit the answers |
| `GET` | `/survey-responses` | S3 | The response book — `filter[survey|company|band|attention|date_from|date_to]`, `search` |
| `GET` | `/survey-responses/{id}` | S3 | One response with its answers and its attention state |
| `GET` | `/companies/{id}/satisfaction` | S3 | The account's card: latest CSAT/NPS, bands, trend, recent responses |
| `GET` | `/satisfaction/summary` | S3 | Role-scoped rollup: response rate, CSAT, NPS, bands, attention count |
| `POST` | `/survey-responses/{id}/resolve` | S4 | Close the loop with a note (the owner or manager+) |

The public routes are throttled and live **outside** the `auth:api` group; every other route is
inside it. No delete routes for invitations, responses or answers — they are history, and an
invitation is **cancelled**, never deleted.

## Business rules

1. **A survey is a reusable template, frozen once it has been sent.** Invitations and responses
   reference the survey and its question rows; nothing is copied per send (the same stance module 03
   takes with message templates, ADR-0017 §10). Because of that, the question set — adding,
   removing, reordering, retyping, re-optioning — is a **422 once any invitation exists**; `name`,
   `description` and `is_active` stay editable. To change the questions, `duplicate` the survey,
   retire the old one, and let its responses keep reading against the questions they answered.
2. **Five question types, each with its own value column.** `rating` (1–5, a number), `nps` (0–10, a
   number, **at most one per survey**), `single_choice` and `multi_choice` (the options array),
   `text` (free text). Choice questions require 2–10 non-empty options; a `rating` or `nps` question
   takes none. `required` defaults to true and a submission missing a required answer is 422 naming
   the question.
3. **A survey can be sent only when it is active and has questions.** Drafts exist so a manager can
   write a survey without exposing it; the send endpoint answers 422 for an inactive or empty one.
4. **The invitation is the unit that goes out.** It snapshots `recipient_name`/`recipient_email`
   (the contact can be renamed or removed later and the record still says who it went to), carries a
   unique random token (48 characters — **never the ULID**, so a URL cannot be enumerated), expires
   after `crm.surveys.invitation_days` (default 14) and belongs to a company, optionally to a contact
   and to the contract that prompted it.
5. **The invitation email is a real history record.** Sending goes through the mocked `EmailGateway`
   (ADR-0005, ADR-0017 §4) and writes a `Communication` (`channel=email`, `direction=outbound`,
   `status=sent|failed`, the survey as its subject line) linked from the invitation — so the account
   timeline, the communication book, "last contacted" and module 04's check-in generator all see it
   without knowing surveys exist. A gateway rejection is a **delivery outcome, not an HTTP error**
   (the module 03 stance): the invitation is created `failed` with `failed_reason`, and `resend` is
   the retry.
6. **The public link is the only unauthenticated surface.** `GET`/`POST /public/surveys/{token}` take
   no JWT: the token *is* the credential. Unknown → 404; submitted, expired, cancelled or failed →
   410 with a message the page shows; a valid one returns the questions only (never other responses,
   never the account's other data). The link is `{public_url}/survey/{token}`, where `public_url` is
   `crm.surveys.public_url` (`CRM_SURVEY_URL`, falling back to `FRONTEND_URL`) — **never hardcoded**,
   so local (`http://localhost:5173`) and production (`https://crm.primepower.com`) differ only by
   configuration.
7. **A submitted answer set is immutable and scored once.** The response stores `csat` (mean of its
   rating answers, 2 dp), `nps` (the NPS answer) and `band`; the invitation becomes `submitted` and
   its token stops working. There is no update or delete route for a response or an answer — it is
   what the client said, once.
8. **Unhappy feedback closes the loop automatically.** A response with `csat` at or below
   `crm.surveys.attention_csat_max` (default 3) **or** `band = detractor` is `needs_attention`. On
   submit, `SurveyResponseFlagged` tells the account owner and raises a module-04 follow-up task on
   the company (`source_key satisfaction:{response}`), so the unhappy client is in the worklist and on
   the bell — not only in a report. The owner (or a manager+) **resolves** the response with a note;
   resolution is the closed-loop record and does not complete the task by itself (the call may happen
   later, and the task is completed when it does).
9. **Scoring is computed over answers, never entered.** Per response: `csat` is the mean of its
   `rating` answers, `nps` is its `nps` answer, `band` follows the 9–10 / 7–8 / 0–6 split. Per view:
   the account/org CSAT is the mean of the per-response CSAT values, NPS is
   `%promoters − %detractors` ×100 rounded, and the **response rate** is submitted responses over the
   invitations that could have been answered (excludes failed and cancelled). Everything is derived
   on read from the stored answers.
10. **Visibility is inherited from the account (ADR-0004).** A survey is house wording: every role
    reads the library, manager+ authors it. An invitation and a response are visible exactly when
    their company is; sending a survey needs the company's `update` right; cancelling needs the
    sender or manager+; resolving needs the account's `update` right. The summary is role-scoped like
    the deal book (salesrep own, manager team, admin all).
11. **The library is retired, never deleted.** `DELETE /surveys/{id}` soft-deletes; `restore` brings
    it back; the name is unique among **live** surveys (a validation rule, since a soft delete frees
    it) and restore re-checks it (422). Responses and invitations of a retired survey stay readable.
12. **Locale rules hold everywhere.** Timestamps are stored UTC and displayed in `Asia/Manila`; a date
    is `DD MMM YYYY`; the public page shows the client's local date only in the thank-you line, if at
    all. CSAT shows as `4.6 / 5`, NPS as a whole score (or `—` when no NPS answer exists).

## Permissions

| Action | salesrep | manager | admin / superadmin |
| --- | --- | --- | --- |
| Read the survey library, a survey | ✓ | ✓ | ✓ |
| Author / edit / duplicate / retire / restore a survey | — | ✓ | ✓ |
| See an invitation or a response | Company visible | Company visible | All |
| Send a survey to a company | Company updatable | Company updatable | ✓ |
| Cancel an invitation | Own send | Team | All |
| Resend a failed / open invitation | Own send | Team | All |
| The satisfaction summary | Own accounts | Team | All |
| Resolve a response (closed loop) | Company updatable | Company updatable | ✓ |
| The public survey form | *(no account — the token is the credential)* | | |

"Company visible / updatable" reuses module 01's policy (ADR-0004, ADR-0010 §6): a salesrep's own
accounts, a manager's team, an admin's all. Force-deleting a survey is admin-only, as with templates.

## UI

**S1 — the survey library**

- `/surveys` — the library: name, description, question count, active state, sends and responses,
  with **search** and active/retired filters; **New survey** for manager+, **Retire** / **Restore**
  / **Duplicate** on the row.
- `/surveys/new` and `/surveys/:id` — the form: name, description, an **Active** switch and the
  **question builder** (add a question, pick its type, write the prompt and help, mark it required,
  options for the choice types, move up/down, remove). A **Preview** panel renders the survey the way
  the client will see it. On a survey that has been sent, the builder is read-only and explains why
  (with **Duplicate** offered as the way to change it).
- An inactive survey explains that it can be written but not sent.

**S2 — invitations & the public form**

- The account page gains a **Satisfaction** tab (S1 shows an empty state pointing at the library):
  the account's sends with state, recipient, sent/expires, **Copy link** on an open one,
  **Cancel** and **Resend**; **Send survey** (manager+ or the account's owner) opens a small dialog —
  survey picker (active surveys only), contact picker (defaulting to the primary contact), an
  optional contract and a note — and reports the send's outcome, including a mocked failure.
- `/survey-invitations` — the book: survey, company, recipient, state (sent / expired / submitted /
  cancelled / failed), sent and expires, with state/survey/company filters and search.
- **`/survey/:token`** — the client's page, **outside the dashboard shell** and without login:
  the agency's name, the survey title and description, the questions rendered by type (a 1–5 row, a
  0–10 NPS row, radio buttons, checkboxes, a textarea), a submit button, and a thank-you state. An
  invalid, expired, already-submitted or cancelled token shows the reason plainly. The page says the
  send is simulated ("Email (mocked)") nowhere — the client is a client.

**S3 — responses & the satisfaction view**

- `/satisfaction` — the summary: CSAT, NPS, response rate and needs-attention cards, the band split,
  and the recent responses (company, survey, CSAT, NPS band, attention, date) linking to their detail.
  The numbers cover **every answer in the caller's scope** — a salesrep counts their own accounts, a
  manager the team's, an admin everything — so the page states whose answers it is reading instead of
  offering an Everyone/Mine/My-team switch (role scoping already decides; see §Permissions).
- `/satisfaction/responses` — the book: company, contact, survey, CSAT, band, the attention flag,
  the send and the submitted date; filters by survey, band, attention and date range plus search, and
  a `?company=` deep link (the account card's "read every answer"), which shows the one-account state
  without a company picker.
- `/satisfaction/responses/:id` — one response: the answers in the order asked (a rating as `4 / 5`,
  the NPS answer as `0–10`, options as a list, text as written), who it came from, the score block and
  the invitation it answers, and — from S4 — the resolution panel.
- The account page's **Satisfaction** tab carries the card (latest CSAT/NPS, the four numbers, the
  band split and a small CSAT trend) beside the sends, with every answer one link away.

**S4 — closed-loop follow-through**

- A response that needs attention is marked in the book and in the summary's count; its detail carries
  **Resolve** (a note field) and, once resolved, when/by whom/by note.
- The submit path raises a bell notification ("Unhappy feedback from Sunrise Foods Inc: CSAT 2 / 5")
  that opens the response, and a follow-up task on the company so it appears in `/follow-ups` and the
  account timeline; the task's subject names the score, so the worklist reads like the work.
- Unresolved attention responses are listed on `/satisfaction` above the recent responses — the
  manager's "who is unhappy right now" view.

## Events

| Event | Emitted by | Effect |
| --- | --- | --- |
| `SurveyResponseSubmitted` | the public submit | marks the invitation submitted, saves the score and the attention flag, writes a `system` activity on the company |
| `SurveyResponseFlagged` | a response that needs attention | notifies the account owner and raises the module-04 follow-up task |
| `SurveyResponseResolved` | `POST /survey-responses/{id}/resolve` | writes a `system` activity on the company with the note |

The email itself is the mocked gateway's, exactly like module 03's sends (ADR-0017 §4): a rejection
is recorded as a failed communication and a failed invitation, never as an exception. Sending and
resending an invitation raise **no event of their own** — they reuse module 03's `CommunicationSent`
and `CommunicationFailed`, so the `Communication` row, the timeline mirror and the account clock
move exactly as a logged email does. That is also why `SurveyResponseSubmitted`, not the send, is
what marks the invitation `submitted`.

## Demo data

- **S1** seeds five surveys, deterministic like every other seeder: **Post-deployment check-in**
  (active; "How satisfied are you with the deployment?" rating, "How likely are you to recommend
  Primepower?" NPS, "Anything we should know?" text), **Quarterly account review** (active; two
  ratings — crew and supervisor — a single-choice "How is the headcount matching your needs?" and a
  text), **Exit survey** (active; rating, a single-choice reason and a text), **Deployment pulse
  (pilot)** (inactive — the draft state), and **Client referral pulse** (retired/soft-deleted, so
  **Restore** demos from a real row).
- **S2** seeds invitations the way the API sends them, so the book, the states and the account tab
  demo without touching a gateway: a **submitted** check-in for **Harbor Logistics** (CSAT 5, NPS 10)
  and for **Sunrise Foods Inc** (CSAT 2, NPS 4 — the unhappy one that S4 acts on), an **open** one for
  **BPO Solutions PH** (its link is the live demo of the public form), an **expired** one for
  **Greenfield Hotels** (sent well past the window) and a **cancelled** one, so every state is on the
  page. The emails are real `Communication` rows, which is why the accounts' timelines and "last
  contacted" already tell the story.
- **S3** needs no new seed: the two submitted responses drive the summary (NPS 0 — one promoter, one
  detractor), the band split, the response rate and both account cards.
- **S4** marks the Sunrise response as the seeded attention item (the way the API would have) — a
  bell notification for its owner and a follow-up task on the account — leaving it **unresolved**, so
  resolving it is the demo. Answering the open BPO invitation through the public link during a demo
  then raises a fresh one live.

## Acceptance criteria

**S1**

- [x] A manager can create, edit, duplicate, retire and restore a survey; a salesrep can read one but
      not change it (403).
- [x] A survey keeps its questions in the order given, of the five types; a choice question without
      2–10 options, a second NPS question or a question with no prompt is 422.
- [x] A survey can be activated only when it has at least one question; an inactive survey answers 422
      on send (S2).
- [x] The name is unique among live surveys; a soft-deleted name may be reused, and restore of a row
      whose name is now taken is 422.
- [x] Retiring a survey keeps its invitations and responses readable; restore brings it back.
- [x] The question set is editable until the survey has been sent (S2 adds the freeze).

**S2**

- [x] Sending requires an active survey with questions and a company the caller may update; the
      invitation snapshots the recipient and answers 201 with its state.
- [x] The send goes through the mocked gateway and writes a `Communication` linked from the
      invitation; a forced failure (`CRM_EMAIL_SIMULATE_FAILURE=true`) leaves the invitation `failed`
      with its reason, and `resend` retries it.
- [x] The public link opens without a token for a valid invitation and returns only that survey's
      questions; the URL comes from configuration (the local and production origins differ by env
      only).
- [x] A submitted answer set is validated (required questions, rating 1–5, NPS 0–10, only offered
      options), stored once, and the token stops working (410 on a second submit); an unknown token is
      404 and an expired, cancelled or failed one is 410.
- [x] A response stores its CSAT, NPS and band; the invitation is `submitted` and the account's
      history shows the email and the response.
- [x] Once a survey has been sent, its question set is frozen (422) while name/description/active stay
      editable.
- [x] Cancelling a sent invitation stops the link working; a cancelled invitation cannot be resent.

**S3**

- [x] The response book filters by survey, company, band, attention and date, searches across the
      company/contact/survey, and is scoped to the caller's accounts.
- [x] A response detail shows its answers in the order asked with the right value per type.
- [x] The account card reports latest CSAT/NPS, the band counts, the trend and recent responses; the
      summary reports response rate, CSAT, NPS, bands and the attention count for the caller's scope.
- [x] CSAT is the mean of the rating answers and NPS the promoter−detractor split, computed on read;
      a response with no NPS answer is excluded from NPS but still counts for CSAT and the book.
- [x] Rendered dates are `Asia/Manila` and `DD MMM YYYY`; CSAT shows `4.6 / 5`.

**S4**

- [ ] A response with CSAT ≤ `crm.surveys.attention_csat_max` or a detractor band is marked
      `needs_attention` at submit time; a happy one is not.
- [ ] Flagging notifies the account owner (one notification, carrying a `url`) and raises exactly one
      follow-up task on the company, owned by the account owner, carrying
      `source_key satisfaction:{response}`.
- [ ] The owner or a manager can resolve the response with a note; a salesrep who does not own the
      account cannot (403); resolving twice is 422.
- [ ] The unresolved attention responses appear on `/satisfaction` and in the book's attention filter,
      and disappear from it once resolved.

## Open questions

- **Automatic triggers** — sending on `ContractSigned`, on a renewal, or on a churn are all
  real-world behaviours and all deliberate acts a human should take in Phase 1; the seam is the send
  endpoint, and nothing dispatches it.
- **Reminders** — a second email to an unanswered invitation is not built; `resend` is the manual
  version. A scheduled reminder would reuse module 04's `CommunicationStatus.scheduled`.
- **Anonymous responses** — every response is tied to its invitation and account, which is what makes
  the closed loop possible; anonymising would remove the account link.
- **Survey versioning** — `duplicate` plus retire is the Phase-1 answer; a versioned question set
  would be the alternative if a client needs to compare a question's history across edits.
- **Per-question analytics** — the AI module can compute them from `SurveyAnswer`; this module shows
  the numbers per response and per account only.
