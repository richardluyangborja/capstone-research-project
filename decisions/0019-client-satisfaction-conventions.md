# 0019 — Client satisfaction & survey conventions

- Status: Accepted
- Date: 2026-10-20

## Context

Module 05 (Client Satisfaction & Survey, `specs/13`) is the last CRM module before the AI reports
(ADR-0014). Nothing in the data model was reserved for it: `00-data-model.md` has no survey entity,
and the pain point in `business-flow.md` is simply "no structured client satisfaction capture". The
module has to answer three questions the rest of the CRM cannot: *who do we ask*, *what did they say*,
and *does anybody act on it*.

Four seams exist and are deliberately reused rather than duplicated:

- Module 03 owns outbound email through the mocked `EmailGateway` and records every message as a
  `Communication` (ADR-0017 §4) — a survey invitation **is** an outbound email.
- Module 04 owns whatever somebody owes (`Activity` `type=task` with `source_key`, ADR-0018 §7) and
  the bell — "call this unhappy client back" **is** a follow-up.
- Module 01 owns the company and its contacts, and ADR-0004 decides who may see or change an account.
- The frontend already has an unauthenticated `PublicLayout` for the public site.

## Decision

1. **A survey is a reusable template; an invitation is what goes out.** `Survey` holds the wording and
   `SurveyQuestion` its ordered question set. `SurveyInvitation` points at the survey (never at a copy)
   and at the company (+ optional contact and contract), with a snapshot of the recipient. Because
   responses read the survey's questions, the **question set is frozen once any invitation exists**
   (`name`, `description` and `is_active` stay editable); `POST /surveys/{id}/duplicate` is the
   Phase-1 way to version one. Retiring is a soft delete, like message templates (ADR-0017 §10).
2. **Five question types with typed answer columns, not one JSON blob.** `rating` (1–5), `nps`
   (0–10, at most one per survey), `single_choice`, `multi_choice` and `text`. A `SurveyAnswer` row
   carries `value_number`, `value_text` or `value_options` according to the question type, so the
   database can aggregate and the API can validate ranges instead of trusting a payload. Open-ended
   analytics belong to the AI module.
3. **The invitation is keyed by a random token, never by its ULID.** The client has no account, so the
   link is the credential: 48 random characters, unique, single-use. A submitted, cancelled, failed or
   expired invitation answers 410; an unknown token 404. Expiry is derived on read from
   `expires_at` (`crm.surveys.invitation_days`, default 14) — nothing runs on a clock, the same stance
   as task due states (ADR-0018 §5). Invitations are **cancelled**, never deleted.
4. **The public link is the one unauthenticated surface, and its origin comes from configuration.**
   `GET`/`POST /api/v1/public/surveys/{token}` sit outside `auth:api`, return only the survey's
   questions (never the account's other data) and are throttled. The emailed URL is
   `{public_url}/survey/{token}`, where `public_url` is `crm.surveys.public_url` — env
   `CRM_SURVEY_URL`, falling back to `FRONTEND_URL` — so local (`http://localhost:5173`) and
   production (`https://crm.primepower.com`) differ by configuration only. No link is ever built from
   a hardcoded host, and the API origin is never used as the page origin.
5. **A sent invitation is a communication, not a second kind of mail.** Sending goes through the
   `EmailGateway` mock and writes a `Communication` (`channel=email`, `direction=outbound`,
   `status=sent|failed`) linked from the invitation; the account timeline, the history book, "last
   contacted" and module 04's check-in generator pick it up without knowing surveys exist. A gateway
   rejection is a delivery outcome (invitation `failed` + `failed_reason`, HTTP 201), exactly as
   module 03 treats a rejected send; `resend` is the retry.
6. **Scoring is computed from answers, never entered.** Per response, `csat` is the mean of its
   `rating` answers and `nps` its NPS answer, with `band` the 9–10 / 7–8 / 0–6 split. Per view
   (account card, summary), CSAT is the mean of the per-response values and NPS the
   promoter−detractor share ×100; response rate is submissions over invitations that could have been
   answered (failed and cancelled excluded). A response stores its computed values so the closed loop
   can filter in SQL; the aggregates are derived on read.
7. **Unhappy feedback is somebody's job.** On submit, a response at or below
   `crm.surveys.attention_csat_max` (default 3) or with a detractor band is `needs_attention`:
   `SurveyResponseFlagged` notifies the account owner and raises **one** module-04 follow-up task on
   the company with `source_key satisfaction:{response}` — so it appears in `/follow-ups`, the bell and
   the timeline. Resolution is a note on the response (`resolved_at`, `resolved_by`,
   `resolution_note`), not a status of the task: the call is completed when it is made, and a response
   can be resolved twice only by a bug (422).
8. **Visibility is inherited from the account (ADR-0004).** Surveys are house wording — every role
   reads, manager+ authors, admin force-deletes. An invitation or response is visible exactly when its
   company is; sending needs the company's `update` right, cancelling the sender or manager+,
   resolving the account's `update` right. The summary is scoped like the deal book (own / team / all).

## Consequences

- Five new tables and no change to an existing one; `activities.source_key` gains the
  `satisfaction:{response}` form beside module 04's three.
- The module depends on module 03 for delivery and module 04 for the closed loop, so it must land
  after both — which is why it is the roadmap's last CRM module.
- The AI module inherits a real satisfaction feed (CSAT, NPS, bands, attention and resolution) instead
  of inferring sentiment from text.
- Automatic sends (on signing, renewal or churn) and reminder emails stay deliberately unbuilt; the
  seam is the send endpoint and, later, a scheduled `Communication`.
- The public page is the only route in the product that answers without a JWT; a future audit should
  start there.
