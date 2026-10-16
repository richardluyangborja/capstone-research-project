# 0008 — JWT auth package

- Status: Accepted
- Date: 2026-10-16

## Context

Auth strategy is **JWT, not cookies** (see `system-tech.md` / AGENTS.md): the SPA and API are on
different origins. Laravel 13 is new, so the package must explicitly support it.

## Decision

Use **`php-open-source-saver/jwt-auth`** (the actively maintained fork of `tymon/jwt-auth`), which
declares support for `illuminate/* ^12|^13` and PHP ^8.3 (matches the L13 skeleton).

- Guard: `api` (driver `jwt`, provider `users`) is the **default** guard (`config/auth.php`).
- `App\Models\User` implements `JWTSubject`.
- Secret via `php artisan jwt:secret` → `JWT_SECRET` (not committed). TTL `JWT_TTL=1440` (1 day).

Rejected: `tymon/jwt-auth` (supports 13 but older release), `firebase/php-jwt` (framework-agnostic;
would need a hand-written guard + token issuance), Laravel Sanctum (cookie/session — disallowed).

## Consequences

- API routes use `auth:api`; clients send `Authorization: Bearer <token>`.
- Do **not** add Sanctum/Passport cookie auth (see AGENTS.md).
