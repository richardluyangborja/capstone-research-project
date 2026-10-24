# 15 — AI Management Reports

Module 07 (ADR-0014, **ADR-0021**). A read-only presentation module over modules 01–06. Reuses
`DashboardSummary`, `DashboardWorklist`, `DashboardStaffPerformance` and the existing `ai/` FastAPI
service; adds **no table**.

> **Status: S1 + S2 built.** D3 of spec `14` is moved here as Module 07 (ADR-0021). The
> rules-based report remains the source of truth; the free Groq model is an optional prose layer with
> an explicit fallback. Nothing in this module mutates CRM data.

## Purpose

The dashboard answers “what is happening?”. This module answers “what does it mean, and what should
I read next?” in a dedicated management-report page, in the house's voice, without forcing a manager
to assemble the story from eight KPI cards.

The module is deliberately narrow:

- **Three report variants** — Executive, Pipeline, Client Health.
- **On-demand generation** — the standard rules report is instant; the AI version is requested.
- **Cost-free** — Groq's free API is the only provider, with the existing Laravel rules report as
  the fallback.
- **Private by construction** — the model receives aggregated numbers only. No account names,
  contact names, emails, notes or message bodies.
- **Advisory only** — a report links to the page that owns the work; it never changes a record.

The module is the home for later AI submodules (record catch-up summaries, message drafting), but
those are **not** part of S1/S2.

## Boundary

| In scope                                | Out of scope                                      |
| --------------------------------------- | ------------------------------------------------- |
| `/reports` page and its sidebar section | Record catch-up summaries                         |
| Three report variants                   | AI email / message drafting                       |
| Rules reports for every role            | Natural-language CRM search / chat                |
| On-demand Groq generation               | Survey text sentiment analysis                    |
| Cache, rate limits and fallback alert   | Forecasting ML, training or fine-tuning           |
| Provider seam under `ai/`               | Embeddings, RAG or a vector database              |
| Aggregated, PII-free context            | Exports, scheduled/emailed reports, saved layouts |

## Stack and tooling

| Layer         | Choice                                                  | Why                                                                                        |
| ------------- | ------------------------------------------------------- | ------------------------------------------------------------------------------------------ |
| Laravel       | Built-in `Http` + `Cache` counters                       | No new package; the existing `cache` table holds the report cache, counters and cooldowns. |
| `ai/`         | FastAPI + Pydantic v2 + `pydantic-settings`             | Already scaffolded; typed request/response validation with no ML stack.                    |
| Provider call | `httpx` to Groq's OpenAI-compatible `/chat/completions` | One small HTTP dependency; no vendor SDK lock-in.                                          |
| Provider      | Groq free tier, `openai/gpt-oss-20b`                    | No card, fast, JSON mode, sufficient free quota with caching.                              |
| `ai/` dev     | `pytest` + `respx` (and `ruff` if linting is added)     | Unit-test the provider and schema without a real key.                                      |
| Frontend      | Existing React 19 + Vite + Tailwind + `lucide-react`    | Reuse `DashboardShell` and the common components; no new UI dependency.                    |

Explicitly **not** used: LangChain, LlamaIndex, OpenAI SDK, Transformers/PyTorch, embeddings, FAISS,
pgvector, a vector database, a queue worker or a cron scheduler. The context is structured numbers,
not documents; adding a framework would increase weight without improving the report.

## Delivery slices

The module declares **two slices** (ADR-0016 §2). Each is demoable end to end and leaves `main`
coherent.

| Slice  | Branch                     | Facet                                  | Demo                                                                                                                                                                                  |
| ------ | -------------------------- | -------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| **S1** | `feature/ai-reports-shell` | The module, page and rules variants **(built)** | open `/reports` as each role → switch Executive / Pipeline / Client Health → switch periods → the standard report changes scope and numbers                                           |
| **S2** | `feature/ai-reports-groq`  | Free AI generation, cache and fallback **(built)** | press **Generate with AI** → a Groq report appears with its model/timestamp; remove the key or force a rate limit → the standard report stays and an info alert explains the fallback |

S1 is useful on its own: if the provider decision changes, the module and its reports still ship.
S2 adds the model behind the same report contract.

Future slices, **not built here**:

- `S3` — record catch-up summaries (lead/account/deal timeline).
- `S4` — message drafting in the communication composer.

