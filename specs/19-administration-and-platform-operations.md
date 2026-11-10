# Module 08 — Administration & Platform Operations

- Status: **S0 accepted; S1–S10 proposed and pending implementation verification**
- Owner: Main Planner
- Depends on: ADR-0004, ADR-0005, ADR-0007, ADR-0016, `00-data-model.md`, `implementation/system-roles.md`

## Purpose

This module defines the back-office capabilities needed to operate the CRM as a real product. It
adds an application administration surface for `admin` users, a restricted platform operations
surface for `superadmin` users, and a small personal-settings surface available to every signed-in
role.

The module is intentionally separate from CRM sales workflows. It must not become a second path for
editing leads, accounts, opportunities, contracts, communications, surveys, or external department
records. Existing domain policies remain authoritative.

## Recommended role boundary

### Admin — application operator

An admin can:

- manage CRM users, teams, activation state, and role assignment below `superadmin`; ownership
  changes go through the existing domain delegation endpoints;
- configure application settings that affect CRM behavior, presentation, notifications, and mocked
  integrations;
- inspect immutable audit history and operational activity;
- inspect delivery, email, AI, and notification integration status;
- run explicitly safe maintenance actions such as retrying a failed handoff only where the owning
  module already exposes that operation;
- view all CRM records through existing read policies.

An admin cannot:

- create, demote, suspend, or delete a superadmin;
- change platform security controls or JWT secrets through the UI;
- execute arbitrary SQL or mutate another department's data;
- bypass a domain state machine by using an administrative endpoint;
- silently impersonate a user.

### Superadmin — platform operator

A superadmin can do everything an admin can, plus:

- manage admin accounts and platform-level access;
- manage system-wide feature flags and security settings;
- inspect application health, runtime configuration status, queue/cache/storage status, and
  integration diagnostics;
- access a read-only data diagnostics view with bounded, allow-listed queries;
- place the system in maintenance/read-only mode if that control is implemented;
- review security-sensitive audit events.

The superadmin UI must not expose arbitrary SQL, raw credentials, JWT signing keys, password hashes,
private attachment paths, or unrestricted destructive database operations. Database migrations,
backups, restores, and production shell access remain deployment/operator responsibilities.

### All signed-in roles — personal settings

Every role receives a lightweight settings page for:

- profile name and contact details;
- password change;
- timezone/display preferences, default landing page, date format, and table density;
- notification preferences;
- active-session list and sign-out of other sessions when session tracking exists.

Personal settings cannot change role, team, ownership scope, or organization settings.

## Tenancy assumption

Phase 1 remains single-organization, matching the current seeded `Primepower Manpower Services`
system. The data model should keep organization scope explicit in settings and audit contracts where
practical, but organization creation, billing, and cross-tenant administration are deferred.

## Domain model proposal

The implementation adds only the tables needed by the accepted slices; the field-level proposal is
mirrored in `00-data-model.md`:

- `users` gains `public_id` (ULID, unique; the admin URL key while the bigint `users.id` remains the
  internal FK/JWT key), `status` (`active`/`suspended`), `suspended_at`, `suspended_by` and
  `status_reason`;
- `settings` with a typed allow-listed key, scope (`system`, `organization`, `team`, `user`), json
  value, value type, and updated-by metadata;
- `audit_logs` with actor, action, severity, subject type/id, request metadata, redacted before/after
  snapshots, correlation/request id, and created timestamp;
- `user_sessions` (only if “sign out other sessions” is delivered) with a ULID, user, session
  reference, client metadata and revocation timestamp.

A separate `admin_invitations` table is not required in Phase 1: the mocked invitation uses a
signed, expiring token; a table is added only if revocable acceptance tracking needs it. All
URL-exposed records use ULIDs. Secrets and sensitive values are redacted before persistence or
response. Audit rows are append-only to application users; corrections are represented by a new
event.

## Settings categories

The first settings contract should use an allow-list rather than arbitrary key/value editing:

1. **Organization profile** — display name, logo, support email, business timezone, locale, and
   default currency (PHP remains fixed).
2. **CRM behavior** — aging thresholds, attachment limits, follow-up defaults, survey thresholds,
   and visible feature switches already represented by `config/crm.php`.
3. **Notifications** — database notification defaults and mocked email behavior.
4. **AI and integration controls** — enabled state, service availability, timeout/cap display, and
   safe simulation toggles; credentials remain environment/deployment managed.
5. **Security** — password policy, login throttling display, session lifetime, and optional MFA
   readiness; secrets and JWT configuration remain outside the UI.
6. **User preferences** — per-user display and notification preferences.

Settings writes must validate type, range, and role ownership. Every effective change is audited.
Runtime configuration may be cached, but the settings API must return the effective value and its
source (`database`, `environment`, or `default`) without revealing secret contents.

## S0 contract — role matrix, boundaries, policies and threat model

**Status: accepted 2026-11-08.** This is the implementation contract for S1–S10 and the source of
truth for the role, endpoint, settings, audit and redaction decisions below.

### Role capability matrix

