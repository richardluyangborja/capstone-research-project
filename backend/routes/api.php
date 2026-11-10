<?php

use App\Http\Controllers\Api\V1\AdminAccessController;
use App\Http\Controllers\Api\V1\AdminAuditLogController;
use App\Http\Controllers\Api\V1\AdminMaintenanceController;
use App\Http\Controllers\Api\V1\AdminOperationsController;
use App\Http\Controllers\Api\V1\AdminSettingController;
use App\Http\Controllers\Api\V1\AdminTeamController;
use App\Http\Controllers\Api\V1\AdminUserController;
use App\Http\Controllers\Api\V1\AttachmentController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\BillingBookController;
use App\Http\Controllers\Api\V1\CommunicationController;
use App\Http\Controllers\Api\V1\CommunicationTemplateController;
use App\Http\Controllers\Api\V1\CompanyActivityController;
use App\Http\Controllers\Api\V1\CompanyAssignmentController;
use App\Http\Controllers\Api\V1\CompanyBillingController;
use App\Http\Controllers\Api\V1\CompanyContactController;
use App\Http\Controllers\Api\V1\CompanyContractController;
use App\Http\Controllers\Api\V1\CompanyController;
use App\Http\Controllers\Api\V1\CompanyDeliveryController;
use App\Http\Controllers\Api\V1\CompanyLifecycleController;
use App\Http\Controllers\Api\V1\CompanyOpportunityController;
use App\Http\Controllers\Api\V1\CompanySiteController;
use App\Http\Controllers\Api\V1\ContactController;
use App\Http\Controllers\Api\V1\ContractController;
use App\Http\Controllers\Api\V1\ContractRateCardController;
use App\Http\Controllers\Api\V1\DashboardController;
use App\Http\Controllers\Api\V1\DeploymentBookController;
use App\Http\Controllers\Api\V1\FollowUpController;
use App\Http\Controllers\Api\V1\LeadAssignmentController;
use App\Http\Controllers\Api\V1\LeadController;
use App\Http\Controllers\Api\V1\LeadConversionController;
use App\Http\Controllers\Api\V1\LeadImportController;
use App\Http\Controllers\Api\V1\LeadLifecycleController;
use App\Http\Controllers\Api\V1\LeadQualificationController;
use App\Http\Controllers\Api\V1\ManagementReportController;
use App\Http\Controllers\Api\V1\NotificationController;
use App\Http\Controllers\Api\V1\OpportunityActivityController;
use App\Http\Controllers\Api\V1\OpportunityController;
use App\Http\Controllers\Api\V1\OpportunityStageController;
use App\Http\Controllers\Api\V1\PipelineController;
use App\Http\Controllers\Api\V1\PublicLeadController;
use App\Http\Controllers\Api\V1\PublicSurveyController;
use App\Http\Controllers\Api\V1\SatisfactionController;
use App\Http\Controllers\Api\V1\SurveyController;
use App\Http\Controllers\Api\V1\SurveyInvitationController;
use App\Http\Controllers\Api\V1\SurveyResponseController;
use App\Http\Controllers\Api\V1\TaskController;
use App\Http\Controllers\Api\V1\UserController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| CRM API (v1)
|--------------------------------------------------------------------------
| Versioned REST API (ADR-0007). Resource routes are added per submodule;
| see specs/ for the endpoint contracts.
*/

