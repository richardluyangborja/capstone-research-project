<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\LeadAssignmentController;
use App\Http\Controllers\Api\V1\LeadController;
use App\Http\Controllers\Api\V1\LeadQualificationController;
use App\Http\Controllers\Api\V1\NotificationController;
use App\Http\Controllers\Api\V1\UserController;
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

        // Notifications (submodule 03) — the topbar bell
        Route::get('notifications', [NotificationController::class, 'index']);
        Route::post('notifications/read-all', [NotificationController::class, 'markAllRead']);
        Route::post('notifications/{id}/read', [NotificationController::class, 'markRead']);

        // Assignment targets (submodule 03)
        Route::get('users/assignable', [UserController::class, 'assignable']);

        // Leads — submodules 01–03. Static paths must precede the resource route.
        Route::get('leads/summary', [LeadController::class, 'summary']);
        Route::post('leads/bulk-assign', [LeadAssignmentController::class, 'bulkAssign']);
        Route::post('leads/bulk-transfer', [LeadAssignmentController::class, 'bulkTransfer']);
        Route::post('leads/{id}/restore', [LeadController::class, 'restore']);
        Route::apiResource('leads', LeadController::class)->only(['index', 'store', 'show', 'destroy']);

        // Submodule 02 — Lead Qualification & Scoring
        Route::patch('leads/{lead}/qualification', [LeadQualificationController::class, 'updateQualification']);
        Route::post('leads/{lead}/rate', [LeadQualificationController::class, 'rate']);
        Route::post('leads/{lead}/qualify', [LeadQualificationController::class, 'qualify']);
        Route::post('leads/{lead}/disqualify', [LeadQualificationController::class, 'disqualify']);
        Route::post('leads/{lead}/nurture', [LeadQualificationController::class, 'nurture']);

        // Submodule 03 — Lead Assignment & Ownership
        Route::post('leads/{lead}/assign', [LeadAssignmentController::class, 'assign']);
        Route::post('leads/{lead}/claim', [LeadAssignmentController::class, 'claim']);
        Route::get('leads/{lead}/activities', [LeadAssignmentController::class, 'activities']);
    });
});
