<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| CRM API (v1)
|--------------------------------------------------------------------------
| Versioned REST API (ADR-0007). Resource routes are added per submodule;
| see resources/specs/ for the endpoint contracts.
*/

Route::prefix('v1')->group(function (): void {
    Route::get('/health', fn () => response()->json(['status' => 'ok']));
});
