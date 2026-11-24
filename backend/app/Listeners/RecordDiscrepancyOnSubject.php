<?php

namespace App\Listeners;

use App\Enums\ActivityStatus;
use App\Enums\ActivityType;
use App\Events\EmployeeDiscrepancyRaised;

/**
 * The flag is history on the record it is about (module 09 slice T4, ADR-0033 §5).
 *
 * Only the commercial categories can carry a record reference, so only those reach a timeline:
 * `missing_deal` lands on its opportunity and `commission_calculation` on its contract. Attendance
 * and leave-balance flags live only in Core 2 and have no CRM subject — no entry is fabricated.
 */
class RecordDiscrepancyOnSubject
{
    public function handle(EmployeeDiscrepancyRaised $event): void
    {
        $discrepancy = $event->discrepancy;
        $discrepancy->loadMissing(['subjectable', 'employee']);

        $subject = $discrepancy->subjectable;

        // Attendance and leave-balance flags name no CRM record (ADR-0033 §5), so nothing is
        // written on a timeline that does not exist.
        if ($subject === null || ! method_exists($subject, 'activities')) {
            return;
        }

        $subject->activities()->create([
            'type' => ActivityType::System,
            'subject' => sprintf(
                '%s discrepancy raised — %s',
                $discrepancy->category->label(),
                $discrepancy->employee?->name ?? 'an employee',
            ),
            'body' => $discrepancy->explanation,
            'status' => ActivityStatus::Done,
            'owner_id' => $discrepancy->raised_by ?? $discrepancy->employee_id,
            'occurred_at' => $discrepancy->created_at ?? now(),
        ]);
    }
}