| Capability | Salesrep | Manager | Admin | Superadmin |
| --- | --- | --- | --- | --- |
| CRM records | Own, through existing domain policies | Team, through existing domain policies | All, through existing domain policies | All, through existing domain policies |
| Admin application shell | No | No | Yes | Yes |
| Platform operations shell | No | No | No | Yes |
| User directory | No | No | Manager/salesrep accounts below superadmin | All accounts, including admins |
| Create/promote admins | No | No | No | Yes |
| Create superadmins | No | No | No | No (seeded/platform only) |
| User suspension/restoration | No | No | Below superadmin | Any, except the final active superadmin |
| Team administration | No | No | Yes | Yes |
| Safe CRM settings | No | No | Yes | Yes |
| Security/platform settings | No | No | No | Yes |
| Audit history | No | No | Non-security events, redacted | All events, security details where permitted |
| Audit export | No | No | Bounded, redacted CSV | Bounded, redacted CSV |
| Application health | No | No | Yes | Yes |
| Platform diagnostics | No | No | No | Yes |
| Allow-listed maintenance | No | No | Yes | Yes |
| Personal settings | Yes | Yes | Yes | Yes |
| Impersonation | No | No | No | No |
| Arbitrary SQL, migrations, backups, restores, shell | No | No | No | No |
| Credentials, JWT keys, password hashes, private paths | No | No | No | No |

Admin is an **application operator**, not a platform operator. Superadmin is not a second CRM editor;
it inherits the same CRM policies and cannot bypass a domain state machine or validation rule.

### Resource boundaries

| Resource | Application admin | Platform superadmin | Hard boundary |
| --- | --- | --- | --- |
| Leads, accounts, contacts, opportunities, contracts, communications, surveys, follow-ups | Read all; mutate only through existing policies and domain state rules | Same as admin | No administration endpoint may edit or bypass a domain record |
| Users | Manage manager/salesrep accounts; view profiles but not superadmin security details | Manage admins; suspend/restore superadmins except the final active one | No hard deletion; no self-suspension; no final-active-superadmin lockout |
| Teams | Create/edit teams, assign managers, move members | Same as admin | A team change never auto-reassigns CRM ownership |
| Settings | Typed safe keys in the allow-list | Safe keys plus security/platform keys | No arbitrary keys, environment secrets, credentials, or deployment controls |
| Audit | Read/export non-security events | Read/export all, including security events | Append-only; no update/delete API; exports are themselves audited |
| Sessions | Own sessions and other-session sign-out | Same as admin | No admin browsing of another user's session payload |
| Health | Application dependencies and mocked integrations | Platform diagnostics and runtime capability status | No secrets, environment dumps, or unrestricted table queries |
| Maintenance | Configuration refresh and supported email retry | Same, with platform visibility | No generic command runner, SQL, migration, backup, restore, or cache flush |
| Diagnostics | None | Bounded, allow-listed, read-only | Fixed queries with timeouts; no raw schema dumps |

### Endpoint and policy inventory

All routes are under `/api/v1`. `{user:public_id}` uses the new ULID `users.public_id`; the internal
bigint `users.id` remains the JWT/FK key and is never URL-exposed. `{team}` is the existing ULID team
id. `{auditLog}` and `{session}` are ULIDs.

