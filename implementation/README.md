# Implementation Guidelines & System Architecture

This directory holds technical implementation guides, system flows, design instructions, tokens, and domain references for engineering agents working on the CRM subsystem.

## Folder Contents

| Document | Purpose |
| --- | --- |
| `departamental-design-instruction.md` | Official design system rules (layers 1–3, common components, dashboard layouts, accessibility) |
| `design-tokens.md` | Color palette (light & dark mode), typography, domain semantics, and qualification tone tokens |
| `system-tech.md` | Tech stack specifications: Laravel 13 API, React 19 + Vite frontend, local Postgres DB, JWT authentication |
| `system-roles.md` | Core permission and role hierarchy definitions (`superadmin`, `admin`, `manager`, `salesrep`) |
| `system-modules.md` | Subsystem module breakdown across CRM |
| `system-ai-feature.md` | FastAPI AI service technical requirements and integration baseline |
| `crm-flow.md` | Complete CRM system-level flow, object model, and module interaction guide |
| `business-flow.md` | Primepower Manpower Service business context, department stakeholders, and end-to-end delivery flows |
| `list-of-departments.md` | Enterprise transaction cores and department systems inventory |
| `screenshots/` | UI design references and component layout examples |

---

## Instructions for Implementing Agents

1. **Role of Implementing Agents:**
   - Implement features according to slices declared in `../specs/` and architectural decisions in `../decisions/`.
   - Adhere strictly to the design system in `departamental-design-instruction.md` and `design-tokens.md`.
   - Do not invent new tables or endpoints unless explicitly specified in the slice contract.
2. **Planner Agent vs. Implementing Agent:**
   - The **Main Planner** authors specifications in `../specs/`, documents architectural decisions in `../decisions/`, breaks down tasks into reviewable slices, and reviews audit reports in `audit reports/`.
   - **Implementing Agents** write code, add tests, format with `pint` / `prettier`, and verify test suites.
