<?php

namespace App\Http\Companies;

use App\Enums\CompanyStatus;
use App\Enums\ContractStatus;
use App\Enums\HandoffStatus;
use App\Http\Delivery\DeliverySnapshot;
use App\Integrations\DeploymentFeed;
use App\Integrations\DeploymentSummary;
use App\Integrations\FinanceBilling;
use App\Integrations\JobOrderSummary;
use App\Integrations\RecruitmentFeed;
use App\Integrations\TimekeepingFeed;
use App\Models\Company;
use App\Models\Contract;
use App\Models\SurveyResponse;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * The explainable account-health read (spec 16 §Account health, ADR-0023 §5).
 *
 * Every flag is derived on read from existing records and read-only department feeds. The
 * service never writes `Company.status`, never moves the lifecycle and never invents a value:
 * a salesrep gets the same financial flag as a manager, but with the amount redacted.
 */
final class AccountHealth
{
    private const SEVERITY_ORDER = ['critical' => 0, 'warning' => 1, 'info' => 2];

    private const FLAG_ORDER = [
        'handoff_failed' => 0,
        'ar_overdue' => 1,
        'satisfaction_attention' => 2,
        'contract_expiring' => 3,
        'deployment_underfilled' => 4,
        'job_order_stalled' => 5,
        'timesheets_pending' => 6,
        'renewal_overdue' => 7,
        'engagement_quiet' => 8,
    ];

    public function __construct(
        private readonly FinanceBilling $billing,
        private readonly RecruitmentFeed $recruitment,
        private readonly DeploymentFeed $deployment,
        private readonly TimekeepingFeed $timekeeping,
    ) {}