| Method | Path | Owning policy | Allowed roles | Explicitly forbidden |
| --- | --- | --- | --- | --- |
| `GET` | `/admin/access` | `AdminAccessPolicy@view` | admin, superadmin | Salesrep/manager access; admin claiming platform operations; secrets or environment details |
| `GET` | `/admin/users` | `UserAdminPolicy@viewAny` | admin, superadmin | Admin listing or filtering superadmin accounts |
| `POST` | `/admin/users` | `UserAdminPolicy@create` | admin, superadmin | Admin creating admins; any route creating a superadmin; sending a plaintext password |
| `GET` | `/admin/users/{user:public_id}` | `UserAdminPolicy@view` | admin, superadmin | Admin viewing a superadmin's security details |
| `PATCH` | `/admin/users/{user:public_id}` | `UserAdminPolicy@update` | admin, superadmin | Role, team, status, password, or ownership fields in this payload |
| `PATCH` | `/admin/users/{user:public_id}/role` | `UserAdminPolicy@changeRole` | admin, superadmin | Admin assigning admin/superadmin; changing the final active superadmin |
| `PATCH` | `/admin/users/{user:public_id}/team` | `UserAdminPolicy@changeTeam` | admin, superadmin | Out-of-scope teams; automatic ownership reassignment; changing a superadmin by an admin |
| `POST` | `/admin/users/{user:public_id}/suspend` | `UserAdminPolicy@suspend` | admin, superadmin | Suspending self; suspending the final active superadmin; admin suspending a superadmin |
| `POST` | `/admin/users/{user:public_id}/restore` | `UserAdminPolicy@restore` | admin, superadmin | Restoring an already-active user; admin restoring a superadmin |
| `POST` | `/admin/users/{user:public_id}/resend-invitation` | `UserAdminPolicy@resendInvitation` | admin, superadmin | Admin resending to a superadmin; sending credentials; arbitrary recipient addresses |
| `GET` | `/admin/users/{user:public_id}/ownership-impact` | `UserAdminPolicy@viewOwnershipImpact` | admin, superadmin | Any mutation; automatic reassignment |
| `GET` | `/admin/teams` | `TeamAdminPolicy@viewAny` | admin, superadmin | Cross-organization listing |
| `POST` | `/admin/teams` | `TeamAdminPolicy@create` | admin, superadmin | Creating a team for another organization |
| `GET` | `/admin/teams/{team}` | `TeamAdminPolicy@view` | admin, superadmin | Exposing user password or token fields |
| `PATCH` | `/admin/teams/{team}` | `TeamAdminPolicy@update` | admin, superadmin | Hard deletion; implicit member reassignment |
| `PATCH` | `/admin/teams/{team}/manager` | `TeamAdminPolicy@assignManager` | admin, superadmin | Assigning a suspended user or a salesrep as manager |
| `POST` | `/admin/teams/{team}/members` | `TeamAdminPolicy@addMember` | admin, superadmin | Adding a user outside the caller's allowed role scope |
| `DELETE` | `/admin/teams/{team}/members/{user:public_id}` | `TeamAdminPolicy@removeMember` | admin, superadmin | Automatically reassigning the removed user's CRM records; removing a manager without a replacement |
| `GET` | `/admin/settings` | `SettingPolicy@viewAny` | admin, superadmin | Returning secret values or environment dumps; admin reading superadmin-only keys |
| `PATCH` | `/admin/settings` | `SettingPolicy@update` | admin, superadmin | Arbitrary keys, secret keys, invalid ranges, or values outside the caller's key scope |
| `POST` | `/admin/settings/refresh` | `SettingPolicy@refresh` | admin, superadmin | Arbitrary cache flush, environment mutation, or destructive cache operations |
| `GET` | `/admin/audit-logs` | `AuditLogPolicy@viewAny` | admin, superadmin | Unbounded queries; admin reading security-sensitive events |
| `GET` | `/admin/audit-logs/{auditLog}` | `AuditLogPolicy@view` | admin, superadmin | Editing/deleting audit rows; admin reading redacted security fields |
| `GET` | `/admin/audit-logs/export` | `AuditLogPolicy@export` | admin, superadmin | Unbounded exports; secrets, hashes, message bodies, or private paths; export without an audit event |
| `GET` | `/admin/operations/summary` | `OperationsHealthPolicy@viewSummary` | admin, superadmin | Platform-only internals for admin |
| `GET` | `/admin/operations/health` | `OperationsHealthPolicy@viewHealth` | admin, superadmin | Credentials, tokens, environment dumps, or raw exception traces |
| `GET` | `/platform/diagnostics` | `PlatformDiagnosticPolicy@view` | superadmin | Arbitrary SQL, unrestricted table scans, schema dumps, secrets, or write operations |
| `POST` | `/admin/maintenance/refresh-configuration` | `MaintenanceActionPolicy@refreshConfiguration` | admin, superadmin | Generic cache flush, environment writes, or unconfirmed execution |
| `POST` | `/admin/maintenance/retry-email` | `MaintenanceActionPolicy@retryEmail` | admin, superadmin | Retrying sent/received messages; arbitrary recipients; channels other than email; bypassing `CommunicationPolicy::retry` |
| `GET` | `/me/settings` | `PersonalSettingsPolicy@view` | any authenticated role | Reading another user's settings |
| `PATCH` | `/me/profile` | `PersonalSettingsPolicy@updateProfile` | any authenticated role | Role, team, status, ownership, or organization changes |
| `PATCH` | `/me/preferences` | `PersonalSettingsPolicy@updatePreferences` | any authenticated role | Organization-wide settings or another user's preferences |
| `PUT` | `/me/password` | `PersonalSettingsPolicy@changePassword` | any authenticated role | Missing current password; changing another user's password |
| `GET` | `/me/sessions` | `UserSessionPolicy@viewAny` | any authenticated role | Reading session payloads or another user's sessions |
| `DELETE` | `/me/sessions/{session}` | `UserSessionPolicy@revoke` | any authenticated role | Revoking another user's session |
| `POST` | `/me/sessions/revoke-others` | `UserSessionPolicy@revokeOthers` | any authenticated role | Revoking the caller's current session without a fresh re-authentication |

`CommunicationPolicy::retry` remains the owner of message state; the maintenance route delegates to it
rather than re-implementing the send rules. No admin route accepts an arbitrary `user_id` for ownership
changes; ownership changes continue through the domain delegation endpoints.

### Settings allow-list

Settings are stored as typed values with an explicit scope and source. The API returns the effective
value and its source (`database`, `environment`, or `default`) without exposing secret contents.
Unknown keys are rejected; there is no free-form key/value editor.

