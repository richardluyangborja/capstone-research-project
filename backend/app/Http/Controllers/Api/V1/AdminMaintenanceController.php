<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\AuditSeverity;
use App\Enums\CommunicationChannel;
use App\Enums\CommunicationStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\CommunicationResource;
use App\Integrations\EmailGateway;
use App\Models\AuditLog;
use App\Models\Communication;
use App\Settings\EffectiveSettings;
use App\Support\MaintenanceAction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Safe, allow-listed maintenance actions (module 08 slice S7, spec 19, ADR-0030).
 *
 * Each action is idempotent or rejects with an explicit state error, calls an existing
 * service rather than duplicating a workflow, requires confirmation, and is audited so the
 * actor (admin vs superadmin) is distinguishable. There is no generic runner, SQL, migration,
 * backup or restore endpoint.
 */
class AdminMaintenanceController extends Controller
{
    public function __construct(private readonly EffectiveSettings $effective) {}

    /**
     * Rebuild the effective configuration/cache view. Bounded to the settings snapshot —
     * never a generic cache flush.
     */
    public function refreshConfiguration(Request $request): JsonResponse
    {
        $request->validate(['confirm' => ['required', 'accepted']]);
        Gate::authorize('refreshConfiguration', new MaintenanceAction());

        $this->effective->refresh();

        AuditLog::record(
            'maintenance.configuration_refreshed',
            AuditSeverity::Info,
            $request->user()->id,
            \App\Models\Setting::class,
            null,
            null,
            ['action' => 'refresh_configuration', 'result' => 'refreshed'],
        );

        return response()->json([
            'data' => [
                'action' => 'refresh_configuration',
                'result' => 'refreshed',
                'actor_id' => $request->user()->public_id,
                'actor_role' => $request->user()->role->value,
            ],
        ]);
    }

    /**
     * Retry a supported failed mocked email delivery. The owning communication state and
     * policy remain authoritative: only a failed email may be retried, and a message that is
     * not in that state is rejected with an explicit 422 (a second retry after success is
     * therefore safe/idempotent in effect — it cannot resend a sent message).
     */
    public function retryEmail(Request $request, EmailGateway $gateway): JsonResponse
    {
        $request->validate([
            'confirm' => ['required', 'accepted'],
            'communication_id' => ['required', 'string'],
        ]);
        Gate::authorize('retryEmail', new MaintenanceAction());

        $communication = Communication::query()->findOrFail($request->string('communication_id')->toString());

        // State is the owner: only a failed email is a supported retry target. Delegating to
        // the message state means a sent/received/draft message can never be re-sent here.
        if ($communication->channel !== CommunicationChannel::Email) {
            throw ValidationException::withMessages([
                'communication_id' => ['Only a failed email message with an address can be retried.'],
            ]);
        }

        if ($communication->status !== CommunicationStatus::Failed) {
            throw ValidationException::withMessages([
                'communication_id' => ['Only a failed email message with an address can be retried.'],
            ]);
        }

        $communication->fillRecipientFromContact();
        if (blank($communication->to_address)) {
            throw ValidationException::withMessages([
                'communication_id' => ['The message has no address to retry.'],
            ]);
        }

        $before = $communication->status->value;
        $communication->sendThrough($gateway, $request->user());
        $communication->refresh();

        AuditLog::record(
            'maintenance.email_retried',
            AuditSeverity::Notice,
            $request->user()->id,
            Communication::class,
            null,
            ['status' => $before],
            [
                'status' => $communication->status->value,
                'result' => $communication->status === CommunicationStatus::Sent ? 'sent' : 'failed',
                'actor_role' => $request->user()->role->value,
            ],
        );

        return response()->json([
            'data' => [
                'action' => 'retry_email',
                'result' => $communication->status === CommunicationStatus::Sent ? 'sent' : 'failed',
                'actor_id' => $request->user()->public_id,
                'actor_role' => $request->user()->role->value,
                'communication' => (new CommunicationResource($communication->loadMissing(['owner', 'contact', 'subjectable'])))->resolve(),
            ],
            'meta' => ['source' => $gateway->source()],
        ]);
    }
}