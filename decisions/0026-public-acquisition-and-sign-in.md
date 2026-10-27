# 0026 — Public acquisition, modal sign-in & bulk lead intake

- Status: Accepted
- Date: 2026-10-27

## Context

The public site is a near-empty shell with a single “Open dashboard” link, and sign-in is a standalone
page. Lead intake is authenticated/manual only; `spec 01` still lists a public intake endpoint and CSV
mapping as open questions. The business needs a simple, credible public request-staffing surface and a
controlled way to bring existing lead lists into the CRM.

## Decision

1. **The public landing page is refreshed, not rebuilt as a marketing site.** It keeps the existing
   design language and uses:
   - a hero with a hook title, supporting subtitle and a **Request staffing** action;
   - a simple CSS-only animated background that respects `prefers-reduced-motion`;
   - feature cards with lucide icons and short copy;
   - lightweight HTML/CSS product previews (a framed table that reads like a screenshot) instead of
     external image assets;
   - the request-staffing form as an embedded section;
   - a public header/footer with **Sign in** and contact details.

   No new UI library is added. The page uses the existing design tokens and shared components.

2. **Sign-in becomes a modal on the public page.** The standalone login page is removed. The public
   header opens the modal; `/login` redirects to `/?login=1` and protected routes redirect to
   `/?login=1&next=<internal path>`. A 401 also clears the session and opens the modal. After a
   successful login the user returns to `next`, or to `/dashboard`.

   The modal is an accessible dialog: labelled title, initial focus, focus trap, Escape/backdrop
   close, loading state and inline error. Query parameters make the open state linkable and restore
   correctly on refresh.

3. **The public intake endpoint is unauthenticated and throttled.** `POST /api/v1/public/leads`
   accepts company, contact, email/phone, industry, service interest, headcount, roles, target start,
   message and consent. At least one contact channel and consent are required; the submission stamps
   `consent_at`. The lead is created **unassigned** with `source = website`, `source_detail` naming the
   landing form; duplicate matches are advisory only. The response is a friendly thank-you and never
   exposes internal ids or the duplicate detail. A honeypot and rate limit protect the endpoint; the
   public page shows success, validation and failure states.

4. **CSV import is available to every role, with ownership rules.**
   - A salesrep's imported rows are owned by the importer.
   - Manager/admin imports default to the shared unassigned queue; the preview may choose a target
     owner within their scope.
   - Import is preview-then-commit, with a fixed template, per-row validation errors, a 500-row cap,
     and advisory duplicates. No merge is performed.
   - `GET /leads/import/template`, `POST /leads/import/preview` and `POST /leads/import` back the
     `/leads/import` page.

5. **The public form and the authenticated `POST /leads` share validation but not permissions.** The
   public route cannot set an owner, status, score or sensitive field; it is an intake envelope, not
   a general lead API.

## Consequences

- No new table; the public form writes the existing `Lead` record and the import uses the existing
  lead create path.
- The landing page, modal and import page get frontend tests; the public endpoint and import preview/
  commit get feature tests.
- `/login` becomes a redirect; bookmarks and expired-session redirects keep working.
- The public endpoint is outside `auth:api` and must be included in CORS/rate-limit review.
- The landing refresh is intentionally simple; a full brand/marketing redesign remains a later
  decision.
