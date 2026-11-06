# 00 — Data Model

Shared entities for the CRM modules. Every table also has the **common columns** (`id`, timestamps,
`created_by`, `updated_by`, `deleted_at`) plus `owner_id` where noted. Enum values are lowercase
strings. See `README.md` for global conventions.

All `id` columns are **ULIDs** and are the route keys (ADR-0007); `reference` values (`LD-2026-0001`,
…) are display/search only and never used in URLs. Currency is always **PHP (₱)**; timestamps are UTC
and displayed in `Asia/Manila`.

## Entity relationships

```
Lead ──(convert)──► Company ──► CompanySite
                      │  ▲           Contact
                      │  └── parent (branches)
                      ├──► Opportunity ──► Contract
                      ├──► Contact
                      ├──► SurveyInvitation ──► SurveyResponse ──► SurveyAnswer
                      └──► Activity / Attachment   (polymorphic)
Opportunity ──► Contract ──► ContractRateCard   (Contract is a read-only projection)
                    │
                    └──(read-only)──► commercial / renewal / delivery context
Survey ──► SurveyQuestion
```

`Client` is **not** a table — it is `Company.status ∈ {onboarding, active, at_risk, dormant, churned}`.
`Contract` is a read-only projection owned by Core Transaction 3 (ADR-0027); the CRM has no contract
write path.

## Enums

| Enum | Values |
| --- | --- |
| `LeadStatus` | `new`, `contacted`, `qualified`, `converted`, `disqualified`, `nurture`, `archived` |
| `LeadRating` | `hot`, `warm`, `cold` |
| `LeadSource` | `walk_in`, `email`, `phone`, `referral`, `website`, `outbound`, `event`, `partner`, `other` |
| `Urgency` | `low`, `medium`, `high` |
| `DisqualificationReason` | `no_budget`, `no_need`, `competitor`, `unresponsive`, `bad_fit`, `duplicate`, `other` |
| `OpportunityStage` | `new_inquiry`, `qualified`, `discovery`, `proposal_sent`, `negotiation`, `won`, `lost`, `on_hold` |
| `LostReason` | `price`, `competitor`, `no_budget`, `timing`, `no_decision`, `other` |
| `CompanyStatus` | `prospect`, `onboarding`, `active`, `at_risk`, `dormant`, `churned` |
| `CompanyTier` | `a`, `b`, `c` *(advanced scoring deferred)* |
| `CompanySize` | `micro`, `small`, `medium`, `large`, `enterprise` |
| `ContactRoleType` | `hiring_manager`, `hr`, `procurement`, `finance`, `executive`, `other` |
| `ActivityType` | `call`, `email`, `meeting`, `walk_in`, `task`, `note`, `system` |
| `ActivityStatus` | `open`, `done`, `cancelled` |
| `ContractStatus` | `draft`, `sent`, `signed`, `expired`, `terminated` |
| `CommunicationChannel` | `email`, `call`, `sms`, `whatsapp`, `meeting`, `letter`, `other` *(module 03)* |
| `CommunicationDirection` | `inbound`, `outbound` *(module 03)* |
| `CommunicationStatus` | `draft`, `scheduled`*, `sent`, `received`, `failed`, `logged` *(module 03; *reserved for module 04)* |
| `CommunicationOutcome` | `connected`, `no_answer`, `voicemail`, `busy`, `other` *(module 03, calls)* |
| `SurveyQuestionType` | `rating`, `nps`, `single_choice`, `multi_choice`, `text` *(module 05)* |
| `SurveyInvitationStatus` | `sent`, `failed`, `submitted`, `cancelled` — `expired` is derived on read *(module 05)* |
| `SurveyBand` | `promoter`, `passive`, `detractor` — from the NPS answer, 9–10 / 7–8 / 0–6 *(module 05)* |

## Lead