| Key | Type and rule | Scope | Write role | Notes |
| --- | --- | --- | --- | --- |
| `organization.display_name` | string, 1–120 | organization | admin | Presentation only |
| `organization.support_email` | valid email | organization | admin | Public support contact |
| `organization.logo_url` | absolute URL, http/https | organization | admin | Presentation only; no file upload in Phase 1 |
| `organization.timezone` | enum `Asia/Manila` | organization | admin | PH business calendar; multi-timezone deferred |
| `organization.locale` | enum `en-PH` | organization | admin | Fixed display locale |
| `organization.currency` | enum `PHP`, read-only | organization | — | Single currency by product rule |
| `crm.aging.stale_days` | int 1–365 | system | admin | Module 04 aging |
| `crm.aging.at_risk_days` | int, greater than `stale_days` | system | admin | Module 04 aging |
| `crm.aging.archive_days` | int, greater than `at_risk_days` | system | admin | Module 04 aging |
| `crm.pipeline.stuck_days` | int 1–365 | system | admin | Module 02 stalled count |
| `crm.follow_ups.check_in_days` | int 1–365 | system | admin | Module 04 generated check-ins |
| `crm.attachments.max_kb` | int 1–10240 | system | admin | Module 03 attachment ceiling |
| `crm.attachments.max_files` | int 1–20 | system | admin | Module 03 per-record ceiling |
| `crm.contracts.expiring_soon_days` | int 1–365 | system | admin | Renewal/expiry window |
| `crm.surveys.invitation_days` | int 1–90 | system | admin | Public form lifetime |
| `crm.surveys.attention_csat_max` | decimal 1–5 | system | admin | Closed-loop threshold |
| `crm.surveys.attention_nps_max` | int 0–10 | system | admin | Closed-loop threshold |
| `crm.surveys.public_url` | absolute URL, http/https | system | admin | Client-facing survey origin; never a secret |
| `crm.integrations.email.simulate_failure` | bool | system | admin | Mocked email only |
| `crm.integrations.hr.simulate_failure` | bool | system | admin | Mocked HR feed only |
| `crm.integrations.service_handoff.simulate_failure` | bool, read-only | system | — | Historical flag; the CRM no longer hands off |
| `notifications.database_enabled` | bool | system | admin | Mandatory security notifications stay on |
| `ai.reports.enabled` | bool | system | admin | Module 07 feature switch |
| `ai.reports.service_url` | absolute URL, read-only | system | — | Deployment-owned |
| `security.session_lifetime_minutes` | int 15–1440 | system | superadmin | Security-sensitive |
| `security.login_throttle_per_minute` | int 1–60 | system | superadmin | Security-sensitive |
| `security.password_min_length` | int 8–64 | system | superadmin | Security-sensitive |
| `security.mfa_required` | bool, read-only `false` | system | — | MFA is deferred |
| `user.date_format` | enum `DD MMM YYYY` | user | self | Personal preference |
| `user.table_density` | enum `comfortable`, `compact` | user | self | Personal preference |
| `user.default_landing_page` | allow-listed route | user | self | Personal preference |
| `user.notification_database` | bool | user | self | Security notifications cannot be disabled |

**Never settings:** `APP_KEY`, JWT secrets, database credentials, mail credentials, AI provider keys,
`AI_SERVICE_TOKEN`, private storage paths, password hashes, and any value whose only source is an
environment secret. Those remain deployment-owned.

### Audit event vocabulary

Audit actions use `domain.action` in the past tense. `severity` is one of `info`, `notice`,
`warning`, or `critical`. Every row carries `actor_id` (nullable for system events), `subject_type`,
`subject_id`, redacted before/after snapshots, request metadata, `request_id`, and `created_at`.

| Action | Severity | When |
| --- | --- | --- |
| `auth.login_succeeded` | info | A user authenticates |
| `auth.login_failed` | notice | Invalid credentials or throttled login |
| `auth.logout` | info | A user logs out |
| `auth.password_changed` | notice | A password changes |
| `auth.session_revoked` | notice | A session is revoked by the user or security action |
| `user.created` | notice | An admin creates a user or sends an invitation |
| `user.updated` | info | A safe profile field changes |
| `user.role_changed` | warning | A role changes |
| `user.team_changed` | notice | A team membership changes |
| `user.suspended` | warning | A user is suspended |
| `user.restored` | notice | A suspended user is restored |
| `user.invitation_resent` | info | An invitation is resent through the mocked gateway |
| `team.created` | info | A team is created |
| `team.updated` | info | A team name/manager changes |
| `team.member_added` | info | A member joins a team |
| `team.member_removed` | notice | A member leaves a team |
| `setting.updated` | notice | An allow-listed setting changes |
| `setting.reset` | notice | A setting returns to its default |
| `setting.refreshed` | info | The effective configuration view is refreshed |
| `audit.exported` | notice | An audit CSV export is produced |
| `maintenance.email_retried` | notice | A supported failed email is retried |
| `maintenance.configuration_refreshed` | info | The allow-listed configuration refresh runs |
| `diagnostic.viewed` | notice | A platform diagnostic section is read |
| `security.access_denied` | warning | A role boundary or policy rejects an administrative request |
| `security.final_superadmin_guard` | critical | An action is blocked because it would remove the last active superadmin |

The event list is intentionally closed for S1–S10. New actions require a spec update and an
allow-list entry in the audit policy. Audit rows are append-only; a correction is a new event.

### Redaction rules

