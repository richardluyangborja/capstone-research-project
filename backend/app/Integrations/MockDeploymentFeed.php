<?php

namespace App\Integrations;

use App\Enums\BillingUnit;
use App\Enums\CompanyStatus;
use App\Enums\ContractStatus;
use App\Enums\HandoffStatus;
use App\Models\Company;
use App\Models\Contract;
use App\Models\ContractRateCard;

/**
 * Mocked Deployment operations (spec 16 §Read-only external feeds, ADR-0025).
 *
 * An accepted handoff gives active deployments; a failed handoff leaves the crew pending;
 * a churned/terminated engagement's deployments are ended. The mock derives every value
 * from the contract's rate cards and avoids worker-level rows entirely.
 */
final class MockDeploymentFeed implements DeploymentFeed
{
    public function deployments(Company $company): array
    {
        $contract = $this->contract($company);

        if ($contract === null) {
            return [];
        }

        $ended = $contract->status === ContractStatus::Terminated
            || $company->status === CompanyStatus::Churned;
        $active = ! $ended && $company->handoff_status === HandoffStatus::Sent;

        $deployments = [];

        foreach ($contract->rateCards->values() as $index => $card) {
            if ($card->billing_unit === BillingUnit::Placement) {
                continue;
            }

            $requested = max(1, (int) $card->headcount);
            $activeHeadcount = $active ? $requested : 0;

            $deployments[] = new DeploymentSummary(
                reference: $this->reference($contract, $index),
                contractReference: $contract->reference,
                role: $card->role_title,
                site: $card->site?->label ?? $this->fallbackSite($company),
                status: match (true) {
                    $ended => 'ended',
                    $active => 'active',
                    default => 'pending',
                },
                activeHeadcount: $activeHeadcount,
                requestedHeadcount: $requested,
                startDate: $contract->start_date?->toDateString(),
                endDate: $contract->end_date?->toDateString(),
                fillRate: (int) round(($activeHeadcount / $requested) * 100),
                operationsOwner: 'Deployment operations (mocked)',
                lastUpdatedAt: ($contract->updated_at ?? $company->updated_at)?->toIso8601String(),
                standardHoursPerHead: $this->standardHoursPerHead($card),
                billingUnit: $card->billing_unit->value,
            );
        }

        return $deployments;
    }

    private function contract(Company $company): ?Contract
    {
        return $company->contracts()
            ->with(['rateCards' => fn ($query) => $query->orderBy('position')->with('site')])
            ->whereIn('status', [ContractStatus::Signed->value, ContractStatus::Terminated->value])
            ->orderByDesc('start_date')
            ->orderByDesc('created_at')
            ->first();
    }

    private function reference(Contract $contract, int $index): string
    {
        $seed = abs(crc32($contract->reference));
        $year = $contract->start_date?->year ?? now()->year;

        return sprintf('DEP-%d-%04d', $year, ($seed % 9000) + $index + 1);
    }

    private function fallbackSite(Company $company): string
    {
        $primary = $company->sites()->where('is_primary', true)->value('label');

        return $primary ?: $company->name;
    }

    private function standardHoursPerHead(ContractRateCard $card): float
    {
        if ($card->billing_unit === BillingUnit::Hourly) {
            return (float) ($card->units_per_period ?? 176);
        }

        // Monthly and placement lines are not measured in billable hours; the mock uses a
        // normal full-time month so the timekeeping read still has a sensible period figure.
        return 176.0;
    }
}
