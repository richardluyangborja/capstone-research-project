# System roles

The CRM has four roles. Domain permissions remain enforced server-side through the existing
policies (ADR-0004); the administration module adds a separate role boundary rather than a second
path for editing CRM records.

| Role | Scope | Primary responsibility |
| --- | --- | --- |
| `superadmin` | Platform operations and security | Admin/platform access, security-sensitive settings, bounded read-only diagnostics |
| `admin` | Application administration | Users below superadmin, teams, safe CRM settings, audit history, integration health |
| `manager` | Team oversight | Manages salesreps, reviews team records, view-heavy with limited operations |
| `salesrep` | Own CRM work | Main operator; sensitive business data is hidden and escalated to a manager |

## Capability boundaries

| Capability | salesrep | manager | admin | superadmin |
| --- | --- | --- | --- | --- |
| CRM records | Own, per existing policies | Team, per existing policies | All, per existing policies | All, per existing policies |
| Admin application plane | No | No | Yes | Yes |
| Platform operations plane | No | No | No | Yes |
| User lifecycle | No | No | Manager/salesrep accounts below superadmin | All accounts, except the final-active guard |
| Create/promote admins | No | No | No | Yes |
| Create superadmins | No | No | No | No — seeded/platform only |
| Team administration | No | No | Yes | Yes |
| Safe CRM settings | No | No | Yes | Yes |
| Security/platform settings | No | No | No | Yes |
| Audit history | No | No | Non-security, redacted | All, including security events |
| Operational health | No | No | Application dependencies | Application plus platform diagnostics |
| Safe maintenance actions | No | No | Allow-listed | Allow-listed |
| Personal settings | Yes | Yes | Yes | Yes |
| Impersonation | No | No | No | No |
| Arbitrary SQL, migrations, backups, restores, shell | No | No | No | No |
| Credentials, JWT keys, password hashes, private paths | No | No | No | No |

## Administration plane

`admin` operates the application:

- manages manager/salesrep users, teams, membership, activation and role assignment below
  `superadmin`;
- configures the typed, allow-listed CRM settings;
- reads and exports redacted audit history;
- inspects application and integration health;
- runs only the explicit safe maintenance actions exposed by the owning modules.

`admin` cannot create or promote admins, manage superadmins, change platform security settings, or
use an administrative endpoint to skip a CRM policy or state machine.

`superadmin` additionally operates the platform: admin/platform access, security settings, bounded
read-only diagnostics, and security-sensitive audit review. Neither role may run arbitrary SQL,
execute migrations, manage backups/restores, inspect credentials or password hashes, browse private
attachment paths, or impersonate a user.

## Personal settings

Every signed-in role has a personal settings surface for profile details, password change,
timezone/display preferences, notification preferences, and active sessions. Personal settings can
never change role, team, ownership scope, or organization-wide settings.

## Source of truth

The detailed role matrix, endpoint policies, settings allow-list, audit vocabulary, redaction rules
and threat model live in `../specs/19-administration-and-platform-operations.md` (ADR-0030). The
roles in this file are descriptive; the policies in the backend remain authoritative.