## Data contract

### Report context (Laravel → Python)

Laravel builds the context from the caller's already-scoped dashboard. The frontend never sends
numbers, filters or record data to the AI endpoint.

```jsonc
{
  "variant": "executive",
  "period": { "range": "30d", "start": "2026-09-24", "end": "2026-10-24" },
  "scope": { "tier": "team", "label": "Business Development team" },
  "kpis": [
    {
      "id": "new-leads",
      "value": 3,
      "unit": "count",
      "trend": { "direction": "up", "value": 50, "unit": "percent" },
    },
    {
      "id": "pipeline",
      "value": 5050000,
      "unit": "currency",
      "trend": { "direction": "flat", "value": 0, "unit": "percent" },
    },
  ],
  "line": {
    "labels": ["22 Sep", "…"],
    "series": [{ "name": "New leads", "values": [2, 1, 0, 0, 0] }],
  },
  "pipeline": {
    "open_value": 5050000,
    "stages": [{ "label": "Negotiation", "value": 1150000 }],
    "closed": { "won": 1, "lost": 2 },
    "win_rate": 33.3,
    "stalled": 2,
  },
  "clients": { "active": 3, "at_risk": 1, "dormant": 0, "churned": 1 },
  "satisfaction": {
    "csat": 3.5,
    "nps": 0,
    "responses": 2,
    "attention": 1,
    "bands": { "promoter": 0, "passive": 1, "detractor": 1 },
  },
  "follow_ups": {
    "open": 10,
    "overdue": 4,
    "due_today": 1,
    "by_kind": { "task": 8, "message": 2 },
  },
  "worklist": {
    "total": 14,
    "by_severity": { "danger": 3, "warning": 6, "info": 5 },
  },
}
```

Rules:

1. **No personal data.** No account names, contact names, emails, phone numbers, notes, message
   subjects/bodies, staff names or record IDs. The report speaks in counts, stage labels and periods;
   its links take the reader to the records.
2. **No client-supplied context.** Laravel builds and validates the object; the frontend only sends
   `variant`, `range` and `refresh`.
3. **Bounded size.** Top five pipeline stages, the five line-chart points per series, and worklist
   counts only. The provider request should stay around 1–2K input tokens.
4. **The report is advisory.** It carries no action endpoint and no record mutation.

### Report response (Laravel → frontend)

The rules report and the model report use the same shape, so the page never branches on provenance.

```jsonc
{
  "data": {
    "variant": "executive",
    "title": "Executive management report",
    "headline": "The team is at a 33% win rate with 10 follow-ups owed.",
    "narrative": "…",
    "insights": [
      {
        "id": "attention",
        "title": "The unhappy queue",
        "body": "…",
        "domain": "people",
        "href": "/satisfaction/responses?attention=true",
        "link_label": "Read the answers",
      },
    ],
    "actions": [
      {
        "id": "call_back",
        "label": "Call back unhappy clients",
        "description": "…",
        "href": "/satisfaction/responses?attention=true",
      },
    ],
    "generated_at": "2026-10-24T05:00:00Z",
    "source": "rules",
    "model": "Primepower rules v1",
    "cached": false,
    "fallback": null,
  },
  "meta": {
    "range": "30d",
    "scope": { "tier": "team", "label": "Business Development team" },
    "ai_available": true,
    "variants": ["executive", "pipeline", "client_health"],
  },
}
```

`source` is `model` or `rules`. A fallback carries:

```jsonc
"fallback": {
  "reason": "rate_limited",
  "message": "AI report is unavailable right now — showing the standard management report."
}
```

Reasons: `not_configured`, `rate_limited`, `timeout`, `provider_error`, `invalid_response`. The UI
shows the friendly `message`; details stay in the server log.

### Prompt contract

The prompt is a **fixed system instruction + one compact JSON user message**, built by the Python
service from Laravel's context. It is not a chat, has no conversation history and has no tools.

- **System prompt:** “You are the reporting analyst for a Philippine staffing CRM. Use only the
  supplied numbers. Write in the house's concise, factual voice. Do not invent names, records,
  numbers or URLs. Return only the JSON object defined below.”
