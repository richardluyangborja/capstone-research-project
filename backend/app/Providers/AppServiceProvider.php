<?php

namespace App\Providers;

use App\Integrations\ContractFeed;
use App\Integrations\DeploymentFeed;
use App\Integrations\EmailGateway;
use App\Integrations\FinanceBilling;
use App\Integrations\HrGateway;
use App\Integrations\MockContractFeed;
use App\Integrations\MockDeploymentFeed;
use App\Integrations\MockEmailGateway;
use App\Integrations\MockFinanceBilling;
use App\Integrations\MockHrGateway;
use App\Integrations\MockRecruitmentFeed;
use App\Integrations\MockTimekeepingFeed;
use App\Integrations\RecruitmentFeed;
use App\Integrations\TimekeepingFeed;
use App\Listeners\FilterDatabaseNotifications;
use App\Models\Activity;
use App\Models\AuditLog;
use App\Models\Setting;
use App\Models\Team;
use App\Models\User;
use App\Policies\AdminAccessPolicy;
use App\Policies\AuditLogPolicy;
use App\Policies\MaintenanceActionPolicy;
use App\Policies\OperationsHealthPolicy;
use App\Policies\SettingPolicy;
use App\Policies\TaskPolicy;
use App\Policies\TeamAdminPolicy;
use App\Policies\UserAdminPolicy;
use App\Settings\EffectiveSettings;
use App\Support\MaintenanceAction;
use Illuminate\Notifications\Events\NotificationSending;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Department integrations are mocked (ADR-0005). Swapping a mock for a real
        // driver is a binding change here — no caller changes.
        $this->app->bind(FinanceBilling::class, MockFinanceBilling::class);
        $this->app->bind(EmailGateway::class, MockEmailGateway::class);
        $this->app->bind(HrGateway::class, MockHrGateway::class);
        // Module 01 refresh S9 — read-only Recruitment / Deployment / Timekeeping feeds.
        $this->app->bind(RecruitmentFeed::class, MockRecruitmentFeed::class);
        $this->app->bind(DeploymentFeed::class, MockDeploymentFeed::class);
        $this->app->bind(TimekeepingFeed::class, MockTimekeepingFeed::class);
        $this->app->bind(ContractFeed::class, MockContractFeed::class);

        // Module 08 S4 — one effective-settings resolver per process. It remembers the
        // baseline config so deleting an override restores the environment/default value
        // instead of the value an earlier apply left behind in a long-running worker.
        $this->app->singleton(EffectiveSettings::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // A task *is* an `Activity`, but a policy named `ActivityPolicy` would claim every
        // quick-log line — the rules here are about follow-ups only (spec 12 §Permissions).
        Gate::policy(Activity::class, TaskPolicy::class);

        // Module 08 S1 — the administration shell's capability probe (spec 19, ADR-0030).
        Gate::define('viewAdminAccess', [AdminAccessPolicy::class, 'view']);

        // Module 08 S2 — the user directory's lifecycle policy. It is named `UserAdminPolicy`
        // (not `UserPolicy`) on purpose: auto-discovery would silently claim every `users`
        // ability across the CRM, so it is registered explicitly for the User model.
        Gate::policy(User::class, UserAdminPolicy::class);

        // Module 08 S3 — team administration. Registered explicitly alongside the user policy
        // so the application plane owns both halves of the organizational model.
        Gate::policy(Team::class, TeamAdminPolicy::class);

        // Module 08 S4 — the settings registry is the allow-list; a Setting row is only an
        // override. Registered explicitly so auto-discovery cannot claim a narrower meaning.
        Gate::policy(Setting::class, SettingPolicy::class);
        Gate::policy(MaintenanceAction::class, MaintenanceActionPolicy::class);

        // Module 08 S5 — the audit book's read/export boundary. Registered explicitly so the
        // append-only model never inherits a broader auto-discovered policy.
        Gate::policy(AuditLog::class, AuditLogPolicy::class);

        // Module 08 S6 — operational health. An ability, not a model policy: the endpoints are
        // reads over infrastructure rather than over a record. `platform` fields are omitted
        // by the summary service for an admin, so no ability needs to guard them separately.
        Gate::define('viewSummary', [OperationsHealthPolicy::class, 'viewSummary']);
        Gate::define('viewHealth', [OperationsHealthPolicy::class, 'viewHealth']);

        // Module 08 S4 — `notifications.database_enabled` is enforced at send time: the
        // listener vetoes a database delivery when the switch is off.
        Event::listen(NotificationSending::class, FilterDatabaseNotifications::class);
    }
}
