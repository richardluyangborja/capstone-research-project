# 0016 — Delivery cadence: module specs and delivery slices

- Status: Accepted
- Date: 2026-10-18

## Context

Module 01 was specified as one file **per submodule** (`01`–`09`) and shipped as one pull request per
file — eight submodule PRs plus a design-system PR. ADR-0014 then changed the numbering convention for
later modules: `10+` is **one file per module**, with that module's submodules as sections inside the
file.

That change quietly moved the PR boundary without anyone deciding it. Module 02 followed its spec file:
one file, five internal submodules, **one** PR (#10) covering the board, the deal book, the deal page,
stage moves, the timeline and the forecast. Nothing in the spec index or ADR-0014 says how the five
sections should have been delivered, so the granularity was chosen silently — which is exactly the kind
of thing this folder exists to record.

Two failure modes to avoid: shipping a later module as one undifferentiated PR whose review surface
grows with every section (module 02 was already noticeably larger than any module-01 PR), and the
opposite — inventing submodule boundaries for a module whose work is genuinely one increment.

## Decision

1. **Spec granularity stays as ADR-0014** — one spec file per module for `10+`; submodules are sections
   inside it.
2. **Each module spec gains a "Delivery slices" section** listing its slices in the order they will
   ship, each with its own acceptance criteria. The plan is reviewable *before* any code is written.
3. **A slice is the smallest increment that is demoable end to end** — API, screen, permissions, seeded
   demo data and tests together — and that leaves `main` in a coherent state. A slice is not a layer:
   "the endpoints" and "the screens" are not two slices of the same feature.
4. **A module may legitimately be one slice.** When the entity already exists and the work is one
   workflow, say so in the spec instead of manufacturing boundaries. Module 02 is the recorded example
   (five facets, one slice); module 03 is the counter-example (five slices, ADR-0017).
5. **Delivery:** the planning docs (the module spec + its ADRs) land first — their own PR, or the first
   commit of the chapter; then **one PR per slice**, on `feature/<module>-<slice>`, merged to `main`
   with the branch deleted. Conventional Commits throughout.
6. **No half-slices.** An API without its screen, or a screen backed by a stub, does not merge — except
   when the capability itself is headless, which the spec must state.

## Consequences

- PRs get smaller and each one has a demo script ("open `/communications`, log a call, see it on the
  lead timeline"), so review is bounded by one slice rather than one module.
- The module spec becomes the delivery plan, so a later agent can pick up "module 03, slice 2" without
  re-deriving the order — the same benefit module 01's per-submodule files gave.
- More PRs per module (module 03 is provisionally five), and the sidebar/AGENTS/index bookkeeping has to
  be updated per slice rather than once per module.
- Slices must be declared up front. If a module's true shape only emerges mid-build, the spec is edited
  first and the change is visible, rather than the boundary being decided in a commit.
- `AGENTS.md` mirrors this: the delivery unit is the slice, and each slice updates the module bookkeeping
  as it lands.