- **Variant block:** the three report questions, the allowed insight/action IDs and the length caps.
- **User message:** the context JSON from the previous section, nothing else. No free-text note, no
  record row, no email body.
- **Schema:** `headline` (≤90 chars), `narrative` (≤700 chars), exactly 3 `insights`
  (`id`, `title` ≤60, `body` ≤220) and exactly 4 `actions` (`id`, `label` ≤50, `description` ≤140).
- **Allowed IDs:** a fixed set per variant, e.g. `attention`, `pipeline`, `follow_ups`, `win_rate`,
  `stages`, `stalled`, `csat`, `nps`, `churn`, and the action IDs `open_pipeline`, `open_board`,
  `open_deals`, `open_clients`, `open_satisfaction`, `open_worklist`, `read_answers`,
  `open_followups`. The Python service rejects anything outside the set; Laravel maps each ID to a
  route it owns.
- **Generation settings:** JSON mode, `temperature = 0.2`, `max_tokens = 1200` (the gpt-oss
  reasoning tokens count against this), `reasoning_effort = low`, no streaming, no retry, no separate
  chain-of-thought request. A schema failure is a fallback, not a repair loop.
- **Privacy check:** the prompt builder has a deny-list test for names, emails, phone numbers, notes
  and message bodies. The test fails the build if any appear in the outgoing payload.

The system prompt is static per variant, which lets the provider's prompt cache work and keeps the
request small. Only the variant block changes the instructions; the context carries the numbers.

## Report variants

All three are available to every role and are scoped by the caller, exactly like the dashboard.

| Variant           | Question it answers                          | Main blocks                                                                     |
| ----------------- | -------------------------------------------- | ------------------------------------------------------------------------------- |
| **Executive**     | “What is the state of the book this period?” | KPIs, revenue won/open pipeline, attention, follow-ups, next actions            |
| **Pipeline**      | “Where is the value, and what is stuck?”     | open value by stage, closed won/lost, win rate, stalled deals, board next steps |
| **Client Health** | “Which clients need care?”                   | active/at-risk/churned, CSAT/NPS, attention queue, follow-up load               |

The `Team Performance` variant is deliberately deferred: it mixes CRM output with core2 HR data and
needs its own sensitivity rules. It is not a fourth tab in S1/S2.

## API endpoints

Base `/api/v1`; JSON; `Authorization: Bearer <jwt>`. No public route.

| Method | Path                                              | Slice | Description                                                                                                                 |
| ------ | ------------------------------------------------- | ----- | --------------------------------------------------------------------------------------------------------------------------- |
| `GET`  | `/reports/management?variant=executive&range=30d` | S1    | The current report. Returns the cached AI report when one exists; otherwise the rules report. **Never calls the provider.** |
| `POST` | `/reports/management/generate`                    | S2    | Generate (or refresh) one variant through the Python/Groq seam. Body: `{ variant, range, refresh? }`.                       |

- `GET` validates `variant` and `range` and returns the `meta` block (scope, `ai_available`, available
  variants). It is the page-load call. `ai_available` reflects the server switch
  (`AI_REPORT_ENABLED`), not a live provider probe; a missing key or provider failure is discovered
  on generate and becomes a fallback.
- `POST` returns **200** for a model report, a cached report and a rules fallback. A provider failure
  is a delivery outcome, not an HTTP error — the reader still gets a complete report. Invalid
  `variant`/`range` is 422.
- `refresh: true` bypasses the cache. A second refresh inside the cooldown returns 429 with
  `retry_after_seconds`; the frontend shows “Try again in a few minutes.”
- The endpoints accept no scope, record IDs or context. The server derives all of it from the JWT.
- The Python service exposes `POST /reports/dashboard` and is called only by Laravel. It is not a
  public API and is not reachable from the frontend.

## Business rules

1. **The module owns no table.** Reports are derived, cached and regenerated. There is no saved-report
   table, no version history and no per-user layout. The cache is the only persistence.
2. **The rules report is the contract.** S1 must produce all three variants without any external
   call. S2 changes the prose path, not the page or the shape. If the model is disabled, the product
   still works.
3. **Generation is on demand.** A dashboard view does not call the provider. The `/reports` page
   shows the standard report immediately; the user asks for AI. This keeps the free tier comfortable
   and makes the fallback ordinary rather than exceptional.