| Field | Type | Req | Notes |
| --- | --- | --- | --- |
| `reference` | string | ✓ | `LD-YYYY-NNNN`, generated |
| `company_name` | string | ✓ | |
| `contact_name` | string | ✓ | primary contact at lead stage |
| `contact_email` | string | | email **or** phone required |
| `contact_phone` | string | | email **or** phone required |
| `contact_position` | string | | |
| `source` | `LeadSource` | ✓ | |
| `source_detail` | string | | campaign/referrer |
| `industry` | string | | |
| `location` | string | | city/region |
| `service_interest` | string | | e.g. manpower type |
| `estimated_headcount` | int | | qualification |
| `needed_roles` | text | | qualification |
| `target_start_date` | date | | qualification |
| `budget_rate` | decimal(15,2) | | **sensitive** |
| `authority_confirmed` | bool | | qualification (default false) |
| `work_site` | string | | qualification |
| `urgency` | `Urgency` | | |
| `rating` | `LeadRating` | | set at qualification |
| `score` | int | | optional, derived |
| `qualification_notes` | text | | |
| `disqualification_reason` | `DisqualificationReason` | | required when disqualified |
| `status` | `LeadStatus` | ✓ | default `new` |
| `status_changed_at` | timestamp | | |
| `owner_id` | FK users | | assignee (nullable = queue) |
| `last_activity_at` | timestamp | | denormalised |
| `next_follow_up_at` | timestamp | | the lead's earliest open follow-up — task or scheduled message (module 04) |
| `consent_at` | timestamp | | when the public intake form recorded consent; null for manual/CSV leads (module 01 refresh) |
| `converted_company_id` | FK companies | | set on conversion |
| `converted_opportunity_id` | FK opportunities | | set on conversion |

## Company (Account)

| Field | Type | Req | Notes |
| --- | --- | --- | --- |
| `reference` | string | ✓ | `CO-YYYY-NNNN` |
| `name` | string | ✓ | legal name |
| `trade_name` | string | | display name |
| `industry` | string | | |
| `size` | `CompanySize` | | |
| `website` | string | | |
| `description` | text | | |
| `parent_company_id` | FK companies | | branches; no cycles |
| `owner_id` | FK users | ✓ | |
| `status` | `CompanyStatus` | ✓ | default `prospect` |
| `tier` | `CompanyTier` | | manual in Phase 1 |
| `client_since` | date | | stamped on the first move past `prospect`; never reset |
| `churn_reason` | string | | required when `churned` (spec 09 §5) |
| `churned_at` | timestamp | | set with `churned_at`, cleared on win-back |
| `handoff_status` | enum `sent`/`failed` | | last mocked Service handoff (spec 09 §2) |
| `handoff_reference` | string | | `SVC-{contract reference}` on success |
| `handoff_error` | text | | why the handoff failed; nulled on success |
| `handoff_requested_at` | timestamp | | when the handoff last ran |
| `source_lead_id` | FK leads | | origin |
| `tags` | json | | |

**CompanySite** (PH address format): `company_id` ✓, `label`, `street_address`, `barangay`,
`city_municipality`, `province`, `region`, `postal_code`, `is_primary` (one primary per company).

## Contact

| Field | Type | Req | Notes |
| --- | --- | --- | --- |
| `company_id` | FK companies | ✓ | |
| `name` | string | ✓ | |
| `position` | string | | |
| `role_type` | `ContactRoleType` | ✓ | decision-maker type |
| `email` | string | | |
| `phone` | string | | |
| `mobile` | string | | |
| `is_primary` | bool | | one primary per company |
| `status` | enum `active`/`inactive` | | default `active` |
| `notes` | text | | |

## Opportunity (Deal)

| Field | Type | Req | Notes |
| --- | --- | --- | --- |
| `reference` | string | ✓ | `OPP-YYYY-NNNN` |
| `company_id` | FK companies | ✓ | created on conversion |
| `lead_id` | FK leads | | origin |
| `name` | string | ✓ | |
| `stage` | `OpportunityStage` | ✓ | default `new_inquiry` |
| `amount` | decimal(15,2) | | **sensitive** |
| `currency` | string | | default PHP |
| `probability` | int (0–100) | | defaults from stage, overridable |
| `expected_close_date` | date | | |
| `actual_close_date` | date | | |
| `source` | `LeadSource` | | |
| `primary_contact_id` | FK contacts | | |
| `owner_id` | FK users | ✓ | |
| `lost_reason` | `LostReason` | | required when `lost` |
| `stage_changed_at` | timestamp | | set on every stage move (module 02) |
| `last_activity_at` | timestamp | | denormalised; refreshed on a logged activity (module 02) |
| `next_step` | string | | free-text next action; scheduled tasks are the follow-up module (module 02) |
| `estimated_headcount` | unsigned int | | requested workforce count (module 02 refresh, spec 18) |
| `service_type` | string | | placement type: `temporary_staffing`, `contract_to_hire`, `direct_hire`, `project_based` (spec 18) |
| `target_start_date` | date | | target worker deployment date (spec 18) |
| `next_follow_up_at` | timestamp | | denormalised; refreshed on task changes (spec 18, module 04 sync) |
| `win_note` | text | | captured close terms and deployment notes on won deals (spec 18) |

