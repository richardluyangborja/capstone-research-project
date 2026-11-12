<?php

namespace Database\Seeders;

use App\Enums\AuditSeverity;
use App\Enums\Role;
use App\Enums\UserStatus;
use App\Models\AuditLog;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Database\Seeder;

/** Fixed administration fixtures for Module 08 (no faker). */
class DemoAdministrationSeeder extends Seeder
{
    public function run(): void
    {
        $admin = User::query()->where('email', 'admin@primepower.test')->firstOrFail();
        $suspended = User::query()->firstOrCreate(
            ['email' => 'suspended@primepower.test'],
            [
                'name' => 'Jordan Suspended',
                'password' => 'password',
                'role' => Role::Salesrep,
                'status' => UserStatus::Suspended,
                'status_reason' => 'Seeded suspended account for administration demo.',
                'suspended_at' => now(),
                'suspended_by' => $admin->id,
            ],
        );

        $settings = [
            'crm.pipeline.stuck_days' => 28,
            'notifications.database_enabled' => true,
            'ai.reports.enabled' => true,
        ];

        foreach ($settings as $key => $value) {
            Setting::query()->firstOrCreate(
                ['key' => $key],
                ['value' => $value, 'updated_by' => $admin->id],
            );
        }

        $suspensionBefore = ['status' => UserStatus::Active->value];
        $suspensionAfter = ['status' => UserStatus::Suspended->value, 'status_reason' => $suspended->status_reason];
        if (! $this->auditExists('user.suspended', $admin->id, User::class, $suspended->id, $suspensionAfter)) {
            AuditLog::record('user.suspended', AuditSeverity::Warning, $admin->id, User::class, $suspended->id, $suspensionBefore, $suspensionAfter);
        }

        $settingsBefore = ['crm.pipeline.stuck_days' => 21];
        $settingsAfter = ['crm.pipeline.stuck_days' => $settings['crm.pipeline.stuck_days']];
        if (! $this->auditExists('setting.updated', $admin->id, Setting::class, null, $settingsAfter)) {
            AuditLog::record('setting.updated', AuditSeverity::Notice, $admin->id, Setting::class, null, $settingsBefore, $settingsAfter);
        }
    }

    /** @param array<string, mixed> $after */
    private function auditExists(string $action, int $actorId, string $subjectType, ?int $subjectId, array $after): bool
    {
        return AuditLog::query()
            ->where('action', $action)
            ->where('actor_id', $actorId)
            ->where('subject_type', $subjectType)
            ->where('subject_id', $subjectId)
            ->get()
            ->contains(fn (AuditLog $log): bool => $log->after === $after);

    }
}
