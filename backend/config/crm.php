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

];
