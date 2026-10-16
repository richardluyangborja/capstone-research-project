# 00 — Data Model

Shared entities for Phase 1. Every table also has the **common columns** (`id`, timestamps,
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
| `ContractStatus` | `draft`, `sent`, `signed`, `active`, `expired`, `terminated`, `renewed` |

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
| `next_follow_up_at` | timestamp | | used by follow-up module later |
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
| `client_since` | date | | set when first contract signed |
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

## Activity (supporting, used in Phase 1)

| Field | Type | Req | Notes |
| --- | --- | --- | --- |
| `type` | `ActivityType` | ✓ | |
| `subject` | string | ✓ | |
| `body` | text | | |
| `status` | `ActivityStatus` | ✓ | default `open` |
| `due_at` | timestamp | | tasks/reminders |
| `occurred_at` | timestamp | | for logged touches |
| `owner_id` | FK users | ✓ | |
| `subject_type` / `subject_id` | morph | | lead / company / contact / opportunity / contract |

Full **communication history** (rich email/phone bodies) is a separate module with its own
`communications` table — deferred.

## Contract (minimal in Phase 1)

| Field | Type | Req | Notes |
| --- | --- | --- | --- |
| `reference` | string | ✓ | `CT-YYYY-NNNN` |
| `company_id` | FK companies | ✓ | |
| `opportunity_id` | FK opportunities | | |
| `status` | `ContractStatus` | ✓ | default `draft` |
| `signed_date` / `start_date` / `end_date` | date | | `signed_date` flips client to active |
| `terms_summary` | text | | |
| `rate_terms` | json | | **sensitive** |
| `renewal_of_contract_id` | FK contracts | | renewal chain |

Attachments (contract PDFs/scans) use the polymorphic `attachments` table. Full contract/document
management is a separate module; Phase 1 only needs signed/term tracking + upload.

## Attachment

`attachable_type` / `attachable_id` (morph), `disk`, `path`, `original_name`, `mime_type`, `size`,
`uploaded_by`. Private disk; pdf/png/jpeg only; max 10 MB.

## Indexes / constraints (highlights)

- Unique: `leads.reference`, `companies.reference`, `opportunities.reference`, `contracts.reference`.
- Index: `leads.status`, `leads.owner_id`, `companies.status`, `companies.owner_id`,
  `opportunities.stage`, `activities.subject_type+subject_id`.
- `companies.name` — soft-unique (warn, don't hard-fail).