4. **Cache before spend.** A successful model report is cached by user + range + variant + a hash of
   the context. A context change invalidates it; a repeated page load costs zero tokens. TTL defaults
   to 15 minutes.
5. **One provider, one fallback.** Groq is the only external provider in S2. If it is disabled,
   missing a key, rate-limited, slow, down or returns invalid JSON, Laravel serves the rules report.
   There is no Gemini/OpenRouter chain in S2; a second provider would add another privacy and quota
   surface for little benefit.
6. **The model writes prose, not routes.** It chooses **insight and action IDs** from a fixed set.
   Laravel maps those IDs to real routes and rejects prose containing a URL or email address. The
   prompt forbids names, records and numbers outside the supplied context; because that context is
   aggregated counts only, there is no record data for the model to leak.
7. **The fallback is visible.** When `source = rules` and a fallback reason exists, the page shows an
   info alert: “AI report is unavailable right now — showing the standard management report.” This
   is a degraded-state message, not a simulation badge. `not_configured` says AI generation is not
   configured; the standard report still renders.
8. **Role scope is inherited, never supplied.** Salesrep → personal, manager → team, admin/superadmin
   → org. The report uses the same `DashboardScope`, `DashboardPeriod` and `visibleTo` paths as the
   dashboard. The cache key includes the user, so one caller's report cannot appear for another.
9. **The report is advisory.** It does not send email, create tasks, change stages or resolve
   responses. Every action is a link to the page that owns that action.
10. **Locale holds.** Money is PHP, dates are `DD MMM YYYY` in `Asia/Manila`, and the report uses the
    same scope/period labels as the dashboard.

## Free-tier and token controls

| Control            | Default                         | Purpose                                               |
| ------------------ | ------------------------------- | ----------------------------------------------------- |
| Provider           | Groq (`openai/gpt-oss-20b`)     | Free tier, no card; model configurable                |
| Timeout            | 3 s at Laravel, 2.5 s at Python | Keep the page responsive; fall back on timeout        |
| Input              | ≤ ~2K tokens                    | Aggregated context only                               |
| Output             | ≤ 1,200 tokens                  | Headline, narrative, 3 insights, 4 actions; includes gpt-oss reasoning tokens |
| Cache TTL          | 15 min                          | A reload normally costs zero tokens                   |
| Refresh cooldown   | 5 min per user + variant        | Prevent looped regeneration                           |
| Per-user daily cap | 30 generations                  | Protect the shared free quota                         |
| Org daily cap      | 800 generations                 | Below Groq's current 1,000/day free limit             |
| Retries            | none on 429/timeout             | A retry burns quota; fall back instead                |
| Logging            | metadata only                   | Variant, source, model, latency, token counts, reason |

The Groq key lives in the `ai/` service environment, not the browser and not the frontend bundle.
Set the provider's data controls to zero retention if available. Free-tier limits can change; the
caps are configuration, not promises.

## UI

- **Sidebar:** a new **AI Reports** group with **Management Reports** → `/reports`. Later submodules
  join the same group when they are built; no dead links.
- **`/reports`:** the role scope and period toggle from the dashboard, three variant tabs, and one
  report card:
  - headline, narrative, three insight cards and four action links;
  - a **Generate with AI** button when `ai_available` and no cached model report;
  - **Refresh AI report** when a cached model report is shown;
  - a **Standard report** / **AI-generated** provenance line, the model name and the timestamp;
  - an inline info alert when the response carries a fallback;
  - loading, empty, error/retry and “AI generation is not configured” states.
- **Dashboard:** the existing AI card stays as the standard summary. It gains **Open management
  reports →** and does not call the provider. This keeps the dashboard fast and avoids double spend.
- **Design system:** reuse `PageHeader`, `FormCard`, `StatCard`, `AlertItem`, `EmptyState`, tokens
  and lucide icons. No chart library, no hardcoded colours or arbitrary pixels.

## Permissions

| Action                            | salesrep       | manager        | admin / superadmin |
| --------------------------------- | -------------- | -------------- | ------------------ |
| Open `/reports`, read any variant | Own book       | Team           | Org                |
| Generate an AI report             | Own book       | Team           | Org                |
| See a cached report               | Own cache only | Own cache only | Own cache only     |
| Team performance variant          | —              | —              | — _(deferred)_     |

