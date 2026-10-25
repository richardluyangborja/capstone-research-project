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
| `DEPLOYMENT.md` / `AGENTS.md` | Deployment and agent/contributor guidance |

## Quickstart

One-time install:

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

For live AI reports, set the same `AI_SERVICE_TOKEN` in `backend/.env` and `ai/.env`
(`openssl rand -hex 32`), and add the Groq key to `ai/.env`. Without them the reports still work
through the rules fallback.

Everyday start/stop, from the repository root:

```sh
./up.sh       # Postgres + API + AI + frontend in the background
./status.sh   # health table; exits 0 only when all four are up
./down.sh     # stop everything
```

On a fresh database, seed the demo data once:

```sh
cd backend
php artisan migrate:fresh --seed
```

## Deployment

See [DEPLOYMENT.md](DEPLOYMENT.md) for local vs deployed environment variables, Neon Postgres,
per-app build/start commands, persistent storage and CORS configuration.

## Conventions (see `AGENTS.md`)

- JWT auth (no cookies); `feature/*` branches → `main`; Conventional Commits.
- PH-based: `en-PH`, `Asia/Manila`, single currency **PHP (₱)**.
- Non-sequential **ULIDs** in URLs; never expose auto-incrementing IDs.
- No faker in seeds. Reuse the design system (`DashboardShell` + shared components + tokens).
