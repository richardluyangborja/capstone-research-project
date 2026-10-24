<?php

namespace App\Http\Dashboard;

use App\Enums\ActivityStatus;
use App\Enums\CompanyStatus;
use App\Enums\HandoffStatus;
use App\Enums\LeadStatus;
use App\Models\Activity;
use App\Models\Communication;
use App\Models\Company;
use App\Models\Contract;
use App\Models\Lead;
use App\Models\Opportunity;
use App\Models\SurveyResponse;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * The cross-cutting counts the dashboard reads (spec 14 §Alerts & activity, §Worklist).
 *
 * Every query goes through the owning module's `visibleTo` scope, so the same number is
 * correct for a salesrep, a manager and an admin without a second rule. Nothing is stored:
 * "overdue", "stalled" and "expiring" are computed against the clock and the configured
 * thresholds on every read.
 */
final class DashboardSignals
{
    public function __construct(private readonly User $user) {}

    /**
     * Unhappy survey answers nobody has closed the loop on (module 05 S4).
     */
    public function unresolvedSurveys(): int
    {
        return SurveyResponse::query()
            ->visibleTo($this->user)
            ->unresolvedAttention()
            ->count();
    }

    /**
     * Accounts the lifecycle marks at risk (module 01 aging is manual in Phase 1).
     */
    public function atRiskCompanies(): int
    {
        return Company::query()
            ->visibleTo($this->user)
            ->where('status', CompanyStatus::AtRisk)
            ->count();
    }

    /**
     * The KPI's "needs attention": unhappy answers plus at-risk accounts.
     */
    public function attention(): int
    {
        return $this->unresolvedSurveys() + $this->atRiskCompanies();
    }

    /**
     * Open tasks and scheduled messages whose moment has already passed.
     */
    public function overdueFollowUps(): int
    {
        $now = now();

        $tasks = Activity::query()
            ->visibleTo($this->user)
            ->tasks()
            ->where('status', ActivityStatus::Open)
            ->whereNotNull('due_at')
            ->where('due_at', '<', $now)
            ->count();

        $messages = Communication::query()
            ->visibleTo($this->user)
            ->scheduled()
            ->whereNotNull('scheduled_for')
            ->where('scheduled_for', '<', $now)
            ->count();

        return $tasks + $messages;
    }

    /**
     * Signed contracts inside the renewal window — ending today or later.
     */
    public function expiringContracts(): int
    {
        $today = CarbonImmutable::now((string) config('crm.timezone', 'Asia/Manila'))->toDateString();

        return Contract::query()
            ->visibleTo($this->user)
            ->signedExpiringSoon()
            ->whereDate('end_date', '>=', $today)
            ->count();
    }

    /**
     * The shared queue: nobody has touched the lead for a day.
     */
    public function unassignedLeads(): int
    {
        return Lead::query()
            ->visibleTo($this->user)
            ->whereNull('owner_id')
            ->where('status', '!=', LeadStatus::Archived)
            ->where('created_at', '<=', now()->subDay())
            ->count();
    }

    /**
     * Open deals that have not moved for `crm.pipeline.stuck_days`.
     */
    public function stalledDeals(): int
    {
        return Opportunity::query()
            ->visibleTo($this->user)
            ->open()
            ->whereRaw(
                'COALESCE(last_activity_at, stage_changed_at, created_at) <= ?',
                [now()->subDays((int) config('crm.pipeline.stuck_days', 21))->toDateTimeString()],
            )
            ->count();
    }

    /**
     * Accounts parked in `onboarding` because the mocked Service handoff failed.
     */
    public function failedHandoffs(): int
    {
        return Company::query()
            ->visibleTo($this->user)
            ->where('handoff_status', HandoffStatus::Failed)
            ->count();
    }
}
