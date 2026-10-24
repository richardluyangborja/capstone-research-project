# AI/ML service

Lightweight FastAPI service for AI/ML dashboards, management reports, and action suggestions
(`resources/system-ai-feature.md`). Laravel calls it over HTTP for module 07's on-demand management
report; it is never a public surface and holds the provider key.

## Run

```sh
python3 -m venv .venv && source .venv/bin/activate
pip install -r requirements-dev.txt
cp .env.example .env        # then set GROQ_API_KEY
uvicorn app.main:app --reload --port 8001
```

Then `GET http://localhost:8001/health` → `{"status":"ok"}`.

The key belongs only in `ai/.env`. `backend/.env` carries `AI_REPORT_ENABLED`,
`AI_SERVICE_URL`, the timeout, cache/cooldown windows and the daily caps — never the secret.

## Reports

`POST /reports/dashboard` accepts `{"context": {...}}`, where the context is Laravel's aggregated,
PII-free report input (counts, stage labels, KPI values and dates — no names, emails, notes or
message bodies). The service calls Groq's OpenAI-compatible chat endpoint with
`openai/gpt-oss-20b`, validates the JSON shape and the fixed insight/action IDs, and returns prose
plus token usage. Laravel maps those IDs to its own routes.

Every failure is a typed HTTP status Laravel turns into a rules fallback: `503` no key, `504`
timeout, `429` provider rate limit, `502` provider error or unusable response. There is no retry
loop; the Laravel rules report is the fallback.

## Tests

```sh
pytest
```

Tests use `respx` to fake Groq, so no network call and no key are needed. They cover the outgoing
payload (no names, emails, phones or bodies), success, rate limiting, timeouts, missing
configuration and invented IDs.

## Layout

- `app/main.py` — FastAPI entrypoint (`app.main:app`)
- `app/config.py` — settings from `ai/.env`
- `app/schemas.py` — request/response Pydantic contracts
- `app/reports.py` — prompt, Groq call, validation and typed errors
- `requirements.txt` — runtime dependencies (keep lightweight)
- `requirements-dev.txt` — `pytest` + `respx`, nothing heavier
- `Dockerfile` — container image (exposes 8001)
