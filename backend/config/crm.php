<?php

return [

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

];