There is no separate report policy: the module is an authenticated read over data the caller may
already see. The only write-like action is generation, which is rate-limited, not permission-gated
beyond login and scope.

## Configuration

| Variable                      | Default                 | Where      |
| ----------------------------- | ----------------------- | ---------- |
| `AI_REPORT_ENABLED`           | `true`                  | Laravel    |
| `AI_SERVICE_URL`              | `http://127.0.0.1:8001` | Laravel    |
| `AI_TIMEOUT_SECONDS`          | `3`                     | Laravel    |
| `AI_CACHE_TTL_MINUTES`        | `15`                    | Laravel    |
| `AI_REFRESH_COOLDOWN_MINUTES` | `5`                     | Laravel    |
| `AI_DAILY_LIMIT`              | `800`                   | Laravel    |
| `AI_USER_DAILY_LIMIT`         | `30`                    | Laravel    |
| `AI_PROVIDER`                 | `groq`                  | `ai/`      |
| `AI_MODEL`                    | `openai/gpt-oss-20b`    | `ai/`      |
| `AI_TIMEOUT_SECONDS`          | `2.5`                   | `ai/`      |
| `GROQ_BASE_URL`               | `https://api.groq.com/openai/v1` | `ai/` |
| `GROQ_API_KEY`                | _(required for S2)_     | `ai/` only |

`AI_REPORT_ENABLED=false` is the demo switch for the fallback path. It must not be necessary to
delete a key.

## Acceptance criteria

### S1 — module shell and rules variants

- [x] `/reports` renders inside `DashboardShell`, with a sidebar **AI Reports** group and a
      `Management Reports` link; no new layout and no dead submodule links.
- [x] Executive, Pipeline and Client Health tabs all render a complete standard report from the
      caller's scoped data; the period toggle changes the numbers.
- [x] A salesrep sees their book, a manager the team, an admin the org; no scope is accepted from the
      client.
- [x] `GET /reports/management` returns the rules report and `meta`, and never calls the Python
      service or Groq.
- [x] The report has headline, narrative, three insights, four actions and only existing routes.
- [x] The dashboard AI card links to `/reports` and still works.
- [x] Backend tests cover the three roles, the three variants, the three ranges, the shape and the
      absence of provider calls; frontend tests cover tabs, period, loading and fallback states.
- [x] `npm run lint`, `npm run test`, `npm run build` and the backend suite are clean.

### S2 — Groq generation and fallback

- [x] `POST /reports/management/generate` calls the `ai/` service, which calls Groq with a compact,
      PII-free context and returns strict JSON.
- [x] A successful report is cached by user + range + variant + context hash; a reload does not call
      the provider; `refresh` bypasses the cache within the cooldown rule.
- [x] The report names its `source` and `model`; a cached report carries `cached: true`.
- [x] Timeout, 429, missing key, invalid JSON and provider failure all return a complete rules report
      with the correct `fallback.reason` and the UI info alert.
- [x] The model cannot emit a route; invalid IDs, schema or prose URLs/emails fall back. The prompt
      restricts it to the supplied context, and no record fields are sent.
- [x] Per-user and org daily caps, and the refresh cooldown, are enforced with cache counters; no new
      table.
- [x] A test asserts the outgoing context contains no account names, contact names, emails, notes or
      message bodies.
- [x] The same five demo roles and three ranges are smoke-tested on Postgres, with and without a
      provider key.

### D3 mapping

Spec `14`'s former D3 is now **Module 07 S1 + S2**. The dashboard AI card remains the rules summary;
the new module owns the page, the variants and the provider seam.

## Impact on other specs

- `14` D1 + D2 remain the dashboard read layer. Its D3 section becomes a pointer to this spec; the
  dashboard response's `ai` block remains the standard summary and does not call the provider.
- `ai/` gains `POST /reports/dashboard`, the provider seam and its tests. It stays FastAPI +
  `httpx` + Pydantic; no ML stack, no vector store.
- No table, no migration and no change to modules 01–05.
- `AGENTS.md`, `crm-flow.md`, `specs/README.md` and ADR-0021 record Module 07 and the D3 move.
- A future S3 (record summaries) and S4 (message drafting) reuse the same provider seam and the same
  PII rules; neither is implied by S1/S2.
