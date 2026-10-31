<?php

namespace App\Integrations;

use App\Enums\BillingUnit;
use App\Enums\CompanyStatus;
use App\Enums\ContractStatus;
use App\Enums\HandoffStatus;
use App\Models\Company;
use App\Models\Contract;

/**
 * Mocked Recruitment department (spec 16 §Read-only external feeds, ADR-0025).
 *
 * A job order is derived from the latest signed (or terminated) contract's rate cards:
 * an active, accepted handoff is filled, a failed handoff is still open, and a churned
 * account's demand is closed. The values are deterministic — no faker, no randomness.
 */
final class MockRecruitmentFeed implements RecruitmentFeed
{
    public function jobOrders(Company $company): array
    {
        $contract = $this->contract($company);

        if ($contract === null) {
            return [];
        }

        $closed = $contract->status === ContractStatus::Terminated
            || $company->status === CompanyStatus::Churned;
        $accepted = $company->handoff_status === HandoffStatus::Sent;
        $failed = $company->handoff_status === HandoffStatus::Failed;

        $orders = [];

        foreach ($contract->rateCards->values() as $index => $card) {
            // A placement fee is a one-time charge, not a person to deploy.
            if ($card->billing_unit === BillingUnit::Placement) {
                continue;
            }

            $requested = max(1, (int) $card->headcount);
            $filled = $closed || ! $accepted ? 0 : $requested;

            $orders[] = new JobOrderSummary(
                reference: $this->reference($contract, $index),
                role: $card->role_title,
                requested: $requested,
                filled: $filled,
                status: match (true) {
                    $closed => 'closed',
                    $filled === $requested => 'filled',
                    $filled > 0 => 'partially_filled',
                    default => 'open',
                },
                priority: match (true) {
                    $failed => 'high',
                    $closed => 'low',
                    default => 'medium',
                },
                targetStartDate: $this->targetStartDate($contract),
                recruiter: 'Recruitment (mocked)',
                lastUpdatedAt: $this->lastUpdatedAt($company, $contract),
            );
        }

        return $orders;
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

        return sprintf('JO-%d-%04d', $year, ($seed % 9000) + $index + 1);
    }

    private function targetStartDate(Contract $contract): ?string
    {
        $start = $contract->start_date;

        return $start?->toDateString();
    }

    private function lastUpdatedAt(Company $company, Contract $contract): ?string
    {
        return ($contract->updated_at ?? $company->updated_at)?->toIso8601String();
    }
}
