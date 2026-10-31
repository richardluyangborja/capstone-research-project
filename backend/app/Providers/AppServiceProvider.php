<?php

namespace App\Providers;

use App\Integrations\DeploymentFeed;
use App\Integrations\EmailGateway;
use App\Integrations\FinanceBilling;
use App\Integrations\HrGateway;
use App\Integrations\MockDeploymentFeed;
use App\Integrations\MockEmailGateway;
use App\Integrations\MockFinanceBilling;
use App\Integrations\MockHrGateway;
use App\Integrations\MockRecruitmentFeed;
use App\Integrations\MockServiceHandoff;
use App\Integrations\MockTimekeepingFeed;
use App\Integrations\RecruitmentFeed;
use App\Integrations\ServiceHandoff;
use App\Integrations\TimekeepingFeed;
use App\Models\Activity;
use App\Policies\TaskPolicy;
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
        $this->app->bind(ServiceHandoff::class, MockServiceHandoff::class);
        $this->app->bind(FinanceBilling::class, MockFinanceBilling::class);
        $this->app->bind(EmailGateway::class, MockEmailGateway::class);
        $this->app->bind(HrGateway::class, MockHrGateway::class);
        // Module 01 refresh S9 — read-only Recruitment / Deployment / Timekeeping feeds.
        $this->app->bind(RecruitmentFeed::class, MockRecruitmentFeed::class);
        $this->app->bind(DeploymentFeed::class, MockDeploymentFeed::class);
        $this->app->bind(TimekeepingFeed::class, MockTimekeepingFeed::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // A task *is* an `Activity`, but a policy named `ActivityPolicy` would claim every
        // quick-log line — the rules here are about follow-ups only (spec 12 §Permissions).
        Gate::policy(Activity::class, TaskPolicy::class);
    }
}
