<?php

return [

    /*
    |--------------------------------------------------------------------------
    | AI Management Reports (module 07, spec 15, S2)
    |--------------------------------------------------------------------------
    |
    | The provider key lives only in the `ai/` service. Laravel sees the switch,
    | the service URL, the timeout and the quota controls — never the secret.
    |
    */

    'report' => [
        'enabled' => (bool) env('AI_REPORT_ENABLED', true),
        'service_url' => (string) env('AI_SERVICE_URL', 'http://127.0.0.1:8001'),
        'timeout' => (float) env('AI_TIMEOUT_SECONDS', 3),
        'cache_ttl_minutes' => (int) env('AI_CACHE_TTL_MINUTES', 15),
        'refresh_cooldown_minutes' => (int) env('AI_REFRESH_COOLDOWN_MINUTES', 5),
        'daily_limit' => (int) env('AI_DAILY_LIMIT', 800),
        'user_daily_limit' => (int) env('AI_USER_DAILY_LIMIT', 30),
    ],

];
