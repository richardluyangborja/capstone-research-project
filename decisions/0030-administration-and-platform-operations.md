# 0030 — Administration and platform operations boundary

- Status: Accepted
- Date: 2026-11-08

## Context

The CRM already defines four roles, but `admin` and `superadmin` currently have only broad
visibility semantics. A real operating system needs user lifecycle, team and access management,
typed configuration, auditability, integration health, and safe support diagnostics. The current
description of superadmin as having “database table queries” is too broad for a production-facing
application and could encourage arbitrary SQL or destructive access.

## Decision

Define two administration planes:

1. **Application administration** for `admin`: users below superadmin, teams, safe CRM settings,
   audit history, and operational health.
2. **Platform operations** for `superadmin`: admin/platform access, security-sensitive settings,
   redacted diagnostics, and bounded read-only data inspection.

Every signed-in role receives personal settings. Only superadmins can create or promote admins.
User lifecycle is suspend/restore, not deletion, and onboarding uses a mocked invitation link.
Administrative actions use explicit policies, organization-scoped allow-listed settings and
maintenance actions, immutable audit records, redaction, confirmation, and idempotency. The UI
never exposes arbitrary SQL, credentials, password hashes, private file paths, migrations, backups,
or unrestricted destructive operations. Sessions are in scope before MFA.

Operations dashboards, audit log review, and bounded redacted audit export are first-class
deliverables. Superadmin diagnostics remain read-only and allow-listed.

## Consequences

- Administration becomes a documented module rather than an implicit bypass around CRM policies.
- The role model remains understandable: admin operates the application; superadmin operates the
  platform.
- Some support and deployment tasks remain outside the product and require infrastructure access.
- The module adds audit/settings data and must protect those records more strictly than ordinary
  CRM data.
- The detailed delivery contract is [`specs/19-administration-and-platform-operations.md`](../specs/19-administration-and-platform-operations.md).