    /**
     * @param  Collection<int, Company>  $companies
     */
    public function attach(Collection $companies, ?User $viewer): void
    {
        foreach ($companies as $company) {
            $company->setAttribute('health', $this->forCompany($company, $viewer));
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function forCompany(Company $company, ?User $viewer): array
    {
        $canViewFinancialDetail = $viewer !== null && $viewer->can('viewBilling', $company);
        $flags = [];

        $this->addHandoffFlag($company, $flags);
        $this->addBillingFlag($company, $flags, $canViewFinancialDetail);
        $this->addSatisfactionFlag($company, $flags);
        $this->addContractFlags($company, $flags);
        $this->addDeliveryFlags($company, $flags);
        $this->addEngagementFlag($company, $flags);

        $ordered = collect($flags)
            ->sortBy(fn (array $flag): string => sprintf(
                '%d|%02d',
                self::SEVERITY_ORDER[$flag['severity']] ?? 9,
                self::FLAG_ORDER[$flag['code']] ?? 99,
            ))
            ->values()
            ->all();

        $state = match (true) {
            collect($ordered)->contains(fn (array $flag): bool => $flag['severity'] === 'critical') => 'at_risk',
            collect($ordered)->contains(fn (array $flag): bool => $flag['severity'] === 'warning') => 'attention',
            default => 'healthy',
        };

        $first = $ordered[0] ?? null;

        return [
            'state' => $state,
            'flags' => $ordered,
            'suggested_action' => $first === null ? null : [
                'code' => $first['code'],
                'label' => $first['action']['label'],
                'url' => $first['action']['url'],
            ],
            'can_view_financial_detail' => $canViewFinancialDetail,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $flags
     */
    private function addHandoffFlag(Company $company, array &$flags): void
    {
        if ($company->handoff_status !== HandoffStatus::Failed) {
            return;
        }

        $flags[] = $this->flag(
            code: 'handoff_failed',
            severity: 'critical',
            title: 'Service handoff failed',
            reason: $company->handoff_error
                ? sprintf('Service did not accept the signed engagement: %s', $company->handoff_error)
                : 'Service did not accept the signed engagement.',
            source: 'Service handoff',
            action: [
                'label' => 'Retry handoff',
                'url' => sprintf('/accounts/%s?tab=delivery', $company->id),
            ],
        );
    }

    /**
     * @param  array<int, array<string, mixed>>  $flags
     */
    private function addBillingFlag(Company $company, array &$flags, bool $canViewFinancialDetail): void
    {
        $summary = $this->billing->summary($company);

        if ($summary->overdueInvoices === 0 || $summary->overdue <= 0) {
            return;
        }

        $flags[] = $this->flag(
            code: 'ar_overdue',
            severity: 'critical',
            title: 'Overdue AR',
            reason: $canViewFinancialDetail
                ? sprintf(
                    'Finance reports %s %s overdue across %d invoice%s.',
                    $summary->currency,
                    number_format($summary->overdue, 2),
                    $summary->overdueInvoices,
                    $summary->overdueInvoices === 1 ? '' : 's',
                )
                : 'Finance reports an overdue balance. Follow up with your manager.',
            source: 'Finance (mocked)',
            action: [
                'label' => $canViewFinancialDetail ? 'Finance follow-up' : 'Open account',
                'url' => $canViewFinancialDetail
                    ? sprintf('/accounts/%s?tab=commercial&section=billing', $company->id)
                    : sprintf('/accounts/%s?tab=commercial', $company->id),
            ],
        );
    }

    /**
     * @param  array<int, array<string, mixed>>  $flags
     */
    private function addSatisfactionFlag(Company $company, array &$flags): void
    {
        $response = SurveyResponse::query()
            ->where('company_id', $company->id)
            ->unresolvedAttention()
            ->latest('submitted_at')
            ->first(['id', 'submitted_at', 'csat', 'nps']);

        if ($response === null) {
            return;
        }

        $flags[] = $this->flag(
            code: 'satisfaction_attention',
            severity: 'critical',
            title: 'Satisfaction needs a call-back',
            reason: sprintf(
                'A client survey answer needs attention (%s).',
                $response->scoreLine(),
            ),
            source: 'Client satisfaction',
            action: [
                'label' => 'Open response',
                'url' => sprintf('/satisfaction/responses/%s', $response->id),
            ],
        );
    }

    /**
     * @param  array<int, array<string, mixed>>  $flags
     */
    private function addContractFlags(Company $company, array &$flags): void
    {
        $signed = $company->contracts()
            ->where('status', ContractStatus::Signed->value)
            ->orderBy('end_date')
            ->get(['id', 'reference', 'end_date', 'renewal_of_contract_id']);

        if ($signed->isEmpty()) {
            return;
        }

        $today = now()->startOfDay();
        $soon = $today->copy()->addDays((int) config('crm.contracts.expiring_soon_days', 30));
        $renewed = $signed->pluck('renewal_of_contract_id')->filter()->all();

        $expiring = $signed->first(
            fn (Contract $contract): bool => $contract->end_date !== null
                && $contract->end_date->greaterThanOrEqualTo($today)
                && $contract->end_date->lessThanOrEqualTo($soon)
                && ! in_array($contract->id, $renewed, true),
        );

        if ($expiring !== null) {
            $flags[] = $this->flag(
                code: 'contract_expiring',
                severity: 'warning',
                title: 'Contract expiring',
                reason: sprintf(
                    'Contract %s ends on %s and is inside the renewal window.',
                    $expiring->reference,
                    $expiring->end_date->format('d M Y'),
                ),
                source: 'Contracts',
                action: [
                    'label' => 'Renew contract',
                    'url' => sprintf('/contracts/%s', $expiring->id),
                ],
            );
        }

        $overdue = $signed->first(
            fn (Contract $contract): bool => $contract->end_date !== null
                && $contract->end_date->lessThan($today)
                && ! in_array($contract->id, $renewed, true),
        );

        if ($overdue !== null) {
            $flags[] = $this->flag(
                code: 'renewal_overdue',
                severity: 'warning',
                title: 'Renewal overdue',
                reason: sprintf(
                    'Contract %s ended on %s without a signed renewal.',
                    $overdue->reference,
                    $overdue->end_date->format('d M Y'),
                ),
                source: 'Contracts',
                action: [
                    'label' => 'Renew or close',
                    'url' => sprintf('/contracts/%s', $overdue->id),
                ],
            );
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $flags
     */
    private function addDeliveryFlags(Company $company, array &$flags): void
    {
        $jobOrders = $this->recruitment->jobOrders($company);
        $deployments = array_map(
            fn (DeploymentSummary $deployment): DeploymentSummary => $deployment->withTimesheet(
                $this->timekeeping->summary($company, $deployment),
            ),
            $this->deployment->deployments($company),
        );

        $stalledOrder = collect($jobOrders)->first(
            fn (JobOrderSummary $order): bool => in_array($order->status, ['open', 'partially_filled'], true),
        );

        if ($stalledOrder !== null) {
            $flags[] = $this->flag(
                code: 'job_order_stalled',
                severity: 'warning',
                title: 'Job order still open',
                reason: sprintf(
                    'Job order %s is %s (%d of %d filled).',
                    $stalledOrder->reference,
                    str_replace('_', ' ', $stalledOrder->status),
                    $stalledOrder->filled,
                    $stalledOrder->requested,
                ),
                source: 'Recruitment (mocked)',
                action: [
                    'label' => 'Chase recruitment',
                    'url' => sprintf('/accounts/%s?tab=delivery', $company->id),
                ],
            );
        }

        $underfilled = collect($deployments)->first(
            fn (DeploymentSummary $deployment): bool => $deployment->status !== 'ended'
                && $deployment->activeHeadcount < $deployment->requestedHeadcount,
        );

        if ($underfilled !== null) {
            $flags[] = $this->flag(
                code: 'deployment_underfilled',
                severity: 'warning',
                title: 'Deployment underfilled',
                reason: sprintf(
                    'Deployment %s is %d%% filled (%d of %d people).',
                    $underfilled->reference,
                    $underfilled->fillRate,
                    $underfilled->activeHeadcount,
                    $underfilled->requestedHeadcount,
                ),
                source: 'Deployment (mocked)',
                action: [
                    'label' => 'Review delivery',
                    'url' => sprintf('/accounts/%s?tab=delivery', $company->id),
                ],
            );
        }

        $pending = collect($deployments)->first(
            fn (DeploymentSummary $deployment): bool => DeliverySnapshot::needsCurrentTimesheet(
                $deployment->timesheet,
            ),
        );

        if ($pending !== null) {
            $flags[] = $this->flag(
                code: 'timesheets_pending',
                severity: 'warning',
                title: 'Deployment without current timesheet',
                reason: sprintf(
                    'Deployment %s has %s hours awaiting a decision.',
                    $pending->reference,
                    rtrim(rtrim(number_format($pending->timesheet?->pendingHours ?? 0, 2), '0'), '.'),
                ),
                source: 'Timekeeping (mocked)',
                action: [
                    'label' => 'Confirm hours',
                    'url' => sprintf('/accounts/%s?tab=delivery', $company->id),
                ],
            );
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $flags
     */
    private function addEngagementFlag(Company $company, array &$flags): void
    {
        if ($company->status === CompanyStatus::Churned) {
            return;
        }

        $last = $company->getAttribute('last_activity_at')
            ?? AccountBookMetrics::lastActivityFor($company);
        $since = $last !== null
            ? Carbon::parse($last)
            : ($company->client_since?->startOfDay() ?? $company->created_at);

        if ($since === null) {
            return;
        }

        $quietDays = (int) config('crm.follow_ups.check_in_days', 45);

        if ($since->diffInDays(now()) <= $quietDays) {
            return;
        }

        $flags[] = $this->flag(
            code: 'engagement_quiet',
            severity: 'info',
            title: 'Engagement quiet',
            reason: sprintf(
                'No activity or communication for %d days.',
                (int) $since->diffInDays(now()),
            ),
            source: 'Activity & communication history',
            action: [
                'label' => 'Log a check-in',
                'url' => sprintf('/accounts/%s?tab=engagement', $company->id),
            ],
        );
    }

    /**
     * @param  array{label: string, url: string}  $action
     * @return array<string, mixed>
     */
    private function flag(
        string $code,
        string $severity,
        string $title,
        string $reason,
        string $source,
        array $action,
    ): array {
        return [
            'code' => $code,
            'severity' => $severity,
            'title' => $title,
            'reason' => $reason,
            'source' => $source,
            'action' => $action,
        ];
    }
}
