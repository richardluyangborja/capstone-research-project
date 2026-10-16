# Primepower — CRM subsystem

Capstone project: the **Customer Relationship Management** subsystem for Primepower Manpower
Service (a manpower/staffing agency). See `resources/` for business context and specs, and
`decisions/` for accepted design decisions.

## Layout

| Path | What |
| --- | --- |
| `backend/` | Laravel API (`/api/v1`), Postgres, JWT (planned) |
| `frontend/` | React + Vite + TypeScript SPA (Tailwind design tokens) |
| `ai/` | Python FastAPI AI/ML service (lightweight) |
| `resources/` | Business context, CRM flow, and per-submodule specs |
| `decisions/` | Architecture decision records (ADRs) |
| `docs`/`AGENTS.md` | Agent/contributor guidance |

## Quickstart

```sh
# 1. Database (Postgres)
docker compose up -d db

# 2. Backend
cd backend
composer install
php artisan migrate
php artisan serve            # http://localhost:8000  (API health: /api/v1/health)

# 3. Frontend
cd ../frontend
npm install
npm run dev                  # http://localhost:5173

# 4. AI service (optional for Phase 1)
cd ../ai
python3 -m venv .venv && source .venv/bin/activate
pip install -r requirements.txt
uvicorn app.main:app --reload --port 8001
```

## Conventions (see `AGENTS.md`)

- JWT auth (no cookies); `feature/*` branches → `main`; Conventional Commits.
- PH-based: `en-PH`, `Asia/Manila`, single currency **PHP (₱)**.
- Non-sequential **ULIDs** in URLs; never expose auto-incrementing IDs.
- No faker in seeds. Reuse the design system (`DashboardShell` + shared components + tokens).