| Data class | Rule |
| --- | --- |
| Passwords and password confirmations | Never persisted in audit snapshots; never returned; never logged |
| Tokens, JWT keys, cookies, authorization headers | Removed before audit persistence and response serialization |
| Database/mail/AI credentials and `APP_KEY` | Never stored in settings or audit; environment-only |
| Private storage paths, attachment paths, file contents | Never returned in admin/diagnostic responses or audit metadata |
| Message bodies, notes, survey free text | Omitted from list/export payloads by default; truncated to a redacted summary only when a detail view explicitly needs it |
| Contact email/phone and other PII in audit | Masked in list/export responses; the live record remains governed by its own policy |
| Request metadata | Method, path, status, correlation id, IP and user-agent only; no body, query secrets, or headers |
| CSV exports | Allow-listed columns, formula-injection protection, row/date bounds, and the export action audited |
| Error responses | Stable message and correlation id; no stack traces, SQL, paths, or secret values |

### Threat model

| Abuse case | Control |
| --- | --- |
| Admin promotes themselves or creates an admin/superadmin | Role-scoped `UserAdminPolicy`; only superadmin can assign admin; superadmin creation is not a UI route |
| Last active superadmin is suspended or demoted | Database/application guard on every lifecycle and role mutation; `security.final_superadmin_guard` audit event |
| Suspended user keeps using an old JWT | Status check on authenticated requests; session revocation; suspended users cannot authenticate or mutate |
| Admin changes a role/team to gain ownership access | Policies recalculate on the next request; team changes never reassign CRM records; ownership changes use domain delegation |
| Admin bypasses a domain state machine through an admin endpoint | No admin write routes for CRM records; existing policies and state rules remain authoritative |
| Arbitrary SQL or destructive platform operation through the UI | No SQL console, command runner, migration, backup, restore, or generic cache-flush endpoint; diagnostics use fixed queries |
| Secrets leak through settings, diagnostics, audit, or export | Environment-only secret storage; key allow-list; redaction layer before persistence/response; export column allow-list |
| Audit history is edited or deleted | Append-only application model; no update/delete routes; exports audited |
| Impersonation or silent privilege assumption | Not delivered; no impersonation endpoint |
| IDOR/enumeration on admin resources | ULID public ids for users, teams, audit rows and sessions; policy checks on every route |
| Audit or settings growth causes denial of service | Bounded pagination, indexes, query timeouts, and bounded exports |
| Retry action is used to spam or resend a completed message | Delegates to `CommunicationPolicy::retry` and the message state; repeated execution is idempotent or a 422 state error |
| Admin sees platform internals | Separate `/platform` routes and `PlatformDiagnosticPolicy`; admin health responses exclude platform-only detail |

### S0 acceptance record

- [x] ADR-0030 is accepted and names this spec as the detailed contract.
- [x] This spec and `implementation/system-roles.md` use the same role boundaries.
- [x] Every proposed endpoint has an owning policy and an explicit forbidden operation.
- [x] Settings are an allow-list with type, range, scope, write role and secret exclusions.
- [x] Audit actions, severities, append-only behavior, redaction and export rules are defined.
- [x] The threat model covers privilege escalation, lockout, stale sessions, secret leakage, audit tampering, IDOR and destructive actions.

## Delivery slices

Each slice is independently demoable and should land as one PR. S0 is documentation and decision
work; later slices are implementation work.

### S0 — Administration contract and threat model *(built)*

Document the role matrix, resource boundaries, settings allow-list, audit event vocabulary, and
redaction rules. Resolve the open questions at the end of this specification before coding.

Acceptance:

- [x] ADR-0030 is accepted or amended.
- [x] This spec and `implementation/system-roles.md` agree.
- [x] Every proposed endpoint has an owning policy and an explicit forbidden operation.
- [x] The settings allow-list, audit vocabulary, redaction rules and threat model are accepted.
- [x] The S0 contract is the implementation baseline for S1–S10.

### S1 — Shared administration shell and route protection *(built)*

Add admin/platform navigation groups, route guards, capability helpers, empty states, and a shared
admin layout using the existing `DashboardShell`. Do not add fake controls without an API contract.
S1 ships `GET /admin/access` (the capability probe), the `RoleRoute` guard, the Forbidden surface,
the `Administration`, `Platform Operations` and `Personal` sidebar groups, and inert placeholder
pages for S2–S9.

Acceptance:

- [x] salesrep and manager cannot see or open admin routes;
- [x] admin sees application administration only;
- [x] superadmin sees application plus platform operations;
- [x] all roles see Personal Settings;
- [x] unauthorized access returns the existing authenticated error shape (`403` from
  `AdminAccessPolicy`, mirrored by the shell's 403 page).

### S2 — User directory and account lifecycle *(built)*

Deliver the admin user directory: search, role/team/status filters, detail view, create/invite,
activate, suspend, restore, and safe profile edits. A suspended user cannot authenticate or mutate
CRM data. Preserve ownership history when a user is suspended; do not silently reassign records.

Acceptance:

- [x] admins manage manager/salesrep accounts; only superadmins create or promote admins;
- [x] only superadmins manage superadmin accounts; creating a superadmin is not exposed in the UI;
- [x] the final active superadmin cannot be suspended;
- [x] every lifecycle mutation is audited and visible in the activity detail;
- [x] invitation delivery uses the existing mocked gateway or a clearly labelled local fallback.

Built as: the `users` lifecycle columns (`public_id` ULID URL key, `status`, `suspended_at`,
`suspended_by`, `status_reason`), the append-only redacting `audit_logs` writer, `UserAdminPolicy`,
`EnsureUserIsActive`, and the `/admin/users` routes from the inventory above plus
`GET /admin/users/team-options` — the directory's team lookup for its filter and create form, not
team administration, which S3 owns. The frontend is `/admin/users`: search and role/team/status
filters, a create/invite form, and a detail panel whose controls are gated by the row's own `can`
block. The ownership-impact preview is read-only and states that suspension and team changes never
reassign records.

### S3 — Teams, roles, and ownership administration *(built)*

Deliver team listing/detail, manager assignment, membership changes, and a role/team change
workflow. Role changes must be explicit, confirmed, audited, and reflected in the next request.

Acceptance:

- [x] team manager and membership constraints remain valid;
- [x] admins cannot assign a user to an out-of-scope role;
- [x] changing a user's team does not reassign CRM records automatically;
- [x] ownership reassignment uses existing domain delegation endpoints or a reviewed bulk operation;
- [x] the user detail shows an ownership-impact preview before a risky change.

Built as: `GET|POST /admin/teams`, `GET|PATCH /admin/teams/{team}`, `PATCH
/admin/teams/{team}/manager`, `POST /admin/teams/{team}/members` and `DELETE
/admin/teams/{team}/members/{user:public_id}`, all behind `TeamAdminPolicy`. A manager must be an
active account with the manager role (`EligibleTeamManager`); a team's current manager cannot be
removed as a member, and a manager who still leads a team cannot be demoted or suspended until the
team is reassigned. Administrators may add manager/salesrep accounts, while only a superadmin may
move an administrator; superadmins are never team members. Membership changes only write
`users.team_id` — never a lead, account or deal owner. The role control in `/admin/users` now asks
for explicit confirmation and shows the ownership-impact preview before the change; every team
mutation writes `team.created`, `team.updated`, `team.member_added` or `team.member_removed`.
`/admin/teams` is the directory and detail workspace: create/rename, assign a manager, inspect
members and add or remove them. The seed adds an unmanaged **Enterprise Accounts** team beside
Business Development so the flow demos without creating one first.

### S4 — Organization and CRM settings *(built)*

Deliver typed settings read/update APIs and the settings UI. Group settings by the categories above,
show effective source, validate ranges, and preserve defaults when no override exists.

Acceptance:

- [x] admin can change safe CRM thresholds and organization presentation settings;
- [x] superadmin-only settings are hidden and rejected for admin;
- [x] setting changes affect the next read/dispatch where the owning module supports runtime settings;
- [x] unsupported or secret settings cannot be written through the UI;
- [x] changes create audit entries with old/new values after redaction.

Built as: `GET|PATCH /admin/settings` and `POST /admin/settings/refresh`, all behind
`SettingPolicy`. `App\Settings\SettingRegistry` is the allow-list and `App\Settings\EffectiveSettings`
resolves override → environment → default, caching one snapshot and pushing the effective values onto
`config()` through the API middleware (`ApplySettings`) so the owning modules read the runtime values
unchanged. A write upserts one typed `settings` row, deletes it when the value returns to the default
(`setting.reset`), and audits `setting.updated`/`setting.reset` with the key's old and new values;
`setting.refreshed` records the bounded snapshot rebuild. Unknown keys, secret-shaped keys and
read-only keys are rejected, an admin never sees the Security category, and `notifications.database_enabled`
is enforced at notification send time. `/admin/settings` renders the server's grouped allow-list with
source badges and inline editors only where the key is editable. The runtime wiring covers the CRM
aging/pipeline/follow-up/attachment/contract/survey thresholds, the mocked email/HR failure flags, the
AI report switch and the organization timezone.

### S5 — Audit and security activity *(built)*

Deliver an immutable audit log book with filters for actor, action, resource, date range, and
severity, plus a detail view showing redacted before/after values and request metadata. This is a
first-class priority, not an administrative afterthought. Include a bounded export for compliance
and panel review.

Acceptance:

- audit coverage includes authentication events, user/team/role changes, settings changes, and
  administrative actions;
- audit visibility is admin+; security-sensitive events and redacted metadata are superadmin-only
  where required;
- audit rows cannot be edited or deleted by API users;
- pagination and filtering are bounded and indexed;
- a correlation id connects a multi-step administrative mutation.
- exports are permissioned, redacted, bounded by date/row count, and themselves audited;
- exports use a documented format (CSV first; JSON may be added for machine consumers) and never
  include secrets, hashes, private paths, or message bodies by default.

Built as: `GET /admin/audit-logs` (actor, action, resource, severity, date-range and correlation-id
filters; per-page capped at 100), `GET /admin/audit-logs/{auditLog}` (redacted before/after and
request metadata) and `GET /admin/audit-logs/export` (CSV, 5,000-row and 366-day bounds, itself
audited as `audit.exported`), all behind `AuditLogPolicy`. `AuditLog` is append-only at the model
layer (update/delete throw) and the writer already strips secret-shaped keys; `AuditPresenter`
masks contact email/phone and actor emails again on read, and the CSV neutralises formula
injection. An application admin never sees `security.*` or `auth.login_failed`; a superadmin does.
The correlation id is minted once per request and cached, so a multi-step mutation's rows share it.
`/admin/audit` renders the book with grouped event labels, severity badges, a detail panel and the
bounded export.

### S6 — Integration and operational health

Deliver `/admin/operations` as a **read-only** operational dashboard backed by
`GET /admin/operations/summary` and `GET /admin/operations/health`. Both endpoints use
`OperationsHealthPolicy` and are available to admin and superadmin. The response is role-shaped:
admins receive application health only; superadmins receive that same application view plus a
separate, explicitly platform-only summary. The UI must not treat a hidden client-side section as an
authorization boundary.

#### Health-check contract

`GET /admin/operations/health` returns `{ data: { generated_at, checks } }`. Each check has a stable
`key`, human-readable `label`, `status`, `checked_at`, short safe `summary`, optional `remediation`,
and `source` (`application`, `mocked`, or `external`). `status` is exactly one of `healthy`,
`degraded`, `unavailable`, or `not_configured`. The application-plane keys are `database`,
`storage`, `email`, `ai`, `hr`, `recruitment`, `deployment`, `timekeeping`, `notifications`, and
`application`. Superadmins additionally receive `queue` and `schema` checks where the corresponding
subsystem exists; absence is represented explicitly, never as healthy.

Probe behavior is constrained as follows:

- **Database:** execute one bounded connection/read probe; do not enumerate tables or include the
  exception, connection string, SQL, or driver credentials in the response.
- **Storage:** inspect availability/writability of the configured private attachment disk without
  returning its path, listing files, reading customer files, or leaving a probe file behind.
- **Email:** report the bound gateway's mocked/external source and configured failure state. Do not
  send a test message or expose recipients/provider configuration.
- **AI:** when a service URL is configured, call only its health endpoint using a strict two-second
  connect/total timeout; do not generate a report or include the URL, response body, token, or raw
  exception. Missing URL is `not_configured`; timeout/non-success is `unavailable` with safe retry
  guidance.
- **HR and department feeds:** report the bound gateway/driver availability and whether it is
  mocked. Do not imply a mocked feed is a live department connection. Use only bounded, read-only
  probes where the interface supports one; no writes or broad data pulls.
- **Notifications:** report database notification-channel availability and the effective
  `notifications.database_enabled` state. Do not send a notification as a probe. A disabled channel
  is `degraded`, not `healthy`.
- **Application:** expose only the application build label (or `unknown`) and effective-configuration
  availability/source counts. Do not return environment values, config contents, or secret names.
- **Queue/schema:** superadmin-only capability checks. Do not run migrations, mutate schema, or inspect
  arbitrary tables; if queue/schema metadata is not configured or cannot be checked safely, return
  `not_configured`/`unavailable` and a safe explanation.

Every probe must have a bounded execution time, catch infrastructure exceptions into the four-state
contract, and return stable remediation text. Responses and logs must not contain credentials,
JWTs, environment dumps, stack traces, SQL, private paths, message bodies, or raw provider
responses. A successful probe means only that the named dependency check succeeded at `checked_at`;
it must not claim that a fallback or delivery succeeded.

#### Summary contract

`GET /admin/operations/summary` returns `{ data: { generated_at, accounts,
administrative_activity, integrations, failed_operations, maintenance, configuration_changes,
platform } }`:

- `accounts` contains active and suspended user counts.
- `administrative_activity` contains the last-24-hour count, severity counts, and at most ten recent
  audit summaries (`id`, `action`, `severity`, actor display name or null, `created_at`). It never
  includes before/after snapshots. For an admin, security-sensitive events and their counts are
  excluded; superadmin sees the full allowed audit summary.
- `integrations` contains counts by the four health statuses and links/keys to the corresponding
  read-only health sections.
- `failed_operations` contains a count of failed email communications (no recipient, subject, body,
  or failure reason).
- `maintenance` shows the number of retryable failed email records and whether the already-defined
  configuration refresh is available. S6 exposes no action button and does not execute maintenance;
  confirmations/actions belong to S7.
- `configuration_changes` contains the count of `setting.updated`/`setting.reset` events in the
  last seven days, without returning setting values. Admin counts obey the same security-event
  filtering as activity.
- `platform` is `null` for admins. For superadmins it contains only application build/runtime
  version labels, a safe schema state (`current`, `pending`, `unavailable`, or `not_configured`),
  bounded counts for failed jobs and allow-listed CRM entity totals where available, and counts of
  security-sensitive warning/critical events in the last 24 hours. Missing optional tables have an
  explicit unavailable/not-configured state; no arbitrary table name or query is accepted.

All time windows use UTC storage and the application's standard `Asia/Manila` display conversion.
Counts must be computed with bounded aggregate queries, not by loading complete tables. Responses
must degrade gracefully if an optional table/driver is absent, without turning the whole dashboard
into an error.

#### UI and acceptance

`/admin/operations` replaces its S1 placeholder with a responsive, read-only dashboard: overall
health summary; labelled check cards with status, last-checked time, safe remediation and source; and
summary sections for accounts, recent administrative activity, audit severity, integration health,
failed email operations, pending maintenance, and recent configuration changes. Superadmins also
see the platform-only summary and alerts. Reuse the existing common components and status tokens;
provide loading, empty, partial/unavailable, error/retry states and accessible status text. Integration
links navigate to the owning module; the page does not duplicate CRM workflows or add maintenance
controls.

Acceptance:

- [ ] both endpoints enforce `OperationsHealthPolicy`; unauthenticated and non-admin roles are
  rejected, and an admin cannot retrieve platform-only fields by direct API request;
- [ ] all checks use exactly `healthy`, `degraded`, `unavailable`, or `not_configured`, with safe
  `checked_at`, summary and remediation where applicable;
- [ ] database, storage, mocked email, AI, HR/deployment feeds, notifications and application
  configuration are represented without side effects or secret/path disclosure;
- [ ] AI health requests use a two-second bound; a timeout/non-success returns a stable unavailable
  check rather than hanging or exposing provider details;
- [ ] admin summaries exclude security-sensitive events and details; superadmin platform fields are
  present only for superadmin;
- [ ] summary counts are bounded/aggregated and optional subsystem failures produce explicit partial
  states rather than a false healthy result;
- [ ] failed checks show truthful remediation guidance, and mocked sources are clearly labelled as
  mocked rather than live integrations;
- [ ] the UI has loading, empty, partial, error/retry and accessible status states, and links to
  owning modules without duplicating their operations;
- [ ] backend feature tests cover role boundaries, status mapping, timeouts, redaction, bounded
  aggregates and optional dependency failure; frontend tests cover the role-shaped summary, health
  cards, partial/error states and route protection.

### S7 — Safe maintenance actions

Expose only allow-listed, idempotent, auditable actions: refresh a cached configuration view,
retry a supported failed delivery, dispatch an already-defined due process, or rebuild a derived
read model if one exists. Actions must call existing services rather than duplicate workflows.

The confirmed first action set is:

1. refresh the effective configuration/cache view;
2. retry mocked email delivery where the owning communication state allows retry.

Dispatching due work and retrying AI generation remain available through their existing module
surfaces, but are not duplicated in the first administration demo.

Acceptance:

- each action has a confirmation, authorization policy, actor, result, and audit event;
- repeated execution is safe or rejected with an explicit state error;
- no generic “run command”, arbitrary SQL, migration, backup, or restore endpoint exists;
- admin and superadmin actions are distinguishable in the audit trail.

### S8 — Superadmin platform diagnostics

Deliver bounded read-only diagnostics: allow-listed table/entity counts, recent failed jobs or
requests if available, migration/schema version, storage usage summary, and environment capability
status. Add a redacted diagnostic export only if needed for support.

Acceptance:

- diagnostics are read-only and use fixed queries;
- responses exclude secrets, tokens, hashes, document paths, message bodies, and unrestricted PII;
- every diagnostic access is audited;
- a query timeout or unavailable subsystem is surfaced explicitly.

### S9 — Personal settings for every role

Deliver `/settings/profile`, `/settings/preferences`, `/settings/security`, and a notification
preferences panel. Keep organization settings and admin navigation separate.

Acceptance:

- every authenticated role can update only their own allowed preferences;
- password changes require the current password and invalidate other sessions if supported;
- notification preference changes do not disable mandatory security notifications;
- the sidebar/footer exposes Settings for every role.

### S10 — Seeds, fixtures, and verification

Extend deterministic seed data with at least one suspended user, multiple teams, an admin audit
history, an integration failure, and representative settings. Add backend policy/feature tests and
frontend route/permission tests for every slice.

Acceptance:

- no faker is introduced;
- demo accounts remain usable with password `password`;
- admin and superadmin demo flows are reproducible from `migrate:fresh --seed`;
- the full backend and frontend suites pass.

## Deferred capabilities

The following are deliberately deferred unless a later ADR reopens them:

- multi-organization tenancy and tenant billing;
- SSO/SAML/SCIM;
- mandatory MFA and hardware-key enrollment;
- arbitrary database query consoles;
- impersonation;
- production backup/restore and migration execution;
- full job queue administration;
- email provider configuration with real credentials;
- permission-builder UI or custom roles;
- data export of unrestricted CRM records.

## Decisions needed before S1

1. **Confirmed:** only superadmins create or promote admins.
2. **Confirmed:** user accounts are suspended/restored; deletion is not in the first module.
3. **Confirmed:** onboarding uses a mocked email invitation link.
4. **Confirmed:** sensitive role changes use confirmation plus immutable audit, not two-person approval.
5. **Confirmed:** session management comes before MFA; MFA remains future scope.
6. **Confirmed:** settings are organization-wide only in the single-tenant phase.
7. **Confirmed:** superadmin diagnostics are bounded, allow-listed, and read-only.
8. **Confirmed:** audit export is bounded CSV with indefinite retention.
9. **Confirmed:** the first admin dashboard uses an equal-weight operational summary.
10. **Confirmed:** the first administration demo includes configuration refresh and mocked-email retry.
