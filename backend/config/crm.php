<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Business calendar (ADR-0007)
    |--------------------------------------------------------------------------
    |
    | Timestamps are stored in UTC and read back in the PH working day the business runs on.
    | Anything that has to ask "is that today?" or render a date the way a person would —
    | template merge fields (spec 11 §S5) and follow-up due state (spec 12) — resolves the
    | day through this one timezone rather than assuming UTC.
    |
    */

    'timezone' => env('CRM_TIMEZONE', 'Asia/Manila'),

    /*
    |--------------------------------------------------------------------------
    | Lead aging thresholds (spec 04 §3)
    |--------------------------------------------------------------------------
    |
    | Days without activity before a lead is considered stale, at risk, or a
    | candidate for archiving. Computed on read, so they can be tuned per
    | environment without a migration. Override with the CRM_* env vars.
    |
    */

    'aging' => [
        'stale_days' => (int) env('CRM_STALE_DAYS', 14),
        'at_risk_days' => (int) env('CRM_AT_RISK_DAYS', 30),
        'archive_days' => (int) env('CRM_ARCHIVE_DAYS', 60),
    ],

    /*
    |--------------------------------------------------------------------------
    | Deal pipeline (module 02)
    |--------------------------------------------------------------------------
    |
    | Days without a touch before an open deal is flagged as stuck on the board. Computed
    | on read only — nothing is scheduled (automatic effects belong to the follow-up module).
    |
    */

    'pipeline' => [
        'stuck_days' => (int) env('CRM_DEAL_STUCK_DAYS', 21),
    ],

    /*
    |--------------------------------------------------------------------------
    | Department integrations (spec 09, ADR-0005)
    |--------------------------------------------------------------------------
    |
    | Other departments are mocked. `simulate_failure` forces the Service handoff to fail
    | so the retry path can be demoed and tested deterministically.
    |
    */

    'integrations' => [
        'service_handoff' => [
            'simulate_failure' => (bool) env('CRM_HANDOFF_SIMULATE_FAILURE', false),
        ],
        'email' => [
            'simulate_failure' => (bool) env('CRM_EMAIL_SIMULATE_FAILURE', false),
        ],
        'hr' => [
            'simulate_failure' => (bool) env('CRM_HR_SIMULATE_FAILURE', false),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Contracts (spec 09)
    |--------------------------------------------------------------------------
    |
    | Uploaded documents live on a private disk and are streamed through an authorized
    | endpoint. `expiring_soon_days` drives the renewal/expiry countdown in the UI.
    |
    */

    'contracts' => [
        'disk' => env('CRM_CONTRACT_DISK', 'local'),
        'max_kb' => (int) env('CRM_CONTRACT_MAX_KB', 10240),
        'expiring_soon_days' => (int) env('CRM_CONTRACT_EXPIRING_DAYS', 30),
    ],

    /*
    |--------------------------------------------------------------------------
    | Attachments (module 03 slice S3, spec 11)
    |--------------------------------------------------------------------------
    |
    | Files on a record, capped per record and served from a private disk through an
    | authorized endpoint — the same allow-list and size ceiling as a contract document.
    |
    */

    'attachments' => [
        'disk' => env('CRM_ATTACHMENT_DISK', 'local'),
        'max_kb' => (int) env('CRM_ATTACHMENT_MAX_KB', 10240),
        'max_files' => (int) env('CRM_ATTACHMENT_MAX_FILES', 5),
    ],

    /*
    |--------------------------------------------------------------------------
    | Templates (module 03 slice S5, spec 11)
    |--------------------------------------------------------------------------
    |
    | Reusable wording for the composer. Merge fields are rendered server-side; `today`
    | resolves in the business calendar above.
    |
    */

    /*
    |--------------------------------------------------------------------------
    | Follow-up reminders (module 04 slice S4, spec 12)
    |--------------------------------------------------------------------------
    |
    | The generated reminders read the thresholds around them: a signed contract inside
    | `contracts.expiring_soon_days` gets a renewal task, a lead past `aging.stale_days` gets a
    | nudge, and a client nobody has contacted for `check_in_days` gets a check-in. Raised on
    | demand by `POST /follow-ups/generate` — nothing runs on a timer in this phase.
    |
    */

    'follow_ups' => [
        'check_in_days' => (int) env('CRM_FOLLOW_UP_CHECK_IN_DAYS', 45),
    ],

    /*
    |--------------------------------------------------------------------------
    | Client satisfaction & survey (module 05, spec 13, ADR-0019)
    |--------------------------------------------------------------------------
    |
    | An invitation carries a tokenized public link and expires after `invitation_days`. The
    | link is built from `public_url` — the **frontend** origin, since that is the page the
    | client opens — never from APP_URL and never hardcoded: local runs on localhost:5173,
    | production on the real CRM host, and only this value changes.
    |
    | `attention_*` is the closed-loop threshold (slice S4): a response at or below either
    | bound is somebody's job to call back.
    |
    */

    'surveys' => [
        'public_url' => env('CRM_SURVEY_URL', env('FRONTEND_URL', 'http://localhost:5173')),
        'invitation_days' => (int) env('CRM_SURVEY_INVITATION_DAYS', 14),
        'attention_csat_max' => (float) env('CRM_SURVEY_ATTENTION_CSAT_MAX', 3),
        'attention_nps_max' => (int) env('CRM_SURVEY_ATTENTION_NPS_MAX', 6),
    ],

];