Route::prefix('v1')->group(function (): void {
    Route::get('/health', fn () => response()->json(['status' => 'ok']));

    Route::post('/auth/login', [AuthController::class, 'login']);

    // Module 05 slice S2 — the client's page. The token in the link is the credential, so these
    // two live outside the JWT group and are throttled: an unknown token is 404, a dead one 410.
    Route::get('public/surveys/{token}', [PublicSurveyController::class, 'show'])->middleware('throttle:30,1');
    Route::post('public/surveys/{token}/responses', [PublicSurveyController::class, 'submit'])->middleware('throttle:10,1');

    // Module 01 refresh S17 — the landing page's request-staffing form. Unauthenticated and
    // narrow: a website lead, owned by nobody, with consent recorded and no internal detail back.
    Route::post('public/leads', [PublicLeadController::class, 'store'])->middleware('throttle:5,1');

    Route::middleware(['auth:api', 'active.user'])->group(function (): void {
        Route::get('/auth/me', [AuthController::class, 'me']);
        Route::post('/auth/logout', [AuthController::class, 'logout']);

        // Module 08 S1 — the administration shell's capability probe (spec 19, ADR-0030).
        // The route is guarded by `AdminAccessPolicy`; non-admins get the standard 403 shape.
        Route::get('admin/access', [AdminAccessController::class, 'show']);

        // Module 08 slice S2 — the admin user directory and account lifecycle (spec 19,
        // ADR-0030). The URL key is `users.public_id` (ULID); `users.id` stays internal.
        // Static paths precede nothing here — every path is explicit — but the lifecycle
        // routes sit beside the resource ones, all behind `UserAdminPolicy`.
        Route::get('admin/users', [AdminUserController::class, 'index']);
        Route::post('admin/users', [AdminUserController::class, 'store']);
        // Declared before `{user:public_id}` so the literal segment is not read as a ULID.
        Route::get('admin/users/team-options', [AdminUserController::class, 'teamOptions']);
        Route::get('admin/users/{user:public_id}', [AdminUserController::class, 'show']);
        Route::patch('admin/users/{user:public_id}', [AdminUserController::class, 'update']);
        Route::patch('admin/users/{user:public_id}/role', [AdminUserController::class, 'changeRole']);
        Route::patch('admin/users/{user:public_id}/team', [AdminUserController::class, 'changeTeam']);
        Route::post('admin/users/{user:public_id}/suspend', [AdminUserController::class, 'suspend']);
        Route::post('admin/users/{user:public_id}/restore', [AdminUserController::class, 'restore']);
        Route::post('admin/users/{user:public_id}/resend-invitation', [AdminUserController::class, 'resendInvitation']);
        Route::get('admin/users/{user:public_id}/ownership-impact', [AdminUserController::class, 'ownershipImpact']);

        // Module 08 slice S3 — team administration (spec 19, ADR-0030). The team key is the
        // existing ULID; member keys are `users.public_id`. A membership change never
        // reassigns CRM ownership.
        Route::get('admin/teams', [AdminTeamController::class, 'index']);
        Route::post('admin/teams', [AdminTeamController::class, 'store']);
        Route::get('admin/teams/{team}', [AdminTeamController::class, 'show']);
        Route::patch('admin/teams/{team}', [AdminTeamController::class, 'update']);
        Route::patch('admin/teams/{team}/manager', [AdminTeamController::class, 'assignManager']);
        Route::post('admin/teams/{team}/members', [AdminTeamController::class, 'addMember']);
        Route::delete('admin/teams/{team}/members/{user:public_id}', [AdminTeamController::class, 'removeMember']);

        // Module 08 slice S4 — organization and CRM settings (spec 19, ADR-0030). The
        // allow-list lives in `App\Settings\SettingRegistry`: unknown or secret keys are
        // rejected, security keys are superadmin-only (hidden from the admin read), and the
        // refresh endpoint rebuilds only the settings snapshot — never a generic cache flush.
        Route::get('admin/settings', [AdminSettingController::class, 'index']);
        Route::patch('admin/settings', [AdminSettingController::class, 'update']);
        Route::post('admin/settings/refresh', [AdminSettingController::class, 'refresh']);

        // Module 08 slice S5 — the immutable audit book (spec 19, ADR-0030). Admin+ reads and
        // exports; an application admin never sees a security-sensitive event. `export` is a
        // static segment, declared before `{auditLog}` so it is not read as a ULID.
        Route::get('admin/audit-logs', [AdminAuditLogController::class, 'index']);
        Route::get('admin/audit-logs/export', [AdminAuditLogController::class, 'export']);
        Route::get('admin/audit-logs/{auditLog}', [AdminAuditLogController::class, 'show']);

        // Module 08 slice S6 — integration and operational health (spec 19). Read-only: no test
        // email, notification, migration or maintenance action. The summary is role-shaped on
        // the server, so an admin's payload carries no `platform` block at all.
        Route::get('admin/operations/summary', [AdminOperationsController::class, 'summary']);
        Route::get('admin/operations/health', [AdminOperationsController::class, 'health']);

        // Module 08 slice S7 — safe maintenance actions (spec 19, ADR-0030). Only the two
        // allow-listed, idempotent actions below; no generic runner, SQL, migration, backup
        // or restore endpoint exists.
        Route::post('admin/maintenance/refresh-configuration', [AdminMaintenanceController::class, 'refreshConfiguration']);
        Route::post('admin/maintenance/retry-email', [AdminMaintenanceController::class, 'retryEmail']);

        // Module 06 dashboard (spec 14, D2) — one role-scoped read over modules 01–05.
        Route::get('dashboard', [DashboardController::class, 'summary']);
        Route::get('dashboard/worklist', [DashboardController::class, 'worklist']);
        Route::get('dashboard/staff-performance', [DashboardController::class, 'staffPerformance']);

        // Module 07 reports (spec 15) — GET is the rules/cached read and never calls the provider;
        // POST is the on-demand generation path and spends the free-tier quota.
        Route::get('reports/management', [ManagementReportController::class, 'show']);
        Route::post('reports/management/generate', [ManagementReportController::class, 'generate']);

        // Notifications (submodule 03) — the topbar bell
        Route::get('notifications', [NotificationController::class, 'index']);
        Route::post('notifications/read-all', [NotificationController::class, 'markAllRead']);
        Route::post('notifications/{id}/read', [NotificationController::class, 'markRead']);

        // Assignment targets (submodule 03)
        Route::get('users/assignable', [UserController::class, 'assignable']);

        // Leads — submodules 01–03. Static paths must precede the resource route.
        Route::get('leads/summary', [LeadController::class, 'summary']);
        // Module 01 refresh S18 — fixed-template CSV import (spec 16 §CSV import).
        Route::get('leads/import/template', [LeadImportController::class, 'template']);
        Route::post('leads/import/preview', [LeadImportController::class, 'preview']);
        Route::post('leads/import', [LeadImportController::class, 'store']);
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

        // Submodule 04 — Lead Lifecycle & Status
        Route::patch('leads/{lead}/status', [LeadLifecycleController::class, 'updateStatus']);
        Route::post('leads/{lead}/archive', [LeadLifecycleController::class, 'archive']);
        Route::post('leads/{lead}/activities', [LeadLifecycleController::class, 'logActivity']);
        Route::get('leads/{lead}/timeline', [LeadLifecycleController::class, 'timeline']);

        // Companies — submodule 06. Static paths must precede the resource route.
        Route::post('companies/{id}/restore', [CompanyController::class, 'restore']);
        Route::apiResource('companies', CompanyController::class)
            ->only(['index', 'store', 'show', 'update', 'destroy']);

        // Module 01 refresh S5 — account delegation (spec 16).
        Route::post('companies/bulk-assign', [CompanyAssignmentController::class, 'bulkAssign']);
        Route::post('companies/{company}/assign', [CompanyAssignmentController::class, 'assign']);

        // Submodule 06 — company sites & activity history
        Route::get('companies/{company}/sites', [CompanySiteController::class, 'index']);
        Route::post('companies/{company}/sites', [CompanySiteController::class, 'store']);
        Route::patch('companies/{company}/sites/{site}', [CompanySiteController::class, 'update']);
        Route::delete('companies/{company}/sites/{site}', [CompanySiteController::class, 'destroy']);
        Route::get('companies/{company}/activities', [CompanyActivityController::class, 'timeline']);
        Route::post('companies/{company}/activities', [CompanyActivityController::class, 'logActivity']);

        // Submodule 07 — contacts: the company tab, plus the flat contact book.
        // Static paths must precede the resource route.
        Route::get('companies/{company}/contacts', [CompanyContactController::class, 'index']);
        Route::post('companies/{company}/contacts', [CompanyContactController::class, 'store']);
        Route::post('contacts/{id}/restore', [ContactController::class, 'restore']);
        Route::post('contacts/{contact}/make-primary', [ContactController::class, 'makePrimary']);
        Route::apiResource('contacts', ContactController::class)
            ->only(['index', 'show', 'update', 'destroy']);

        // Submodule 08 — lead→client conversion, plus the company opportunity tab.
        Route::get('leads/{lead}/conversion-preview', [LeadConversionController::class, 'preview']);
        Route::post('leads/{lead}/convert', [LeadConversionController::class, 'convert']);
        Route::get('companies/{company}/opportunities', [CompanyOpportunityController::class, 'index']);

        // Submodule 09 — client account lifecycle & contracts.
        // Static paths must precede the resource route.
        Route::patch('companies/{company}/lifecycle', [CompanyLifecycleController::class, 'update']);
        Route::post('companies/{company}/reactivate', [CompanyLifecycleController::class, 'reactivate']);
        Route::get('companies/{company}/billing', [CompanyBillingController::class, 'show']);
        // Module 01 refresh S9 — read-only Recruitment / Deployment / Timekeeping feed.
        Route::get('companies/{company}/delivery', [CompanyDeliveryController::class, 'show']);
        Route::get('companies/{company}/contracts', [CompanyContractController::class, 'index']);
        // Module 01 refresh S10 — read-only cross-account deployment book.
        Route::get('deployments', [DeploymentBookController::class, 'index']);
        // Module 01 refresh S12 — read-only cross-account AR book (manager+).
        Route::get('billing', [BillingBookController::class, 'index']);
        Route::get('contracts/{contract}/rate-cards', [ContractRateCardController::class, 'index']);
        Route::apiResource('contracts', ContractController::class)
            ->only(['index', 'show']);

        // Module 02 — opportunity pipeline. Static paths must precede the resource route.
        Route::get('pipeline', [PipelineController::class, 'index']);
        Route::post('opportunities/{id}/restore', [OpportunityController::class, 'restore']);
        Route::post('opportunities/{opportunity}/stage', [OpportunityStageController::class, 'update']);
        Route::get('opportunities/{opportunity}/timeline', [OpportunityActivityController::class, 'timeline']);
        Route::post('opportunities/{opportunity}/activities', [OpportunityActivityController::class, 'logActivity']);
        Route::apiResource('opportunities', OpportunityController::class)
            ->only(['index', 'store', 'show', 'update', 'destroy']);

        // Module 03 — communication history. The per-record history endpoint sits beside each
        // record's timeline; the flat book is the resource route below. Slice S2 adds the
        // outbound email path: send a draft, retry a rejected one, pull the mocked replies.
        // Slice S3 hangs files off a message: upload under the message, download and remove
        // through the attachment's own route. Slice S4 groups messages into conversations:
        // list them, read one, and answer a message into its own thread.
        Route::get('leads/{lead}/communications', [CommunicationController::class, 'forLead']);
        Route::get('companies/{company}/communications', [CommunicationController::class, 'forCompany']);
        Route::get('contacts/{contact}/communications', [CommunicationController::class, 'forContact']);
        Route::get('opportunities/{opportunity}/communications', [CommunicationController::class, 'forOpportunity']);
        Route::get('contracts/{contract}/communications', [CommunicationController::class, 'forContract']);
        // `threads` is a static segment, so it has to be declared before the resource route
        // below: otherwise `communications/{communication}` would swallow it as an id.
        Route::get('communications/threads', [CommunicationController::class, 'threads']);
        Route::get('communications/threads/{threadId}', [CommunicationController::class, 'thread']);
        Route::post('communications/sync-inbound', [CommunicationController::class, 'syncInbound']);
        // Module 04 slice S2: the in-app twin of `communication:send-due` — admin+ only, and it
        // must sit above the resource route or `communications/{communication}` swallows it.
        Route::post('communications/dispatch-due', [CommunicationController::class, 'dispatchDue']);
        Route::post('communications/{communication}/attachments', [AttachmentController::class, 'store']);
        Route::post('communications/{communication}/send', [CommunicationController::class, 'send']);
        Route::post('communications/{communication}/retry', [CommunicationController::class, 'retry']);
        Route::post('communications/{communication}/reply', [CommunicationController::class, 'reply']);
        Route::post('communications/{id}/restore', [CommunicationController::class, 'restore']);
        Route::apiResource('communications', CommunicationController::class)
            ->only(['index', 'store', 'show', 'update', 'destroy']);
        Route::get('attachments/{attachment}/file', [AttachmentController::class, 'download']);
        Route::delete('attachments/{attachment}', [AttachmentController::class, 'destroy']);

        // Module 03 slice S5 — reusable wording for the composer. `fields` is a static segment,
        // declared before the resource route for the same reason `threads` is above.
        Route::get('communication-templates/fields', [CommunicationTemplateController::class, 'fields']);
        Route::post('communication-templates/{id}/restore', [CommunicationTemplateController::class, 'restore']);
        Route::post('communication-templates/{template}/render', [CommunicationTemplateController::class, 'render']);
        Route::apiResource('communication-templates', CommunicationTemplateController::class)
            ->parameters(['communication-templates' => 'template'])
            ->only(['index', 'store', 'show', 'update', 'destroy']);

        // Module 04 — follow-up reminders. The worklist is a read model over what is owed (slice
        // S1 lists tasks; slice S2 adds scheduled messages beside them). A task is cancelled
        // rather than deleted, so there is no destroy route. The due run is admin-only and is
        // the in-app twin of `follow-ups:notify-due` (slice S3); the generator is manager+ and
        // the twin of `follow-ups:generate` (slice S4).
        Route::get('follow-ups', [FollowUpController::class, 'index']);
        Route::post('follow-ups/dispatch-due', [FollowUpController::class, 'dispatchDue']);
        Route::post('follow-ups/generate', [FollowUpController::class, 'generate']);
        Route::patch('tasks/{task}/status', [TaskController::class, 'updateStatus']);
        Route::apiResource('tasks', TaskController::class)->only(['store', 'show', 'update']);

        // Module 05 slice S1 — the survey library. A survey is house wording like a message
        // template: every role reads, manager+ authors. Sending (S2) hangs off the company, not
        // off the survey, because it needs that account's update right.
        Route::post('surveys/{id}/restore', [SurveyController::class, 'restore']);
        Route::post('surveys/{survey}/duplicate', [SurveyController::class, 'duplicate']);
        Route::apiResource('surveys', SurveyController::class)
            ->only(['index', 'store', 'show', 'update', 'destroy']);

        // Module 05 slice S2 — invitations. Sending needs the account's update right and is a real
        // history record (module 03's gateway + `Communication`); an invitation is cancelled or
        // resent, never deleted.
        Route::get('survey-invitations', [SurveyInvitationController::class, 'index']);
        Route::get('survey-invitations/{invitation}', [SurveyInvitationController::class, 'show']);
        Route::post('survey-invitations/{invitation}/cancel', [SurveyInvitationController::class, 'cancel']);
        Route::post('survey-invitations/{invitation}/resend', [SurveyInvitationController::class, 'resend']);
        Route::post('surveys/{survey}/invitations', [SurveyInvitationController::class, 'store']);
        Route::get('companies/{company}/survey-invitations', [SurveyInvitationController::class, 'forCompany']);

        // Module 05 slice S3 — what came back. A response has no write route except the
        // closed-loop resolution: the client answered once, and the numbers are derived on read.
        Route::get('satisfaction/summary', [SatisfactionController::class, 'summary']);
        Route::get('companies/{company}/satisfaction', [SatisfactionController::class, 'forCompany']);
        Route::get('survey-responses', [SurveyResponseController::class, 'index']);
        Route::get('survey-responses/{response}', [SurveyResponseController::class, 'show']);

        // Module 05 slice S4 — close the loop on an unhappy answer.
        Route::post('survey-responses/{response}/resolve', [SurveyResponseController::class, 'resolve']);
    });
});
