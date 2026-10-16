# Decisions

This folder records decisions made as development progresses, so the rationale is retained and
agents can pick up context quickly. Read existing entries before proposing changes.

## Convention

- One decision per file, named `NNNN-short-title.md` (e.g. `0001-lead-object-model.md`),
  numbered sequentially. Use `git mv`/increment to keep numbers stable.
- Status is one of: **Proposed**, **Accepted**, **Superseded by NNNN**, **Rejected**.
- Keep entries short: context → decision → consequences. Link to `../resources/*.md` rather than
  duplicating details.
- Supersede, don't delete: mark the old entry and point to the replacement.
- When a decision changes how agents should work, also reflect it in `../AGENTS.md`.

## Template

```md
# NNNN — Title

- Status: Proposed | Accepted
- Date: YYYY-MM-DD

## Context
What problem/choice this addresses.

## Decision
What was decided.

## Consequences
What follows from it (including trade-offs).
```
