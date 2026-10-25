# 0022 — Public AI Service with Shared Bearer Auth

- Status: Accepted
- Date: 2026-10-25

## Context

Module 07's `ai/` service was designed as a private backend dependency. The deployment platform may
not offer private networking or a private service tier, so the FastAPI service has to be reachable
over public HTTPS. It holds the Groq key, accepts Laravel's aggregated report context and spends the
free provider quota on every `POST /reports/dashboard` call.

The browser never calls this service. The only caller is Laravel's `ManagementReportGenerator`. The
existing CRM user auth (JWT, ADR-0008) does not apply: there is no CRM user on this request, and
issuing a user token for a service call would mix two different trust boundaries.

## Decision

1. **Require a shared bearer token on the report endpoint.** Laravel sends
   `Authorization: Bearer <AI_SERVICE_TOKEN>`; FastAPI accepts the call only when the supplied token
   matches the value configured on the service.
2. **Use one secret in two places.** `AI_SERVICE_TOKEN` lives in the backend environment and in the
   `ai/` service environment. The browser bundle, frontend env and database never see it. Generate it
   once per environment with at least 32 random bytes (`openssl rand -hex 32`).
3. **Fail closed.** If the AI service has no token configured, it returns `503 not_configured`; it
   never falls back to anonymous access. If Laravel has no token configured, it does not call the
   service and returns the rules report with `fallback.reason = not_configured`.
4. **Compare in constant time.** The Python service uses `secrets.compare_digest`; a missing or
   malformed header is `401` with `WWW-Authenticate: Bearer`.
5. **Keep `/health` public.** The platform health probe needs it, and it exposes no data. Report
   calls are authenticated; the OpenAPI/docs routes may remain available but cannot invoke a report
   without the token.
6. **HTTPS is mandatory.** The token is a bearer credential, so plain HTTP would expose it in
   transit. It belongs in the `Authorization` header, never a query string or log line.
7. **No OAuth, JWT or HMAC in this iteration.** A shared bearer token is the smallest mechanism that
   satisfies a single trusted caller over TLS. HMAC would add replay protection and more moving
   parts; a service JWT would add key management and expiry handling for no user-facing benefit.
   The token can be rotated by deploying matching values; there is no multi-token grace window yet.

## Consequences

- `AI_SERVICE_TOKEN` is added to `backend/.env.example` and `ai/.env.example`, and to the deployment
  guide's env tables and first-deploy checklist.
- `ManagementReportGenerator` sends the token and skips the provider when it is missing;
  `ai/app/main.py` rejects unauthenticated report calls.
- Backend tests assert the outgoing `Authorization` header and the missing-token fallback. Python
  tests cover public health, missing/wrong tokens and the unset-token configuration.
- The service may be deployed publicly without exposing the report endpoint anonymously, but a
  leaked token still allows quota use. Platform-level rate limiting and token rotation remain
  operational controls.
- The data contract and the rules fallback do not change. This is transport auth, not a new report
  feature.