Module 02 (pipeline) adds the deal list/board, stage moves (any-to-any with rules, ADR-0015) and the
deal timeline. Spec 18 (ADR-0029) grounds deals in the staffing domain (headcount, service type, target
date), synchronizes follow-up timestamps, and adds won outcome capture. Winning a deal is a record only:
contract work happens in Core Transaction 3, and the CRM reads the resulting projection (ADR-0027); a won
deal never activates a company or uploads a contract.

## Activity (supporting, used in Phase 1)

| Field | Type | Req | Notes |
| --- | --- | --- | --- |
| `type` | `ActivityType` | ✓ | `task` + a `due_at` is a **follow-up** (module 04) |
| `subject` | string | ✓ | |
| `body` | text | | |
| `status` | `ActivityStatus` | ✓ | default `open`; a task is `open`, `done` or `cancelled` (module 04) |
| `due_at` | timestamp | | when a task is owed (module 04) |
| `occurred_at` | timestamp | | for logged touches |
| `owner_id` | FK users | ✓ | who owes the task / logged the touch |
| `subject_type` / `subject_id` | morph | | lead / company / contact / opportunity / contract |
| `communication_id` | ULID communications | | set when the activity mirrors a logged communication (module 03) |
| `reminded_at` | timestamp | | when the owner was last told it fell due; cleared on reschedule (module 04) |
| `source_key` | string (unique, nullable) | | the generator key of a generated reminder — `renewal:{contract}`, `check_in:{company}:{ISO-week}`, `stale_lead:{lead}:{ISO-week}`; null on a hand-written task (module 04) |

`Activity` is the **lightweight quick log** (a touch or a task). Detailed, channel-aware history is the
`Communication` record below — a logged communication also writes an activity (ADR-0017 §2). A `task`
is the one shape that is *not* a record of something done: it is a promise with a `due_at` (module 04).

## Communication (module 03, spec 11)

| Field | Type | Req | Notes |
| --- | --- | --- | --- |
| `reference` | string | ✓ | `COM-YYYY-NNNN`, generated |
| `subject_type` / `subject_id` | morph | ✓ | lead / company / contact / opportunity / contract; no orphans |
| `contact_id` | FK contacts | | the person on the other end, when known |
| `channel` | `CommunicationChannel` | ✓ | |
| `direction` | `CommunicationDirection` | ✓ | |
| `status` | `CommunicationStatus` | ✓ | default from channel/direction; `scheduled` = an outbound email due later (module 04) |
| `subject` | string | ✓ | email subject line, or a one-line summary for a call |
| `body` | text | | the content/notes (stored once — the activity mirror carries no copy) |
| `from_name` / `from_address` | string | | participant display |
| `to_name` / `to_address` | string | | primary recipient |
| `scheduled_for` | timestamp | | when it is due to go out and it has not yet (module 04, `status = scheduled`) |
| `occurred_at` | timestamp | | when it happened — **null** while scheduled, stamped when it leaves |
| `duration_minutes` | int | | calls/meetings |
| `outcome` | `CommunicationOutcome` | | calls |
| `external_id` | string | | message id from the mocked email gateway |
| `thread_id` / `in_reply_to_id` | string / ULID | | threading: the conversation's root message / the message answered (slice S4) |
| `failed_reason` | string | | set when a send fails |
| `owner_id` | FK users | ✓ | who logged or sent it |

Soft deletes. Indexes: `subject_type+subject_id`, `channel`, `direction`, `status`, `occurred_at`,
`contact_id`, `owner_id`, `thread_id`. Attachments use the polymorphic `attachments` table (below) —
slice S3. Email is a **mocked** integration: no SMTP, no IMAP, inbound is pulled by a command
(ADR-0017 §4).

## CommunicationTemplate (module 03 slice S5, spec 11)

