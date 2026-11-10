<?php

namespace Tests\Feature;

use App\Enums\AuditSeverity;
use App\Enums\CommunicationChannel;
use App\Enums\CommunicationDirection;
use App\Enums\CommunicationStatus;
use App\Models\AuditLog;
use App\Models\Communication;
use App\Models\Lead;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Safe, allow-listed maintenance actions (module 08 slice S7, spec 19 §S7, ADR-0030).
 *
 * The contract under test: both endpoints are admin+ only and require `confirm: true`; the
 * configuration refresh calls the effective-settings service and is idempotent; the email retry
 * only touches exactly one failed outbound email, reuses the module 03 delivery rules, treats a
 * gateway rejection as a 200 delivery outcome, rejects a second attempt after a successful send,
 * and every run writes the matching audit event.
 */
class AdminMaintenanceTest extends TestCase
{
    use RefreshDatabase;

    private const REFRESH = '/api/v1/admin/maintenance/refresh-configuration';

    private const RETRY = '/api/v1/admin/maintenance/retry-email';

    // --- Role boundaries and confirmation ------------------------------------

    public function test_both_actions_require_authentication(): void
    {
        $this->postJson(self::REFRESH, ['confirm' => true])->assertUnauthorized();
        $this->postJson(self::RETRY, ['confirm' => true])->assertUnauthorized();
    }

    public function test_salesreps_and_managers_are_rejected_from_both_actions(): void
    {
        $communication = $this->failedEmail(User::factory()->salesrep()->create());

        foreach ([User::factory()->salesrep()->create(), User::factory()->manager()->create()] as $user) {
            $this->actingAs($user, 'api')
                ->postJson(self::REFRESH, ['confirm' => true])
                ->assertForbidden()
                ->assertJsonPath('message', 'This action is unauthorized.');

            $this->actingAs($user, 'api')
                ->postJson(self::RETRY, [
                    'confirm' => true,
                    'communication_id' => $communication->id,
                ])
                ->assertForbidden()
                ->assertJsonPath('message', 'This action is unauthorized.');
        }
    }

