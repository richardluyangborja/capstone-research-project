<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\AuditSeverity;
use App\Enums\CommunicationStatus;
use App\Http\Communications\CommunicationDelivery;
use App\Http\Controllers\Controller;
use App\Http\Operations\MaintenanceAction;
use App\Integrations\EmailGateway;
use App\Models\AuditLog;
use App\Models\Communication;
use App\Settings\EffectiveSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Safe, allow-listed maintenance (module 08 slice S7, spec 19 §S7).
 *
 * Exactly two actions exist: rebuild the effective-configuration snapshot through the same
 * `EffectiveSettings::refresh()` the settings page uses (never a generic cache flush), and
 * retry one failed outbound email through the module 03 delivery path and its state rules.
 * Both need an explicit `confirm: true`, both are admin+ through `MaintenanceActionPolicy`,
 * and both append an immutable audit row naming the actor and their role. Nothing here runs a
 * command, SQL, migration, backup, restore or cache operation.
 */
class AdminMaintenanceController extends Controller
{
    public function __construct(
        private readonly EffectiveSettings $effective,
        private readonly CommunicationDelivery $delivery,
    ) {}

    /**
     * Rebuild the effective-configuration snapshot. Idempotent: every run reads the same
     * settings rows and reports the same result, and every run is audited.
     */
    public function refreshConfiguration(Request $request): JsonResponse
    {
        Gate::authorize('refreshConfiguration', MaintenanceAction::class);
        $this->confirm($request);

        $this->effective->refresh();

        $user = $request->user();

        AuditLog::record(
            'maintenance.configuration_refreshed',
            AuditSeverity::Info,
            $user->id,
            MaintenanceAction::class,
            null,
            null,
            [
                'result' => 'refreshed',
                'actor_role' => $user->role->value,
            ],
        );

        return response()->json([
            'data' => ['result' => 'refreshed', 'refreshed' => true],
        ]);
    }

    /**
     * Retry exactly one failed outbound email. A gateway rejection is a delivery outcome, not
     * an HTTP error: the response is still 200 with `result: failed`, exactly like the module
     * 03 retry. A second attempt after a successful send is an explicit 422 state error.
     */
    public function retryEmail(Request $request, EmailGateway $gateway): JsonResponse
    {
        Gate::authorize('retryEmail', MaintenanceAction::class);
        $this->confirm($request);

        $validated = $request->validate([
            'communication_id' => ['required', 'string'],
        ]);

        $communication = Communication::query()->find($validated['communication_id']);

        if ($communication === null) {
            abort(404, 'That communication does not exist.');
        }

        Gate::authorize('retry', $communication);

        $before = $communication->status;
        $user = $request->user();

        $communication = $this->delivery->handToGateway(
            $gateway,
            $communication,
            $user,
            [CommunicationStatus::Failed],
            'a message the gateway rejected',
        );

        $result = $communication->status === CommunicationStatus::Sent ? 'sent' : 'failed';

        AuditLog::record(
            'maintenance.email_retried',
            AuditSeverity::Notice,
            $user->id,
            null,
            null,
            ['status' => $before->value],
            [
                'status' => $communication->status->value,
                'result' => $result,
                'communication_id' => $communication->id,
                'reference' => $communication->reference,
                'actor_role' => $user->role->value,
            ],
        );

        return response()->json([
            'data' => [
                'id' => $communication->id,
                'reference' => $communication->reference,
                'status' => $communication->status->value,
                'result' => $result,
                'external_id' => $communication->external_id,
                'failed_reason' => $communication->failed_reason,
            ],
            'meta' => ['source' => $gateway->source()],
        ]);
    }

    /**
     * These actions are never implicit: a request without `confirm: true` is a 422 before
     * anything runs.
     */
    private function confirm(Request $request): void
    {
        $validator = validator($request->all(), [
            'confirm' => ['required', 'accepted'],
        ], [], ['confirm' => 'confirmation']);

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }
    }
}