| Field | Type | Req | Notes |
| --- | --- | --- | --- |
| `name` | string | ✓ | how it is picked; unique among **live** rows (a retired one frees its name) |
| `channel` | `CommunicationChannel` | ✓ | default `email`; what it is written for |
| `subject` | string | ✓ | the title line, may hold merge fields |
| `body` | text | ✓ | the content, may hold merge fields |
| `is_active` | bool | ✓ | default true; inactive stays out of the composer's picker |
| `created_by` | FK users | | who authored it |

Soft deletes. Indexes: `name`, `channel`, `is_active`. A template is only ever a **prefill** for the
composer (slice S5, ADR-0017 §10) — a saved communication never references one, so editing or retiring a
template cannot rewrite history. Merge fields are `{{token}}` and are resolved at render time from the
record (and the caller); anything that cannot be filled is left verbatim and reported, never invented.

## Contract (spec 09; read-only projection — ADR-0027 / spec 17)

Core Transaction 3 (Document & Contract Management / Legal & Compliance) owns contract creation,
terms, signatures, renewals, documents and signatories. The CRM keeps `contracts` and
`contract_rate_cards` as a **read-only projection** so communications, activities, surveys,
deployments, billing and health keep a stable local key. The CRM has no contract write route; see
spec 17 T1 for the current API surface and the `ContractFeed` seam.

| Field | Type | Req | Notes |
| --- | --- | --- | --- |
| `reference` | string | ✓ | `CON-YYYY-NNNN`, generated for the projection; display/search only |
| `external_id` | string, nullable, unique | | Core Transaction 3 identifier (projection metadata) |
| `synced_at` | timestamp, nullable | | last successful projection refresh |
| `company_id` | FK companies | ✓ | |
| `opportunity_id` | ULID | | the deal the engagement came from |
| `renewal_of_contract_id` | ULID contracts | | renewal chain; must be the same company |
| `title` | string | ✓ | |
| `status` | `ContractStatus` | ✓ | read from Contract Management; same lowercase enum, never set by a CRM route |
| `start_date` / `end_date` | date | | `end_date` drives the expiry/renewal countdown |
| `signed_at` | timestamp | | set when signed |
| `value` | decimal(15,2) | | negotiated headline value; never recomputed from rate cards |
| `currency` | string | | default PHP |
| `payment_terms` | string | | e.g. “Net 30” (module 01 refresh, ADR-0024) |
| `po_reference` | string | | client purchase-order reference |
| `auto_renew` | bool | | default false; a manual renewal chain still applies |
| `notice_days` | int | | renewal/termination notice window |
| `signatory_name` | string | | client signatory |
| `signatory_position` | string | | client signatory role |
| `terms` | text | | terms summary |
| `notes` | text | | |
| `file_path` / `file_name` / `file_size` / `file_mime` | string / int | | **deprecated** — contract documents live in Core Transaction 3; the CRM does not store or serve them (ADR-0027) |
| `owner_id` | FK users | ✓ | the account owner, so contract and company scoping agree |

Soft deletes remain for projection maintenance; a projected `signed` contract still blocks deleting
its company. Indexes: `status`, `company_id`, `opportunity_id`, `end_date`. The CRM has no create,
edit, sign, renew, retire or document-download route. The module 01 refresh adds the commercial
header above and the rate-card table below; both are read-only after spec 17 T1.

### ContractRateCard (module 01 refresh, ADR-0024)

| Field | Type | Req | Notes |
| --- | --- | --- | --- |
| `contract_id` | FK contracts | ✓ | cascades with the contract |
| `site_id` | FK company_sites | | optional delivery site |
| `role_title` | string | ✓ | the role being billed |
| `headcount` | int | ✓ | ≥ 1; multiplies the line |
| `billing_unit` | enum | ✓ | `hourly`, `monthly`, `placement` |
| `bill_rate` | decimal(15,2) | ✓ | client-facing rate; visible to salesrep |
| `pay_rate` | decimal(15,2) | | internal cost; manager+ only |
| `units_per_period` | decimal(8,2) | | hours/month for hourly (default 176), 1 for monthly/placement |
| `notes` | text | | |
| `position` | int | ✓ | display order |

