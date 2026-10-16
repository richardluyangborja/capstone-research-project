# AI/ML service

Lightweight FastAPI service for AI/ML dashboards, management reports, and action suggestions
(`resources/system-ai-feature.md`). The backend calls it over HTTP; it is **not** part of the CRM
request path yet (Phase 1 is the tracking loop).

## Run

```sh
python3 -m venv .venv && source .venv/bin/activate
pip install -r requirements.txt
uvicorn app.main:app --reload --port 8001
```

Then `GET http://localhost:8001/health` → `{"status":"ok"}`.

## Layout

- `app/main.py` — FastAPI entrypoint (`app.main:app`)
- `requirements.txt` — runtime dependencies (keep lightweight)
- `Dockerfile` — container image (exposes 8001)
