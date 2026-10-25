# Deployment Guide

This guide covers the three apps in this repository and the database they share:

| App | Runtime | Public? | What it does |
| --- | --- | --- | --- |
| `backend/` | PHP 8.3+ / Laravel 13 | Yes, HTTPS | `/api/v1` REST API, JWT auth, seeded demo data |
| `frontend/` | Node build → static files | Yes, HTTPS | React 19 + Vite SPA |
| `ai/` | Python 3.13 / FastAPI | Yes, HTTPS + bearer token | Module 07 report generation through Groq |
| Postgres | Neon or local Docker | No | Primary database |

> **Scope:** this is a demo/staging deployment with the seeded demo data. It is not a
> production-hardening guide for real customer data. The demo accounts are intentionally public.

**Contents**

1. [Deployment shape](#1-deployment-shape)
2. [URLs and hostnames](#2-urls-and-hostnames)
3. [Which env file belongs to which app](#3-which-env-file-belongs-to-which-app)
4. [Backend environment variables](#4-backend-environment-variables)
5. [Frontend environment variables](#5-frontend-environment-variables)
6. [AI service environment variables](#6-ai-service-environment-variables)
7. [Local development](#7-local-development)
8. [Deploying the backend](#8-deploying-the-backend)
9. [Deploying the frontend](#9-deploying-the-frontend)
10. [Deploying the AI service](#10-deploying-the-ai-service)
11. [CORS configuration](#11-cors-configuration)
12. [Recommended first deployment order](#12-recommended-first-deployment-order)
13. [Verification checklist](#13-verification-checklist)
14. [Troubleshooting](#14-troubleshooting)
15. [What this deployment does not include](#15-what-this-deployment-does-not-include)

## 1. Deployment shape

```text
Browser
  │  HTTPS
  ▼
Frontend static host ──────────────┐
  │  VITE_API_URL (browser → API)  │
  ▼                                │
Laravel API  ── HTTPS/SQL ──► Neon Postgres
  │
  │  AI_SERVICE_URL (server → server)
  ▼
FastAPI AI service ── HTTPS ──► Groq API
```

The browser never calls the AI service. The AI service is a **server-side dependency**; the
deployment platform may expose it on a public HTTPS URL, but `POST /reports/dashboard` requires the
shared `AI_SERVICE_TOKEN` bearer credential. `/health` stays public for platform probes. See
section 10 for the auth setup.

Recommended deployment unit layout:

| Unit | Source | Build | Start / publish |
| --- | --- | --- | --- |
| Frontend static site | `frontend/` | `npm ci && npm run build` | publish `frontend/dist` |
| Backend web service | `backend/` | `composer install --no-dev --optimize-autoloader` | PHP-FPM/web server, or `php artisan serve` for a demo |
| AI web service | `ai/` | `pip install -r requirements.txt` or the included `Dockerfile` | `uvicorn app.main:app --host 0.0.0.0 --port 8001` |
| Database | — | — | Neon Postgres |

The root `docker-compose.yml` is for **local Postgres only**. Do not use it as the production
deployment definition.

## 2. URLs and hostnames

Use stable custom domains where possible. Pick one frontend origin and redirect the other
(`www` vs apex) rather than allowing both.

| Value | Local | Example deployment |
| --- | --- | --- |
| Frontend origin | `http://localhost:5173` | `https://crm.example.com` |
| API origin | `http://localhost:8000` | `https://api.example.com` |
| API base URL | `http://localhost:8000/api/v1` | `https://api.example.com/api/v1` |
| AI service | `http://127.0.0.1:8001` | `https://ai.example.com` (public, bearer-protected) |
| Database | `127.0.0.1:5432` (Docker) | Neon connection string |
| Survey link | `http://localhost:5173/survey/{token}` | `https://crm.example.com/survey/{token}` |

`FRONTEND_URL` is the **frontend browser origin**, not the API URL and not the AI URL. It is used
for CORS and as the fallback for survey links.

## 3. Which env file belongs to which app

Each app has its own environment file. Do not put every value in one root `.env`.

| App | Local file | Deployment location |
| --- | --- | --- |
| Backend | `backend/.env` | Backend service environment variables |
| Frontend | `frontend/.env` (or `.env.production`) | Frontend **build** environment |
| AI service | `ai/.env` | AI service environment variables |
| Root | — | Not read by the apps |

`.env` files are git-ignored. Never commit real secrets.

Vite exposes only `VITE_*` values to the browser. Everything in `frontend/.env` must be considered
public. Do not put a Groq key, JWT secret, or database password in the frontend.

## 4. Backend environment variables

### 4.1 Required values

| Variable | Local | Deployment | Notes |
| --- | --- | --- | --- |
| `APP_NAME` | `Primepower ISMERS` | same | Display name |
| `APP_ENV` | `local` | `production` | |
| `APP_KEY` | generated | unique `base64:...` | `php artisan key:generate --show` |
| `APP_DEBUG` | `true` | `false` | Never leave `true` on a public host |
| `APP_URL` | `http://localhost:8000` | `https://api.example.com` | Backend public origin |
| `APP_LOCALE` | `en_PH` | `en_PH` | |
| `APP_FALLBACK_LOCALE` | `en` | `en` | |
| `APP_FAKER_LOCALE` | `en_PH` | `en_PH` | Seeds do not use faker, but keep it PH |
| `LOG_CHANNEL` | `stack` | `stderr` | Platform log collectors read stderr |
| `LOG_LEVEL` | `debug` | `info` or `warning` | |
| `DB_CONNECTION` | `pgsql` | `pgsql` | Neon is standard Postgres |
| `DB_URL` | unset | Neon URL | If set, overrides host/port/database/username/password |
| `DB_SSLMODE` | `prefer` | `require` | Neon requires TLS |
| `JWT_SECRET` | generated | unique secret | `php artisan jwt:secret --show` |
| `JWT_TTL` | `1440` | `1440` | Minutes; 24 hours |
| `JWT_ALGO` | `HS256` | `HS256` | |
| `FRONTEND_URL` | `http://localhost:5173` | `https://crm.example.com` | Exact browser origin, no trailing slash |
| `CRM_SURVEY_URL` | `http://localhost:5173` | `https://crm.example.com` | Public survey page origin |
| `FILESYSTEM_DISK` | `local` | `local` or `s3` | See storage below |
| `CRM_CONTRACT_DISK` | `local` | `local` or `s3` | Contract documents |
| `CRM_ATTACHMENT_DISK` | `local` | `local` or `s3` | Communication attachments |
| `CACHE_STORE` | `database` | `database` | AI report cache, cooldowns and rate limits |
| `SESSION_DRIVER` | `database` | `database` | JWT is used, but keep a working session store |
| `QUEUE_CONNECTION` | `database` | `database` or `sync` | No queue worker is required by the current code |
| `MAIL_MAILER` | `log` | `log` | Email is mocked; do not configure real SMTP |
| `AI_REPORT_ENABLED` | `true` | `true` or `false` | `false` is the rules-only fallback demo |
| `AI_SERVICE_URL` | `http://127.0.0.1:8001` | `https://ai.example.com` | Server-to-server; base URL only, no trailing slash or `/reports/dashboard` |
| `AI_SERVICE_TOKEN` | generated | same value as AI service | Shared bearer secret for the public AI service |
| `AI_TIMEOUT_SECONDS` | `10` | `10` | Allows a public AI service to cold-start; the provider call itself is still bounded at 2.5s |
| `AI_RETRY_ATTEMPTS` | `1` | `1` | One extra attempt only when the connection (not the provider) times out |
| `AI_RETRY_DELAY_MS` | `750` | `750` | Pause before the retry |
| `AI_CACHE_TTL_MINUTES` | `15` | `15` | |
| `AI_REFRESH_COOLDOWN_MINUTES` | `5` | `5` | |
| `AI_DAILY_LIMIT` | `800` | `800` | Organization-wide daily cap |
| `AI_USER_DAILY_LIMIT` | `30` | `30` | Per-user daily cap |

Generate the backend secrets once per environment:

```sh
cd backend
php artisan key:generate --show   # APP_KEY
php artisan jwt:secret --show     # JWT_SECRET
openssl rand -hex 32              # AI_SERVICE_TOKEN (copy to both services)
```

`APP_KEY` and `JWT_SECRET` are unique to the backend. `AI_SERVICE_TOKEN` must be the **same value**
on the backend and the AI service. Keep all three stable across deploys. Changing `APP_KEY` breaks
encrypted values; changing `JWT_SECRET` invalidates every existing login token; changing
`AI_SERVICE_TOKEN` requires deploying the matching value to both services.

Keep `CACHE_STORE=database` on a deployed instance. The AI report cache, refresh cooldown, daily
caps and Laravel's API throttling all rely on a shared cache. `array` resets on every request and
`file` is lost on an ephemeral filesystem.

### 4.2 Database: local Docker vs Neon

**Local (default)**

```dotenv
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=primepower
DB_USERNAME=primepower
DB_PASSWORD=secret
DB_SSLMODE=prefer
```

**Neon**

Neon is supported because Laravel's `pgsql` driver talks to standard Postgres. You can ignore the
managed platform's own database and point the backend only at Neon. Use the connection string Neon
gives you:

```dotenv
DB_CONNECTION=pgsql
DB_URL="postgresql://user:password@ep-...-pooler.region.aws.neon.tech/primepower?sslmode=require"
DB_SSLMODE=require
```

Notes:

- Create the Neon project in the same region as the backend. Cross-region database latency will
  make every page slower, regardless of compute size.
- `DB_URL` overrides `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, and `DB_PASSWORD` when
  present. You can remove the discrete values from the deployment environment to avoid confusion.
- The `-pooler` hostname is Neon's pooled endpoint. Use it for the application runtime if the
  platform runs many short-lived or concurrent processes.
- Use the **direct** (non-pooled) Neon URL for migrations and seeding. Neon's pooler can make
  schema migrations unreliable.
- If your platform hands you `DATABASE_URL`, copy that value into `DB_URL`. Laravel reads
  `DB_URL`, not `DATABASE_URL`.
- `channel_binding=require` can remain in the Neon URL. Laravel's Postgres connector ignores it.
  If another client rejects it, remove it from that client's URL.
- Neon free-tier computes can suspend when idle. The first query after a suspension is slower;
  this is normal.

Both Neon URLs are usually available from the **Connect** panel. Use one of these patterns:

```text
# Runtime (pooled)
postgresql://user:password@ep-...-pooler.region.aws.neon.tech/primepower?sslmode=require

# Migrations (direct)
postgresql://user:password@ep-....region.aws.neon.tech/primepower?sslmode=require
```

### 4.3 Storage: local disk vs object storage

Contracts and communication attachments are private files. The app streams them through
authorized API endpoints; they are not served from a public URL.

On a platform with an **ephemeral filesystem**, files written to `backend/storage` disappear on
redeploy or restart. Choose one:

**Option A — persistent volume (simplest for a demo)**

Mount a persistent disk at `backend/storage` (or at least `backend/storage/app/private`) and keep:

```dotenv
FILESYSTEM_DISK=local
CRM_CONTRACT_DISK=local
CRM_ATTACHMENT_DISK=local
```

**Option B — S3-compatible object storage**

```dotenv
FILESYSTEM_DISK=s3
CRM_CONTRACT_DISK=s3
CRM_ATTACHMENT_DISK=s3
AWS_ACCESS_KEY_ID=...
AWS_SECRET_ACCESS_KEY=...
AWS_DEFAULT_REGION=...
AWS_BUCKET=...
# AWS_URL=...
# AWS_ENDPOINT=...
# AWS_USE_PATH_STYLE_ENDPOINT=false
```

The bucket should be private. The app's download endpoints authorize access before streaming.
Contracts and attachments are served through those endpoints, so `php artisan storage:link` is not
required for them.

### 4.4 Optional CRM tuning

These have defaults and can be omitted:

| Variable | Default | Purpose |
| --- | --- | --- |
| `CRM_TIMEZONE` | `Asia/Manila` | Business calendar |
| `CRM_STALE_DAYS` | `14` | Lead stale threshold |
| `CRM_AT_RISK_DAYS` | `30` | Lead at-risk threshold |
| `CRM_ARCHIVE_DAYS` | `60` | Lead archive-due threshold |
| `CRM_DEAL_STUCK_DAYS` | `21` | Stalled deal threshold |
| `CRM_FOLLOW_UP_CHECK_IN_DAYS` | `45` | Generated client check-in reminder |
| `CRM_CONTRACT_MAX_KB` | `10240` | Contract upload cap |
| `CRM_CONTRACT_EXPIRING_DAYS` | `30` | Renewal reminder window |
| `CRM_ATTACHMENT_MAX_KB` | `10240` | Attachment upload cap |
| `CRM_ATTACHMENT_MAX_FILES` | `5` | Attachments per message |
| `CRM_SURVEY_INVITATION_DAYS` | `14` | Public survey link lifetime |
| `CRM_SURVEY_ATTENTION_CSAT_MAX` | `3` | Unhappy-answer CSAT threshold |
| `CRM_SURVEY_ATTENTION_NPS_MAX` | `6` | Unhappy-answer NPS threshold |

Demo failure switches, all `false` by default:

```dotenv
CRM_HANDOFF_SIMULATE_FAILURE=false
CRM_EMAIL_SIMULATE_FAILURE=false
CRM_HR_SIMULATE_FAILURE=false
```

`INTEGRATIONS_MODE=mock` appears in the example file but is not read by the current code. The
department integrations are always the `Mock*` drivers bound in `AppServiceProvider`.

### 4.5 Copy-paste backend production template

Replace every `<...>` value. Keep the quotes around values that contain `#`, `&`, or spaces.

```dotenv
APP_NAME="Primepower ISMERS"
APP_ENV=production
APP_KEY=base64:<generated>
APP_DEBUG=false
APP_URL=https://api.example.com

APP_LOCALE=en_PH
APP_FALLBACK_LOCALE=en
APP_FAKER_LOCALE=en_PH

LOG_CHANNEL=stderr
LOG_LEVEL=info

DB_CONNECTION=pgsql
DB_URL="postgresql://user:password@ep-...-pooler.region.aws.neon.tech/primepower?sslmode=require"
DB_SSLMODE=require

# JWT auth (ADR-0008)
JWT_SECRET=<generated>
JWT_TTL=1440
JWT_ALGO=HS256

SESSION_DRIVER=database
SESSION_LIFETIME=120
SESSION_ENCRYPT=false
SESSION_PATH=/
SESSION_DOMAIN=null

BROADCAST_CONNECTION=log
FILESYSTEM_DISK=local
CRM_CONTRACT_DISK=local
CRM_ATTACHMENT_DISK=local
QUEUE_CONNECTION=database
CACHE_STORE=database

MAIL_MAILER=log

# CORS and public survey links
FRONTEND_URL=https://crm.example.com
CRM_SURVEY_URL=https://crm.example.com
INTEGRATIONS_MODE=mock

# Module 07 AI reports. The Groq key lives only in the AI service; this token is shared with it.
AI_REPORT_ENABLED=true
AI_SERVICE_URL=https://ai.example.com
AI_SERVICE_TOKEN=<openssl rand -hex 32>
AI_TIMEOUT_SECONDS=10
AI_RETRY_ATTEMPTS=1
AI_RETRY_DELAY_MS=750
AI_CACHE_TTL_MINUTES=15
AI_REFRESH_COOLDOWN_MINUTES=5
AI_DAILY_LIMIT=800
AI_USER_DAILY_LIMIT=30
```

If you use S3, add the `AWS_*` values and change the three disk settings to `s3`.

## 5. Frontend environment variables

The frontend needs exactly one value:

| Variable | Local | Deployment | Notes |
| --- | --- | --- | --- |
| `VITE_API_URL` | `http://localhost:8000/api/v1` | `https://api.example.com/api/v1` | Embedded at build time |

```dotenv
VITE_API_URL=https://api.example.com/api/v1
```

Important:

- Vite replaces `import.meta.env.VITE_API_URL` during `npm run build`. Changing it on the static
  host after the build does nothing; rebuild and redeploy the frontend.
- Do not add a trailing slash unless your API base URL expects one. Keep the `/api/v1` suffix.
- Use HTTPS for the API when the frontend is served over HTTPS, or the browser blocks it as mixed
  content.
- No secret belongs in this file.

## 6. AI service environment variables

| Variable | Local | Deployment | Notes |
| --- | --- | --- | --- |
| `AI_PROVIDER` | `groq` | `groq` | |
| `AI_MODEL` | `openai/gpt-oss-20b` | `openai/gpt-oss-20b` | Free-tier model |
| `AI_TIMEOUT_SECONDS` | `2.5` | `2.5` | Provider call timeout |
| `AI_SERVICE_TOKEN` | generated | same value as backend | Required bearer secret; fail closed |
| `GROQ_API_KEY` | `gsk_...` | `gsk_...` | Secret; AI service only |
| `GROQ_BASE_URL` | `https://api.groq.com/openai/v1` | same | |

```dotenv
AI_PROVIDER=groq
AI_MODEL=openai/gpt-oss-20b
AI_TIMEOUT_SECONDS=2.5
AI_SERVICE_TOKEN=<openssl rand -hex 32>
GROQ_API_KEY=gsk_...
GROQ_BASE_URL=https://api.groq.com/openai/v1
```

Deployment notes:

- Set these as service environment variables. The service does not need a committed `.env` file.
- The AI service can be public on the platform, but `POST /reports/dashboard` requires
  `Authorization: Bearer <AI_SERVICE_TOKEN>`. `GET /health` stays public for health checks.
- Generate one `AI_SERVICE_TOKEN` per environment with `openssl rand -hex 32`, and set the **same**
  value in the backend and the AI service. Use HTTPS only; the token is a bearer credential.
- The AI service fails closed when `AI_SERVICE_TOKEN` is unset (`503 not_configured`). Laravel
  skips the provider call and returns the rules report when its own token is missing.
- Rotate by deploying the new matching value to both services. In this iteration there is no
  multi-token grace window, so a mismatch is a temporary fallback, not a security hole.
- If the platform provides a `PORT` variable, the included `ai/Dockerfile` honors it and defaults to
  `8001`. A plain Python start command should use
  `uvicorn app.main:app --host 0.0.0.0 --port ${PORT:-8001}`.
- If the AI service is not deployed, set `AI_REPORT_ENABLED=false`. The rules-based reports still
  work.

## 7. Local development

### 7.1 First-time setup

```sh
cp backend/.env.example backend/.env
cp frontend/.env.example frontend/.env
cp ai/.env.example ai/.env

cd backend
composer install
php artisan key:generate
php artisan jwt:secret

cd ../frontend
npm install

cd ../ai
python3 -m venv .venv
.venv/bin/pip install -r requirements-dev.txt
```

Then set the same `AI_SERVICE_TOKEN` in `backend/.env` and `ai/.env`
(`openssl rand -hex 32`), and fill in `ai/.env` with `GROQ_API_KEY` if you want live AI
generation. Without the token, the AI endpoint fails closed and the reports use the rules fallback.

### 7.2 Start and stop

From the repository root:

```sh
./up.sh       # Postgres + API + AI + frontend, all in the background
./status.sh   # health table; exits 0 only if all four are up
./down.sh     # stop everything and remove the DB container (volume is kept)
```

The scripts write logs and PIDs to `.run/`. They do **not** run migrations or seeders.

### 7.3 First-run database

With the database up:

```sh
cd backend
php artisan migrate:fresh --seed
```

This is destructive: it drops all tables and reloads the demo data. Only run it on a fresh local
database, or on a fresh Neon branch/database that has no data worth keeping.

Demo accounts, all with password `password`:

| Role | Email |
| --- | --- |
| Salesrep | `salesrep@primepower.test` |
| Salesrep | `salesrep2@primepower.test` |
| Manager | `manager@primepower.test` |
| Admin | `admin@primepower.test` |
| Superadmin | `superadmin@primepower.test` |

### 7.4 Local URLs

| Service | URL |
| --- | --- |
| Frontend | `http://localhost:5173` |
| API | `http://localhost:8000/api/v1` |
| API health | `http://localhost:8000/api/v1/health` |
| AI health | `http://localhost:8001/health` |

`localhost` and `127.0.0.1` are different browser origins. If you open
`http://127.0.0.1:5173` instead of `http://localhost:5173`, set
`FRONTEND_URL=http://127.0.0.1:5173` and `VITE_API_URL=http://127.0.0.1:8000/api/v1` so CORS
matches exactly.

## 8. Deploying the backend

### 8.1 Runtime requirements

- PHP 8.3 or newer
- Composer 2
- PHP extensions required by Laravel: `ctype`, `curl`, `dom`, `fileinfo`, `filter`, `hash`,
  `mbstring`, `openssl`, `pcre`, `pdo`, `pdo_pgsql`, `session`, `tokenizer`, `xml`
- Writable `backend/storage` and `backend/bootstrap/cache`
- A persistent disk for `backend/storage` if you keep the local disk driver

### 8.2 Build and release

Build:

```sh
cd backend
composer install --no-dev --optimize-autoloader
```

Release (after the runtime environment variables are present):

```sh
php artisan migrate --force
php artisan optimize
```

If the database is Neon, run the migration with the **direct** URL even when the runtime uses the
pooled URL:

```sh
DB_URL="$NEON_DIRECT_URL" php artisan migrate --force
php artisan optimize
```

`php artisan optimize` caches config, events, routes and views. Run it in the **release/start**
phase, not in a build phase where the runtime secrets are missing. After changing any backend env
value, clear or rebuild the cache:

```sh
php artisan optimize:clear
# or
php artisan optimize
```

### 8.3 Start

If your platform provides a PHP-FPM/web-server runtime, point the document root at
`backend/public` and let PHP-FPM serve the app. That is the preferred option.

For a simple demo container or a platform that only supports a start command:

```sh
php artisan serve --host=0.0.0.0 --port=${PORT:-8000}
```

Laravel's built-in server is convenient but not a hardened production web server. It is acceptable
for a low-traffic demo; use PHP-FPM + Nginx/Apache for anything more serious. If you must use the
built-in server with multiple workers, set `PHP_CLI_SERVER_WORKERS` to a small value such as `4`.

### 8.4 Health check

Use:

```text
GET /api/v1/health
```

It returns `200` with `{"status":"ok"}`. Laravel also exposes `/up`.

### 8.5 Storage and permissions

The app writes private files for contracts and attachments, plus logs and framework cache. Ensure
the PHP user can write to:

```text
backend/storage
backend/bootstrap/cache
```

Do not use world-writable permissions. A typical setup is group-writable for the web server user.

### 8.6 Seeding the demo data on the deployed database

On the **first** deployment against an empty Neon database, use the direct Neon URL:

```sh
cd backend
DB_URL="$NEON_DIRECT_URL" php artisan migrate:fresh --seed --force
```

On later deployments, do **not** run the seeders again. The seeders create fixed rows and are not
idempotent. Use:

```sh
DB_URL="$NEON_DIRECT_URL" php artisan migrate --force
```

If your platform supports a release command, make the release command the `migrate --force` form.
Run the one-time `migrate:fresh --seed` manually or as a separate first-deploy step.

## 9. Deploying the frontend

### 9.1 Build

Use Node 20.19+ or 22.12+ (the Vite 8 requirement). Set `VITE_API_URL` in the build environment
first, then:

```sh
cd frontend
npm ci
npm run build
```

Publish `frontend/dist`.

Do not use `npm run dev` or `npm run preview` as the production server. They are development
tools.

### 9.2 SPA fallback

The app uses client-side routes such as `/leads`, `/companies/:id`, `/survey/:token`, `/reports`
and `/dashboard`. The static host must serve `index.html` for unknown paths.

Examples:

**Nginx**

```nginx
location / {
    try_files $uri $uri/ /index.html;
}
```

**Netlify** (`_redirects`)

```text
/* /index.html 200
```

**Vercel / Render / similar**

Configure a rewrite from `/*` to `/index.html` with status `200`.

Without this fallback, opening a deep link or refreshing a page returns a 404.

### 9.3 API URL and CORS

- `VITE_API_URL` must point at the public API origin plus `/api/v1`.
- `FRONTEND_URL` on the backend must match the public frontend origin exactly.
- If the frontend and API share one origin behind a reverse proxy, `VITE_API_URL=/api/v1` also
  works and CORS is not involved. In that case still set `FRONTEND_URL` for survey links and
  defense in depth.

## 10. Deploying the AI service

### 10.1 Build and start

With the included Dockerfile:

```sh
cd ai
docker build -t primepower-ai .
docker run --rm -p 8001:8001 --env-file .env primepower-ai
```

Or with a Python 3.13 runtime (the `ai/Dockerfile` uses 3.13; 3.11+ should also work):

```sh
cd ai
python3 -m venv .venv
.venv/bin/pip install -r requirements.txt
.venv/bin/uvicorn app.main:app --host 0.0.0.0 --port "${PORT:-8001}"
```

Health check:

```text
GET /health
```

Expected: `{"status":"ok"}`.

### 10.2 Connect it to Laravel

Set the backend's `AI_SERVICE_URL` to the AI service's public HTTPS URL, and set the same
`AI_SERVICE_TOKEN` on both services:

```dotenv
# backend
AI_REPORT_ENABLED=true
AI_SERVICE_URL=https://ai.example.com
AI_SERVICE_TOKEN=<same value as the AI service>

# ai/
AI_SERVICE_TOKEN=<same value as the backend>
```

The browser never needs the AI URL and never sees the token. Because the AI service is public, the
token is the access control; do not put it in `VITE_*`, a URL query string, or a log line. The
`/health` endpoint is intentionally anonymous so the platform can probe it.

Test the auth boundary from outside:

```sh
# 401: no bearer token
curl -i -X POST https://ai.example.com/reports/dashboard \
  -H "Content-Type: application/json" \
  -d '{"context":{}}'

# 200: Laravel's bearer token
curl -i -X POST https://ai.example.com/reports/dashboard \
  -H "Authorization: Bearer $AI_SERVICE_TOKEN" \
  -H "Content-Type: application/json" \
  -d @report-context.json
```

Use a real context payload for the second call, or verify the header path from Laravel logs and the
backend test suite.

### 10.3 Failure behavior

If the AI service is down, returns an error, times out, rate-limits, or produces invalid output,
Laravel returns a complete rules-based report with a `fallback` block. The demo still works. This is
expected behavior, not a deployment failure. The UI names the failure:

- `service_unauthorized` — the AI service rejected the shared token.
- `service_unreachable` — the backend could not connect to `AI_SERVICE_URL`.
- `timeout` — the connection or provider timed out.
- `not_configured` — the switch, service token, or Groq key is missing.
- `rate_limited`, `provider_error`, `invalid_response` — the provider answered badly.

Laravel logs the upstream HTTP status and reason as `AI management report upstream failed`; transport
failures log as `AI management report transport failed`. A connection timeout is retried once so a
sleeping public AI service can wake before the report falls back.

## 11. CORS configuration

### 11.1 Current configuration

CORS lives in `backend/config/cors.php`:

```php
'paths' => ['api/*'],
'allowed_methods' => ['*'],
'allowed_origins' => [env('FRONTEND_URL', 'http://localhost:5173')],
'allowed_origins_patterns' => [],
'allowed_headers' => ['*'],
'exposed_headers' => [],
'max_age' => 0,
'supports_credentials' => false,
```

Meaning:

- Only `/api/*` routes are CORS-enabled. The AI service has no CORS configuration because browsers
  do not call it; its report route uses bearer auth instead.
- The backend allows exactly one browser origin: whatever is in `FRONTEND_URL`.
- All methods and request headers are allowed, including `Authorization` and `Content-Type`.
- Cookies/credentials are not used. Authentication is the JWT `Authorization: Bearer ...` header
  stored by the SPA, so `supports_credentials` stays `false` and the frontend must not set
  `withCredentials: true`.

### 11.2 Values to use

| Environment | `FRONTEND_URL` |
| --- | --- |
| Local | `http://localhost:5173` |
| Local via loopback IP | `http://127.0.0.1:5173` |
| Deployed | `https://crm.example.com` |
| Preview/demo host | the exact preview origin, e.g. `https://crm-demo.onrender.com` |

Rules:

- Use the **scheme + host + port** the browser shows in the address bar.
- No trailing slash. `https://crm.example.com/` will not match
  `https://crm.example.com`.
- `http` and `https` are different origins.
- `www.example.com` and `example.com` are different origins.
- `localhost` and `127.0.0.1` are different origins.
- Do not put the API URL in `FRONTEND_URL`. It is the frontend origin.

### 11.3 Multiple origins or preview URLs

The current config accepts one origin. If you need more than one, change
`allowed_origins` in `backend/config/cors.php`. For example, to accept a comma-separated
`CORS_ALLOWED_ORIGINS` and fall back to `FRONTEND_URL`:

```php
'allowed_origins' => array_values(array_filter(array_map(
    'trim',
    explode(',', (string) env('CORS_ALLOWED_ORIGINS', env('FRONTEND_URL', 'http://localhost:5173')))
))),
```

Then set:

```dotenv
FRONTEND_URL=https://crm.example.com
CORS_ALLOWED_ORIGINS=https://crm.example.com,https://crm-demo.onrender.com
```

Keep `FRONTEND_URL` as the canonical frontend origin because survey links use it. For temporary
platform preview domains, prefer a stable custom domain over keeping a broad pattern such as
`*.onrender.com` or `*.vercel.app` in the allow-list.

### 11.4 Test CORS

From any machine:

```sh
curl -i -X OPTIONS https://api.example.com/api/v1/auth/login \
  -H "Origin: https://crm.example.com" \
  -H "Access-Control-Request-Method: POST" \
  -H "Access-Control-Request-Headers: content-type,authorization"
```

A successful preflight includes:

```text
access-control-allow-origin: https://crm.example.com
access-control-allow-methods: ...
access-control-allow-headers: ...
```

If the origin is not allowed, the response will not carry a matching
`access-control-allow-origin`, and the browser blocks the request.

If you changed CORS and the old behavior persists, clear the cached config:

```sh
cd backend
php artisan optimize:clear
# then restart the service or re-run php artisan optimize
```

## 12. Recommended first deployment order

1. **Create the Neon database.** Copy the direct and pooled URLs.
2. **Deploy the AI service** on its public HTTPS URL. Generate `AI_SERVICE_TOKEN`
   (`openssl rand -hex 32`), set it alongside the Groq key, and note the service URL.
3. **Deploy the backend.** Set its env vars, point `DB_URL` at Neon, set `AI_SERVICE_URL` to the AI
   service, set the **same** `AI_SERVICE_TOKEN`, and set `FRONTEND_URL`/`CRM_SURVEY_URL` to the
   eventual frontend origin.
4. **Migrate and seed once** with the direct Neon URL:
   `DB_URL="$NEON_DIRECT_URL" php artisan migrate:fresh --seed --force`.
5. **Deploy the frontend.** Set `VITE_API_URL` to the backend's public API base URL, build, and
   publish.
6. **Verify** the health endpoints, login, dashboard, a survey link, an attachment download and
   one AI report generation. Then confirm the browser's CORS request succeeds.

If the frontend URL is not known until step 5, set the backend's `FRONTEND_URL` afterwards and
restart/clear config. CORS will fail until the backend sees the final origin.

## 13. Verification checklist

- [ ] `GET https://api.example.com/api/v1/health` returns `200` and `{"status":"ok"}`.
- [ ] `GET https://ai.example.com/health` returns `200` without a token (public probe).
- [ ] `POST https://ai.example.com/reports/dashboard` without a bearer token returns `401`.
- [ ] Frontend loads at `https://crm.example.com`.
- [ ] Login works with `admin@primepower.test` / `password` (demo).
- [ ] `/dashboard` and `/leads` load data from the API.
- [ ] A deep link such as `/companies/<id>` survives a browser refresh (SPA fallback).
- [ ] CORS preflight from the frontend origin returns `access-control-allow-origin`.
- [ ] A survey link opens at `https://crm.example.com/survey/<token>` without login.
- [ ] A contract or attachment download opens.
- [ ] `POST /api/v1/reports/management/generate` returns model prose with matching
      `AI_SERVICE_TOKEN` values; with a missing/mismatched token, `AI_REPORT_ENABLED=false`, or an
      AI failure it returns a rules report with `fallback`.
- [ ] Uploaded files survive a redeploy (persistent disk or S3).
- [ ] `APP_DEBUG=false` and the demo passwords are understood to be public.

## 14. Troubleshooting

| Symptom | Likely cause |
| --- | --- |
| API 500 after deploy | Missing `APP_KEY` or `JWT_SECRET`, wrong DB URL, missing `pdo_pgsql`, or unwritable `storage/` |
| Login succeeds but next request is 401 | `JWT_SECRET` changed or the token expired; log in again |
| 401 behind a reverse proxy with a valid token | The proxy is stripping the `Authorization` header; forward it to PHP |
| CORS error in browser | `FRONTEND_URL` does not exactly match the browser origin, or cached config still has the old value |
| Neon connection refused / SSL error | Missing `sslmode=require`, wrong host/port, or a password with unencoded special characters |
| Migration fails on Neon | Using the pooled `-pooler` URL; run migrations with the direct URL |
| Uploads vanish after redeploy | Ephemeral filesystem; mount a persistent disk or switch the disks to S3 |
| Frontend deep link 404 | Missing SPA rewrite to `index.html` |
| Frontend calls the wrong API | `VITE_API_URL` was changed after build; rebuild the frontend |
| AI falls back with `service_unauthorized` | The backend and AI service have different `AI_SERVICE_TOKEN` values (or the platform added quotes/whitespace); set the same 64-character hex value on both |
| AI falls back with `service_unreachable` | `AI_SERVICE_URL` is still `http://127.0.0.1:8001`, missing `https://`, includes `/reports/dashboard`, or the AI service is down; use the public base URL and redeploy the backend |
| AI falls back with `timeout` after a long first request | The public AI service is cold-starting or the provider is slow; the connection timeout is retried once, then falls back |
| AI always falls back | `AI_REPORT_ENABLED=false`, `AI_SERVICE_URL` unreachable, missing/mismatched `AI_SERVICE_TOKEN`, missing Groq key, provider rate limit, or provider timeout |
| AI report endpoint returns 401 | Missing or wrong `Authorization: Bearer …`; set the same `AI_SERVICE_TOKEN` on Laravel and the AI service |
| AI report endpoint returns 503 `not_configured` | `AI_SERVICE_TOKEN` is unset on the AI service (it fails closed), or the Groq key is missing |
| Local UI says “not configured” | `backend/.env` has no `AI_SERVICE_TOKEN`, or `ai/.env` has no `AI_SERVICE_TOKEN`; set the same value in both and restart the AI service |
| Someone can call the AI service | They need `AI_SERVICE_TOKEN`; if it leaks, rotate it on both services. Add platform rate limiting as an extra guard |
| `migrate:fresh --seed` re-run fails | Seeders are not idempotent; only run them on a fresh database |
| Slow first request | Neon compute woke from suspension; later requests are faster |
| Logs are missing on the platform | `LOG_CHANNEL` is still `stack`/`single`; use `stderr` |

## 15. What this deployment does not include

- Real email delivery. Email is mocked through `MockEmailGateway`.
- Real HR, Finance or Service integrations. They are mocked.
- Background workers or a scheduler. Due sends and reminders are triggered on demand through the
  admin endpoints or the Artisan commands.
- Automatic client aging, contract e-signature/versioning, and duplicate detection/merge.
- Advanced AI service hardening. It uses a long-lived shared bearer token over HTTPS; there is no
  mTLS, OAuth, per-IP rate limit or multi-token rotation window in this iteration (ADR-0022).
- Multi-currency or multi-tenant hosting. The data and locale are PH-based (`en_PH`,
  `Asia/Manila`, PHP).