Soft deletes. The set is a read-only projection of the external agreement; the former
`PUT /contracts/{id}/rate-cards` write is retired (ADR-0027). A contract may have zero rows.
`client_monthly_value`, `internal_monthly_cost`, `gross_margin`, `gross_margin_percent` and
`estimated_total_value` are **computed on read**; nothing is stored. `pay_rate`/margin/estimated
internal cost are omitted for salesreps. See spec 16 §Contract commercial model for the formulas.

### External read-only feeds (module 01 refresh, ADR-0025)

Recruitment job orders, deployments, timekeeping summaries, Finance AR/invoices and contracts are
**not CRM-owned operational tables**. They are read-only integration DTOs under
`backend/app/Integrations/`, assembled on read and labelled with `meta.source`, `read_only` and
`as_of`. The CRM never writes them, and the former outbound Service handoff on signing is retired
(ADR-0027). No candidate, worker or employee-level rows are stored or exposed.

## Attachment (built — module 03 slice S3)

A polymorphic `attachments` table (`attachable_type` / `attachable_id` (ULIDs), `disk`, `path`,
`original_name`, `mime_type`, `size`, `uploaded_by`) is for records that need *several* files. The
earlier single contract document on the contract row and its
`GET /api/v1/contracts/{id}/file` download are retired by ADR-0027; contract documents live in Core
Transaction 3.

**Module 03 slice S3 implements this table** (spec 11) for a communication's files — up to 5 per message,
10 MB each, same MIME allow-list, stored under a generated path with the uploader's filename kept for
display, and downloaded only through `GET /api/v1/attachments/{id}/file`. There is no `deleted_at`: the
file lives only as long as the row. Contract documents no longer use this table or the contract row;
they stay in Core Transaction 3.

## Survey (module 05, spec 13)

The survey library: reusable templates whose questions are asked through a tokenized public link
(ADR-0019). Soft deletes; the name is unique among live rows.

**Survey** — `name` ✓ (live-unique), `description`, `is_active` ✓ (default false; only an active survey
can be sent), `created_by`. A survey is frozen structurally once an invitation exists.

**SurveyQuestion** — `survey_id` ✓, `type` `SurveyQuestionType` ✓, `prompt` ✓, `help`, `required` ✓
(default true), `position` ✓ (1..n), `options` (json, 2–10 for the two choice types; null for the
others). At most one `nps` question per survey. `rating` is 1–5 and `nps` 0–10, fixed in Phase 1.

**SurveyInvitation** — `reference` `SRV-YYYY-NNNN`, `survey_id` ✓, `company_id` ✓, `contact_id`,
`contract_id` (what prompted it), `communication_id` (the logged email, module 03), `token` ✓
(unique, 48 random characters — never the ULID), `recipient_name` / `recipient_email` (snapshot),
`message`, `status` `SurveyInvitationStatus` ✓, `failed_reason`, `sent_at`, `expires_at` (default
`sent_at + crm.surveys.invitation_days`), `submitted_at`. No soft delete: an invitation is cancelled.

**SurveyResponse** — `invitation_id` ✓ (unique), `survey_id` ✓, `company_id` ✓, `contact_id`,
`submitted_at` ✓, `csat` decimal(3,2) (mean of the rating answers), `nps` int 0–10, `band`
`SurveyBand`, `needs_attention` ✓ (bool, from `crm.surveys.attention_*`), `resolved_at`,
`resolved_by`, `resolution_note`. Immutable — no update or delete route.

**SurveyAnswer** — `response_id` ✓, `question_id` ✓ (unique together), `value_number` (rating/NPS),
`value_text` (text), `value_options` (json, the selected options).

## Indexes / constraints (highlights)

- Unique: `leads.reference`, `companies.reference`, `opportunities.reference`, `contracts.reference`,
  `survey_invitations.reference`, `survey_invitations.token`, `survey_responses.invitation_id`,
  `survey_answers.response_id+question_id`.
- Index: `leads.status`, `leads.owner_id`, `companies.status`, `companies.owner_id`,
  `opportunities.stage`, `contracts.status`, `contracts.company_id`, `contracts.end_date`,
  `contract_rate_cards.contract_id`, `contract_rate_cards.billing_unit`,
  `activities.subject_type+subject_id`, `survey_invitations.status+expires_at`,
  `survey_invitations.company_id`, `survey_responses.company_id`, `survey_responses.submitted_at`.
- `companies.name` — soft-unique (warn, don't hard-fail).
