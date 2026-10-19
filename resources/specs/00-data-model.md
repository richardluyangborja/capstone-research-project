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
                      └──► Activity / Attachment   (polymorphic)
Opportunity ──► Contract ──(signed)──► Company becomes "Client"
```

`Client` is **not** a table — it is `Company.status ∈ {onboarding, active, at_risk, dormant, churned}`.

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

Module 02 (pipeline) adds the deal list/board, stage moves (any-to-any with rules, ADR-0015) and the
deal timeline; it adds only these columns — no new tables. Winning a deal is a record only: the
contract is created from the deal and the mocked Service handoff still fires on signing (ADR-0015 §4).

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
| `source_key` | string | | the generator key of a generated reminder, e.g. `renewal:CON-…` (module 04) |

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

## Contract (spec 09)

| Field | Type | Req | Notes |
| --- | --- | --- | --- |
| `reference` | string | ✓ | `CON-YYYY-NNNN`, generated |
| `company_id` | FK companies | ✓ | |
| `opportunity_id` | ULID | | the deal the engagement came from |
| `renewal_of_contract_id` | ULID contracts | | renewal chain; must be the same company |
| `title` | string | ✓ | |
| `status` | `ContractStatus` | ✓ | default `draft`; `signed` only via `mark-signed` |
| `start_date` / `end_date` | date | | `end_date` drives the expiry/renewal countdown |
| `signed_at` | timestamp | | set when signed |
| `value` | decimal(15,2) | | engagement value |
| `currency` | string | | default PHP |
| `terms` | text | | terms summary |
| `notes` | text | | |
| `file_path` / `file_name` / `file_size` / `file_mime` | string / int | | document on the private disk (ADR-0013 §2) |
| `owner_id` | FK users | ✓ | the account owner, so contract and company scoping agree |

Soft deletes; a `signed` contract blocks deleting its company. Indexes: `status`, `company_id`,
`opportunity_id`, `end_date`. Full contract/document management (e-signature, clause versions,
several files per record) stays a separate module — Phase 1 ships one document plus term/status
tracking.

## Attachment (built — module 03 slice S3)

A polymorphic `attachments` table (`attachable_type` / `attachable_id` (ULIDs), `disk`, `path`,
`original_name`, `mime_type`, `size`, `uploaded_by`) is for records that need *several* files. Phase 1
kept the single contract document on the contract row and serves it through
`GET /api/v1/contracts/{id}/file`; uploads accept pdf/png/jpeg up to 10 MB on a private disk.

**Module 03 slice S3 implements this table** (spec 11) for a communication's files — up to 5 per message,
10 MB each, same MIME allow-list, stored under a generated path with the uploader's filename kept for
display, and downloaded only through `GET /api/v1/attachments/{id}/file`. There is no `deleted_at`: the
file lives only as long as the row. Once the table exists, contracts and contacts can move onto it;
nothing is scheduled to.

## Indexes / constraints (highlights)

- Unique: `leads.reference`, `companies.reference`, `opportunities.reference`, `contracts.reference`.
- Index: `leads.status`, `leads.owner_id`, `companies.status`, `companies.owner_id`,
  `opportunities.stage`, `contracts.status`, `contracts.company_id`, `contracts.end_date`,
  `activities.subject_type+subject_id`.
- `companies.name` — soft-unique (warn, don't hard-fail).
