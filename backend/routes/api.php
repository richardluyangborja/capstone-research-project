<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\LeadController;
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

    Route::post('/auth/login', [AuthController::class, 'login']);

    Route::middleware('auth:api')->group(function (): void {
        Route::get('/auth/me', [AuthController::class, 'me']);
        Route::post('/auth/logout', [AuthController::class, 'logout']);

        Route::post('leads/{id}/restore', [LeadController::class, 'restore']);
        Route::apiResource('leads', LeadController::class)->only(['index', 'store', 'show', 'destroy']);
    });
});
