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
        // Shared bearer token for the public AI service. The same value lives in ai/.env.
        'service_token' => (string) env('AI_SERVICE_TOKEN', ''),
        // A public AI service may need a cold start before the provider call begins. The
        // provider itself is still bounded by the AI service's 2.5s timeout.
        'timeout' => (float) env('AI_TIMEOUT_SECONDS', 10),
        'retry_attempts' => (int) env('AI_RETRY_ATTEMPTS', 1),
        'retry_delay_ms' => (int) env('AI_RETRY_DELAY_MS', 750),
        'cache_ttl_minutes' => (int) env('AI_CACHE_TTL_MINUTES', 15),
        'refresh_cooldown_minutes' => (int) env('AI_REFRESH_COOLDOWN_MINUTES', 5),
        'daily_limit' => (int) env('AI_DAILY_LIMIT', 800),
        'user_daily_limit' => (int) env('AI_USER_DAILY_LIMIT', 30),
    ],

];
