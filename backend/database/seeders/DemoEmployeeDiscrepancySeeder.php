<?php

namespace Database\Seeders;

use App\Enums\DiscrepancyCategory;
use App\Enums\DiscrepancyStatus;
use App\Models\EmployeeDiscrepancy;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * One disputed figure per review state (module 09 slice T4, spec 21 §T4, ADR-0033).
 *
 * Sam's attendance flag is open, so the amber **Under Review** pill and the manager's Resolve panel
 * demo without setup; Liza's commission flag is already settled, so the resolved side of the history
 * shows too. Deterministic, and rerunnable: a reseed finds the rows rather than duplicating them.
 */
class DemoEmployeeDiscrepancySeeder extends Seeder
{
    public function run(): void
    {
        $sam = User::query()->where('email', 'salesrep@primepower.test')->first();
        $liza = User::query()->where('email', 'salesrep2@primepower.test')->first();
        $maria = User::query()->where('email', 'manager@primepower.test')->first();

        if ($sam === null || $liza === null || $maria === null) {
            return;
        }

        EmployeeDiscrepancy::query()->firstOrCreate(
            ['employee_id' => $sam->id, 'category' => DiscrepancyCategory::Attendance->value],
            [
                'raised_by' => $sam->id,
                'explanation' => 'Marked absent on 24 Oct although HR slip #1049 approved the day; the biometric scanner at the client site was down all morning.',
                'status' => DiscrepancyStatus::Open,
            ],
        );

        EmployeeDiscrepancy::query()->firstOrCreate(
            ['employee_id' => $liza->id, 'category' => DiscrepancyCategory::CommissionCalculation->value],
            [
                'raised_by' => $liza->id,
                'explanation' => 'The BPO Solutions PH renewal closed in the last quarter but my commission statement shows only the baseline tier.',
                'status' => DiscrepancyStatus::Resolved,
                'resolved_at' => now()->subDays(3),
                'resolved_by' => $maria->id,
                'resolution_note' => 'Confirmed with Finance: the accelerator was applied in the following pay run.',
            ],
        );
    }
}