    public function test_confirmation_is_required_before_either_action_runs(): void
    {
        $admin = User::factory()->admin()->create();
        $communication = $this->failedEmail($admin);

        foreach ([['confirm' => false], []] as $payload) {
            $this->actingAs($admin, 'api')
                ->postJson(self::REFRESH, $payload)
                ->assertUnprocessable()
                ->assertJsonValidationErrors('confirm');

            $this->actingAs($admin, 'api')
                ->postJson(self::RETRY, $payload + ['communication_id' => $communication->id])
                ->assertUnprocessable()
                ->assertJsonValidationErrors('confirm');
        }

        $this->assertDatabaseMissing('audit_logs', ['action' => 'maintenance.configuration_refreshed']);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'maintenance.email_retried']);
    }

    // --- Configuration refresh ----------------------------------------------

    public function test_an_admin_and_a_superadmin_may_refresh_the_configuration_and_it_is_audited(): void
    {
        foreach ([User::factory()->admin()->create(), User::factory()->superadmin()->create()] as $user) {
            $this->actingAs($user, 'api')
                ->postJson(self::REFRESH, ['confirm' => true])
                ->assertOk()
                ->assertJsonPath('data.refreshed', true)
                ->assertJsonPath('data.result', 'refreshed');

            $log = AuditLog::query()
                ->where('action', 'maintenance.configuration_refreshed')
                ->where('actor_id', $user->id)
                ->latest('created_at')
                ->first();

            $this->assertNotNull($log);
            $this->assertSame(AuditSeverity::Info, $log->severity);
            $this->assertSame('refreshed', $log->after['result']);
            $this->assertSame($user->role->value, $log->after['actor_role']);
        }
    }

    public function test_repeated_configuration_refresh_is_safe_and_each_run_is_audited(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin, 'api')->postJson(self::REFRESH, ['confirm' => true])->assertOk();
        $this->actingAs($admin, 'api')->postJson(self::REFRESH, ['confirm' => true])->assertOk();

        $this->assertSame(2, AuditLog::query()
            ->where('action', 'maintenance.configuration_refreshed')
            ->count());
    }

    // --- Email retry ---------------------------------------------------------

    public function test_a_failed_email_is_retried_once_and_both_attempts_are_audited(): void
    {
        $admin = User::factory()->admin()->create();
        $communication = $this->failedEmail($admin);

        $this->actingAs($admin, 'api')
            ->postJson(self::RETRY, [
                'confirm' => true,
                'communication_id' => $communication->id,
            ])
            ->assertOk()
            ->assertJsonPath('data.result', 'sent')
            ->assertJsonPath('data.status', CommunicationStatus::Sent->value)
            ->assertJsonPath('meta.source', 'Email (mocked)');

        $communication->refresh();
        $this->assertSame(CommunicationStatus::Sent, $communication->status);
        $this->assertNotNull($communication->external_id);

        $log = AuditLog::query()
            ->where('action', 'maintenance.email_retried')
            ->latest('created_at')
            ->first();

        $this->assertNotNull($log);
        $this->assertSame(AuditSeverity::Notice, $log->severity);
        $this->assertNull($log->subject_id);
        $this->assertSame(CommunicationStatus::Failed->value, $log->before['status']);
        $this->assertSame(CommunicationStatus::Sent->value, $log->after['status']);
        $this->assertSame('sent', $log->after['result']);
        $this->assertSame($communication->id, $log->after['communication_id']);
        $this->assertSame($communication->reference, $log->after['reference']);
        $this->assertSame($admin->role->value, $log->after['actor_role']);

        // A second attempt is an explicit state error, not a second send.
        $this->actingAs($admin, 'api')
            ->postJson(self::RETRY, [
                'confirm' => true,
                'communication_id' => $communication->id,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('status');
    }

    public function test_retry_only_accepts_a_failed_email_with_a_recipient(): void
    {
        $admin = User::factory()->admin()->create();

        $sent = $this->failedEmail($admin, ['status' => CommunicationStatus::Sent]);
        $call = $this->failedEmail($admin, ['channel' => CommunicationChannel::Call]);
        $noRecipient = $this->failedEmail($admin, [
            'to_address' => null,
            'to_name' => null,
            'contact_id' => null,
        ]);

        $this->actingAs($admin, 'api')
            ->postJson(self::RETRY, ['confirm' => true, 'communication_id' => $sent->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('status');

        $this->actingAs($admin, 'api')
            ->postJson(self::RETRY, ['confirm' => true, 'communication_id' => $call->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('status');

        $this->actingAs($admin, 'api')
            ->postJson(self::RETRY, ['confirm' => true, 'communication_id' => $noRecipient->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('to_address');

        $this->assertDatabaseMissing('audit_logs', ['action' => 'maintenance.email_retried']);
    }

    public function test_an_unknown_communication_id_is_not_found_and_writes_no_audit_row(): void
    {
        $this->actingAs(User::factory()->admin()->create(), 'api')
            ->postJson(self::RETRY, [
                'confirm' => true,
                'communication_id' => (string) Str::ulid(),
            ])
            ->assertNotFound();

        $this->assertDatabaseMissing('audit_logs', ['action' => 'maintenance.email_retried']);
    }

    public function test_a_mocked_gateway_rejection_is_a_200_failed_result_and_is_audited(): void
    {
        config(['crm.integrations.email.simulate_failure' => true]);

        $admin = User::factory()->admin()->create();
        $communication = $this->failedEmail($admin);

        $this->actingAs($admin, 'api')
            ->postJson(self::RETRY, [
                'confirm' => true,
                'communication_id' => $communication->id,
            ])
            ->assertOk()
            ->assertJsonPath('data.result', 'failed')
            ->assertJsonPath('data.status', CommunicationStatus::Failed->value);

        $communication->refresh();
        $this->assertSame(CommunicationStatus::Failed, $communication->status);

        $log = AuditLog::query()
            ->where('action', 'maintenance.email_retried')
            ->latest('created_at')
            ->first();

        $this->assertNotNull($log);
        $this->assertSame(CommunicationStatus::Failed->value, $log->after['status']);
        $this->assertSame('failed', $log->after['result']);
    }

    // --- Helpers ------------------------------------------------------------

    /**
     * A message the mocked gateway rejected. Every value that matters to the retry is explicit
     * so the test never depends on a random factory value.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function failedEmail(User $owner, array $overrides = []): Communication
    {
        $lead = Lead::factory()->create(['owner_id' => $owner->id]);

        return Communication::factory()
            ->about($lead, $owner)
            ->create($overrides + [
                'channel' => CommunicationChannel::Email,
                'direction' => CommunicationDirection::Outbound,
                'status' => CommunicationStatus::Failed,
                'subject' => 'Rate sheet follow-up',
                'to_address' => 'client@example.test',
                'to_name' => 'Client Contact',
                'failed_reason' => 'The mail provider rejected the message (mocked failure).',
            ]);
    }
}
