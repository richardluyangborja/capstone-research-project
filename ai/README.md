# AI/ML service

Lightweight FastAPI service for AI/ML dashboards, management reports, and action suggestions
(`resources/system-ai-feature.md`). Laravel calls it over HTTP locally and HTTPS in deployment for
module 07's on-demand management report. The platform may expose it publicly, so
`/reports/dashboard` requires the shared `AI_SERVICE_TOKEN` bearer credential. The service also
holds the Groq provider key.

## Run

```sh
python3 -m venv .venv && source .venv/bin/activate
pip install -r requirements-dev.txt
cp .env.example .env        # then set AI_SERVICE_TOKEN and GROQ_API_KEY
uvicorn app.main:app --reload --port 8001
```

Then `GET http://localhost:8001/health` → `{"status":"ok"}`.

The Groq key belongs only in `ai/.env`. `AI_SERVICE_TOKEN` must be the same value in `ai/.env` and
`backend/.env`; it is a server-to-server secret. `backend/.env` also carries `AI_REPORT_ENABLED`,
`AI_SERVICE_URL`, the timeout, cache/cooldown windows and the daily caps.

## Reports

`POST /reports/dashboard` accepts `{"context": {...}}`, where the context is Laravel's aggregated,
PII-free report input (counts, stage labels, KPI values and dates — no names, emails, notes or
message bodies). The service calls Groq's OpenAI-compatible chat endpoint with
`openai/gpt-oss-20b`, validates the JSON shape and the fixed insight/action IDs, and returns prose
plus token usage. Laravel maps those IDs to its own routes.

The report route requires `Authorization: Bearer <AI_SERVICE_TOKEN>`. The token is compared in
constant time; missing or wrong tokens are `401`, and an unset service token is `503 not_configured`
(fail closed). `GET /health` stays public for the platform probe.

Every failure is a typed HTTP status Laravel turns into a rules fallback: `503` no token/key, `504`
timeout, `429` provider rate limit, `502` provider error or unusable response. There is no retry
loop; the Laravel rules report is the fallback.

## Tests

```sh
pytest
```

Tests use `respx` to fake Groq, so no network call and no key are needed. They cover the outgoing
payload (no names, emails, phones or bodies), bearer auth (public health, missing/wrong token,
unset token), success, rate limiting, timeouts, missing configuration and invented IDs.

## Layout

- `app/main.py` — FastAPI entrypoint (`app.main:app`)
- `app/config.py` — settings from `ai/.env`
- `app/schemas.py` — request/response Pydantic contracts
- `app/reports.py` — prompt, Groq call, validation and typed errors
- `requirements.txt` — runtime dependencies (keep lightweight)
- `requirements-dev.txt` — `pytest` + `respx`, nothing heavier
- `Dockerfile` — container image (honors `PORT`, defaults to `8001`)
